<?php

declare(strict_types=1);

namespace Whity\Core\Document\RouteTemplate;

use InvalidArgumentException;
use Whity\Core\Document\Routing\RouteQuorum;
use Whity\Core\Document\Routing\RouteSatisfaction;
use Whity\Core\Document\Routing\RouteVerdict;
use Whity\Core\Document\Routing\RoutingRuleRegistry;

/**
 * Validates a whole template graph before a single row is written (#1027).
 *
 * This is where the editor's canvas becomes a design the engine can run, and it
 * is deliberately the ONE place that decides. The repository writes what it is
 * given inside a transaction and cannot half-apply; the API handler turns a
 * refusal into a 422 and does not second-guess it. Splitting the rules across
 * those two would put half the answer on the side of the wire that cannot see
 * the other half.
 *
 * THE RULE THAT MATTERS MOST: A NODE IS A TYPE
 * --------------------------------------------
 * Every step names a `rule_kind` from {@see RoutingRuleRegistry} and a config the
 * resolver itself validates. There is no branch here that accepts a list of
 * people, because there is no column to put one in — see
 * {@see RouteTemplateRepository}. What this class adds is that a kind nothing
 * registered is refused AT AUTHORING TIME rather than at instantiation: a
 * template naming `acme:committee` after the plugin was uninstalled is a design
 * that would fail months later, on somebody else's document.
 *
 * WHY AN EDGE MAY ONLY LEAVE A DECISION STEP
 * -------------------------------------------
 * A non-decision step produces no verdict. An `approved` edge drawn from one can
 * therefore never fire — it would sit on the canvas looking like part of the
 * design, and route nothing, forever. That is the stored-intention failure
 * migration 112 names for configured effects, reached through the edge table
 * instead, so it is refused rather than saved.
 *
 * The converse is NOT an error: a decision step with no edges at all is a
 * perfectly ordinary gate. An approval falls through to the next authoring
 * ordinal and a rejection ends the chain, which is exactly #1014's stated
 * behaviour and what makes a linear approval route need no edges whatsoever.
 *
 * A STAGE MAY BE SATISFIED BY DELIVERY, AND THEN IT MAY NOT BE A GATE (#1054)
 * ---------------------------------------------------------------------------
 * `satisfied_by` says whether the people at a stage are ASKED for anything or
 * simply TOLD. It is refused in combination with `decision` here for the reason
 * the engine refuses it — a gate needs somebody holding the item to answer it,
 * and a delivery stage closes every item the moment it is sent — and refusing it
 * at authoring time is what stops the canvas from being able to draw an approval
 * nobody can ever give.
 *
 * The vocabulary is read from {@see RouteSatisfaction} DIRECTLY — as verdicts
 * and quorums now are from {@see RouteVerdict} and {@see RouteQuorum}. There was
 * briefly a `RouteTemplateContract` mirroring those two, because #1014 and #1027
 * were built on separate branches and an authoring surface cannot reference
 * classes that are not on its branch. It said in its own docblock that the
 * mirrors should go once both merged, and #1033 is that removal.
 *
 * Nothing replaces it, which is the point: a mirror is a second place for the
 * vocabulary to be wrong, and an authoring surface that can express a verdict
 * the engine cannot route on saves cleanly, renders cleanly, and does something
 * else when it finally runs.
 *
 * WHY CYCLES ARE ALLOWED
 * ----------------------
 * A reject edge pointing BACK to an earlier step — "send it to the author to
 * fix" — is the single most common real approval design, and it is a cycle. A
 * validator that refused cycles would refuse the feature's motivating example.
 *
 * The termination question that raises belongs to the engine and not to
 * authoring: whether a document can loop forever is a property of how the
 * recipients act, not of the drawing, and #1014's `DocumentRouter` is where a
 * traversal bound belongs if one is wanted. Refusing the design here would trade
 * a real capability for a guarantee this layer cannot make anyway.
 *
 * STATELESS. Holds no request state and is safe to call from a FrankenPHP
 * worker.
 */
final class RouteTemplateGraph
{
    public function __construct(private readonly RoutingRuleRegistry $rules)
    {
    }

    /**
     * Validate a decoded graph and return it in the shape the repository writes.
     *
     * Every refusal is a {@see RouteTemplateRejectedException} whose text names
     * the position it is about, so the editor can put the message on the node
     * rather than at the top of the screen.
     *
     * @param mixed $steps    The raw `steps` value from the request body.
     * @param mixed $edges    The raw `edges` value from the request body.
     * @param int   $maxSteps The tenant's effective `documents.routing_max_steps`.
     * @return array{steps: list<array{position: int, rule_kind: string, rule_config: array<string, mixed>, label: ?string, decision: bool, decision_quorum: ?string, satisfied_by: string, canvas_x: int, canvas_y: int}>, edges: list<array{from: int, to: int, verdict: string}>}
     */
    public function validate(mixed $steps, mixed $edges, int $maxSteps): array
    {
        $validSteps = $this->validateSteps($steps, $maxSteps);

        /** @var array<int, bool> $decisionByPosition */
        $decisionByPosition = [];
        foreach ($validSteps as $step) {
            $decisionByPosition[$step['position']] = $step['decision'];
        }

        return [
            'steps' => $validSteps,
            'edges' => $this->validateEdges($edges, $decisionByPosition),
        ];
    }

    /**
     * @return list<array{position: int, rule_kind: string, rule_config: array<string, mixed>, label: ?string, decision: bool, decision_quorum: ?string, satisfied_by: string, canvas_x: int, canvas_y: int}>
     */
    private function validateSteps(mixed $steps, int $maxSteps): array
    {
        if (!is_array($steps)) {
            throw RouteTemplateRejectedException::because("'steps' must be an array of template steps.");
        }

        // A JSON object decodes to a PHP array too, and one sent where a list was
        // meant would silently iterate its values in key order. Refusing it is
        // the same check `DocumentRoutingApiHandler` makes on a route's steps.
        if ($steps !== [] && !array_is_list($steps)) {
            throw RouteTemplateRejectedException::because("'steps' must be a JSON array, not an object.");
        }

        if (count($steps) > $maxSteps) {
            throw RouteTemplateRejectedException::because(
                'A template may declare at most ' . $maxSteps . ' steps, and this one declares '
                . count($steps) . '. Simplify the flow, or raise documents.routing_max_steps.'
            );
        }

        $out = [];
        /** @var array<int, true> $seenPositions */
        $seenPositions = [];

        foreach ($steps as $index => $step) {
            // The ordinal in the message is 1-based and describes the step's
            // place in the payload, which is what an author counting nodes sees.
            $nth = (int) $index + 1;

            // A JSON object decodes to a PHP associative array; a JSON array
            // decodes to a list. A list here means the caller sent `[...]` where
            // `{...}` was meant, and every field lookup below would silently miss.
            if (!is_array($step) || ($step !== [] && array_is_list($step))) {
                throw RouteTemplateRejectedException::because("Step {$nth}: each step must be an object.");
            }

            $position = $step['position'] ?? null;
            if (!is_int($position) || $position < 1) {
                throw RouteTemplateRejectedException::because(
                    "Step {$nth}: 'position' must be a whole number of 1 or more."
                );
            }
            if (isset($seenPositions[$position])) {
                throw RouteTemplateRejectedException::because(
                    "Two steps share position {$position}. A position is a step's handle and must be unique "
                    . 'within a template.'
                );
            }
            $seenPositions[$position] = true;

            $kind = $step['rule_kind'] ?? null;
            if (!is_string($kind) || $kind === '') {
                throw RouteTemplateRejectedException::because(
                    "Step {$position}: 'rule_kind' is required and names the RULE this stage reaches — "
                    . 'never a person.'
                );
            }
            if (!$this->rules->has($kind)) {
                throw RouteTemplateRejectedException::because(
                    "Step {$position}: no routing rule '{$kind}' is registered on this instance. "
                    . 'A plugin that contributed it may be disabled.'
                );
            }

            $config = $step['rule_config'] ?? [];
            if (!is_array($config)) {
                throw RouteTemplateRejectedException::because("Step {$position}: 'rule_config' must be an object.");
            }
            if ($config !== [] && array_is_list($config)) {
                throw RouteTemplateRejectedException::because(
                    "Step {$position}: 'rule_config' must be an object, not an array."
                );
            }

            // The RESOLVER decides whether its own config is usable, and its
            // message is written for this author to read. Validating here would
            // be a second implementation of a rule's own semantics — the thing
            // the registry exists to prevent — and it would be wrong for every
            // plugin-contributed kind by construction.
            $resolver = $this->rules->get($kind);
            if ($resolver === null) {
                // Unreachable through has() above, and kept as a narrowing branch
                // rather than an assertion: get() is nullable, and a cast that
                // pretended otherwise would be a lie PHPStan would have to be
                // told to ignore.
                throw RouteTemplateRejectedException::because(
                    "Step {$position}: no routing rule '{$kind}' is registered on this instance."
                );
            }
            try {
                $resolver->validate($config);
            } catch (InvalidArgumentException $e) {
                throw RouteTemplateRejectedException::because("Step {$position}: " . $e->getMessage());
            }

            $label = $step['label'] ?? null;
            if ($label !== null && !is_string($label)) {
                throw RouteTemplateRejectedException::because("Step {$position}: 'label' must be text or absent.");
            }
            if (is_string($label) && mb_strlen($label) > 160) {
                throw RouteTemplateRejectedException::because(
                    "Step {$position}: 'label' must be 160 characters or fewer."
                );
            }

            $decision = $step['decision'] ?? false;
            if (!is_bool($decision)) {
                throw RouteTemplateRejectedException::because(
                    "Step {$position}: 'decision' must be true or false — it says whether this stage is a GATE."
                );
            }

            // #1054. WHETHER ANYBODY AT THIS STAGE IS ASKED FOR ANYTHING.
            $satisfiedBy = $step['satisfied_by'] ?? RouteSatisfaction::fallback();
            if (!is_string($satisfiedBy) || !RouteSatisfaction::isValid($satisfiedBy)) {
                throw RouteTemplateRejectedException::because(sprintf(
                    "Step %d: 'satisfied_by' must be one of %s — it says whether the people at this stage "
                    . 'are asked to act or simply told.',
                    $position,
                    implode(', ', RouteSatisfaction::all()),
                ));
            }
            if ($satisfiedBy === RouteSatisfaction::DELIVERY && $decision === true) {
                // The one pair that cannot mean anything, refused for the reason
                // the engine refuses it: a decision needs somebody holding the
                // item to answer it, and a delivery stage closes every item the
                // moment it is sent. Stored, it would be an approval on the
                // canvas that nobody could ever give.
                throw RouteTemplateRejectedException::because(sprintf(
                    'Step %d is marked both as a decision and as satisfied by delivery. It cannot be '
                    . 'both — a decision needs somebody holding the item to answer it, and a delivery '
                    . 'stage closes every item the moment it is sent. Drop one of the two.',
                    $position,
                ));
            }

            $quorum = $step['decision_quorum'] ?? null;
            if ($quorum !== null) {
                if (!is_string($quorum) || !RouteQuorum::isValid($quorum)) {
                    throw RouteTemplateRejectedException::because(
                        "Step {$position}: 'decision_quorum' must be one of "
                        . implode(', ', RouteQuorum::all()) . ', or absent to follow the '
                        . 'tenant setting.'
                    );
                }
                // A quorum on a step that asks for no verdict is a setting that
                // can never be consulted. It is refused rather than stored,
                // because a stored value nothing reads is indistinguishable on
                // screen from one that works.
                if ($decision === false) {
                    throw RouteTemplateRejectedException::because(
                        "Step {$position}: a 'decision_quorum' only means something on a decision step. "
                        . 'Mark the step as a decision, or drop the quorum.'
                    );
                }
            }

            $out[] = [
                'position' => $position,
                'rule_kind' => $kind,
                /** @var array<string, mixed> $config */
                'rule_config' => $config,
                'label' => is_string($label) ? $label : null,
                'decision' => $decision,
                'decision_quorum' => is_string($quorum) ? $quorum : null,
                'satisfied_by' => $satisfiedBy,
                'canvas_x' => self::coordinate($step['canvas_x'] ?? 0, $position, 'canvas_x'),
                'canvas_y' => self::coordinate($step['canvas_y'] ?? 0, $position, 'canvas_y'),
            ];
        }

        return $out;
    }

    /**
     * @param array<int, bool> $decisionByPosition
     * @return list<array{from: int, to: int, verdict: string}>
     */
    private function validateEdges(mixed $edges, array $decisionByPosition): array
    {
        if ($edges === null) {
            return [];
        }
        if (!is_array($edges)) {
            throw RouteTemplateRejectedException::because("'edges' must be an array of transitions.");
        }
        if ($edges !== [] && !array_is_list($edges)) {
            throw RouteTemplateRejectedException::because("'edges' must be a JSON array, not an object.");
        }

        $out = [];
        /** @var array<string, true> $seen */
        $seen = [];

        foreach ($edges as $index => $edge) {
            $nth = (int) $index + 1;
            if (!is_array($edge)) {
                throw RouteTemplateRejectedException::because("Edge {$nth}: each edge must be an object.");
            }

            $from = $edge['from'] ?? null;
            $to = $edge['to'] ?? null;
            $verdict = $edge['verdict'] ?? null;

            if (!is_int($from) || !is_int($to)) {
                throw RouteTemplateRejectedException::because(
                    "Edge {$nth}: 'from' and 'to' must be the POSITIONS of two steps in this template."
                );
            }
            if (!is_string($verdict) || !RouteVerdict::isValid($verdict)) {
                throw RouteTemplateRejectedException::because(
                    "Edge {$nth}: 'verdict' must be one of " . implode(', ', RouteVerdict::all())
                    . '. There is no unconditional edge — a step with no edge for a verdict falls through to '
                    . 'the next position.'
                );
            }
            if (!array_key_exists($from, $decisionByPosition)) {
                throw RouteTemplateRejectedException::because(
                    "Edge {$nth}: no step at position {$from} to lead FROM."
                );
            }
            if (!array_key_exists($to, $decisionByPosition)) {
                throw RouteTemplateRejectedException::because(
                    "Edge {$nth}: no step at position {$to} to lead TO."
                );
            }
            if ($from === $to) {
                throw RouteTemplateRejectedException::because(
                    "Edge {$nth}: a step cannot lead to itself."
                );
            }
            if ($decisionByPosition[$from] === false) {
                throw RouteTemplateRejectedException::because(
                    "Edge {$nth}: step {$from} is not a decision step, so it never produces a verdict and this "
                    . 'edge could never be taken. Mark step ' . $from . ' as a decision, or remove the edge.'
                );
            }

            $key = $from . ':' . $verdict;
            if (isset($seen[$key])) {
                throw RouteTemplateRejectedException::because(
                    "Step {$from} has two '{$verdict}' edges. A step has one destination per verdict."
                );
            }
            $seen[$key] = true;

            $out[] = ['from' => $from, 'to' => $to, 'verdict' => $verdict];
        }

        return $out;
    }

    /**
     * A canvas coordinate: a whole number, clamped to a sane canvas.
     *
     * Bounded rather than free, because these are written straight back onto a
     * canvas: a node at x = 10^9 is not visible, is not reachable by the "fit to
     * view" control, and looks to its author exactly like a node that vanished.
     * The bound is generous enough that no real arrangement meets it.
     */
    private static function coordinate(mixed $value, int $position, string $field): int
    {
        if (!is_int($value)) {
            throw RouteTemplateRejectedException::because(
                "Step {$position}: '{$field}' must be a whole number of pixels."
            );
        }
        if ($value < self::CANVAS_MIN || $value > self::CANVAS_MAX) {
            throw RouteTemplateRejectedException::because(
                "Step {$position}: '{$field}' must be between " . self::CANVAS_MIN . ' and ' . self::CANVAS_MAX . '.'
            );
        }

        return $value;
    }

    /**
     * The canvas bounds, in pixels.
     *
     * Not a tunable: this is not a policy anybody would want to configure per
     * tenant, it is the range in which a coordinate is a coordinate rather than a
     * typo or an overflow. The editor never produces a value near either end.
     */
    private const CANVAS_MIN = -100000;
    private const CANVAS_MAX = 100000;
}
