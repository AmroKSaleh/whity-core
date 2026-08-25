<?php

declare(strict_types=1);

namespace Whity\Core\Document\RouteTemplate;

/**
 * The wire shape of a route template (#1027).
 *
 * Presentation only — it decides what a client sees and in what shape, and never
 * what a client may see. That is
 * {@see \Whity\Core\Document\DocumentVisibilityPolicy}'s job for documents and
 * the route's declared permission's job here, and a presenter that also filtered
 * would be a second, quieter access decision in a class nobody audits for one.
 *
 * WHAT IS DELIBERATELY ABSENT FROM A TEMPLATE ROW
 * -----------------------------------------------
 * A resolved-people count. A step names a rule and the number of people it
 * reaches is a live fact about the organisation, so putting one on this row would
 * mean resolving every rule of every step on every render of a list — and would
 * report a number that is already stale by the time it is drawn.
 *
 * The count belongs to the PREVIEW, one rule at a time, where somebody asked for
 * it: `POST /api/v1/user-groups/preview` (#1003) already answers exactly that
 * question with a count and a bounded sample, and the editor calls it per node.
 * A second preview built here would be a second implementation of the resolver's
 * semantics, free to drift from the first.
 *
 * STEP IDS ARE ABSENT TOO, AND THAT IS THE CONTRACT
 * -------------------------------------------------
 * A step is addressed by its `position` on the wire, in both directions. The
 * database ids exist only so the edge foreign keys can cascade, and they churn on
 * every save because {@see RouteTemplateRepository::replaceGraph()} replaces
 * rather than diffs. Publishing them would invite a client to hold one across a
 * save, and it would be wrong the first time somebody dragged a node.
 */
final class RouteTemplatePresenter
{
    /**
     * One template, without its graph — the list row and the create/update reply.
     *
     * @param array<string, mixed> $template
     * @return array<string, mixed>
     */
    public static function template(array $template): array
    {
        return [
            'id' => $template['id'],
            'name' => $template['name'],
            'description' => $template['description'],
            'step_count' => $template['step_count'] ?? 0,
            'created_by' => $template['created_by'],
            'created_at' => $template['created_at'],
            'updated_at' => $template['updated_at'],
        ];
    }

    /**
     * One template WITH its graph — what the editor opens.
     *
     * `default_quorum` rides along because the editor has to show what a node
     * with no explicit quorum will actually do, and that answer lives in the
     * tenant's settings chain rather than on the row. Sending it here means the
     * editor does not need `settings:read` — which somebody who may design a flow
     * need not hold — to render the effective rule beside a node.
     *
     * @param array<string, mixed> $template
     * @param list<array<string, mixed>> $steps
     * @param list<array{from: int, to: int, verdict: string}> $edges
     * @return array<string, mixed>
     */
    public static function graph(array $template, array $steps, array $edges, string $defaultQuorum, int $maxSteps): array
    {
        return self::template($template) + [
            'default_quorum' => $defaultQuorum,
            'max_steps' => $maxSteps,
            'steps' => array_map(self::step(...), $steps),
            'edges' => $edges,
        ];
    }

    /**
     * One step.
     *
     * `decision_quorum` stays NULL when the author did not set one, rather than
     * being resolved to the effective value here. The distinction is real and the
     * editor draws it differently: NULL means "follow the tenant setting, whatever
     * it becomes", and a resolved copy would freeze today's answer into a design
     * whose whole point is that the setting can change it.
     *
     * @param array<string, mixed> $step
     * @return array<string, mixed>
     */
    private static function step(array $step): array
    {
        return [
            'position' => $step['position'],
            'rule_kind' => $step['rule_kind'],
            'rule_config' => $step['rule_config'],
            'label' => $step['label'],
            'decision' => $step['decision'],
            'decision_quorum' => $step['decision_quorum'],
            'canvas_x' => $step['canvas_x'],
            'canvas_y' => $step['canvas_y'],
        ];
    }
}
