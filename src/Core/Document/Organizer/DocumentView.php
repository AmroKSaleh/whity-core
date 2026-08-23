<?php

declare(strict_types=1);

namespace Whity\Core\Document\Organizer;

use Closure;

/**
 * One folder in the document organizer (#978) — which, because no folder tree is
 * stored, is a NAMED QUERY plus a declaration of what it needs in order to be
 * askable at all.
 *
 * A view carries four things and nothing else:
 *
 *  - `key`         — how the client asks for it (`?view=raised-by-my-unit`).
 *  - `requires`    — the {@see DocumentSubstrate} keys whose facts it reads. If
 *                    any is absent from this installation the view does not
 *                    exist, is not listed, and cannot be requested. This is the
 *                    line between "empty" and "not built".
 *  - `parameters`  — what the client may (or must) supply alongside it.
 *  - a resolver    — caller → {@see DocumentViewResolution}.
 *
 * WHY THE LABEL IS SERVER-SIDE
 * ----------------------------
 * The set of views is open — #947 item 3 adds three, and a plugin may add its
 * own — so a client cannot hold a complete map of key to label. It ships English
 * here and the client translates the keys it knows, falling back to this string
 * for the ones it does not. A client-only map would render a registered view as
 * a blank chip the first time anything registered one.
 *
 * WHY THE RESOLVER IS A CLOSURE RATHER THAN AN INTERFACE
 * ------------------------------------------------------
 * An interface per view is the conventional shape and would be six near-empty
 * classes here for six one-expression resolvers. The cost of the closure is
 * that a view cannot be autowired from a container by class name — which
 * nothing in this codebase does for these, since views are registered
 * explicitly at boot ({@see CoreDocumentViews}) exactly as nav items and
 * permissions are. If a view ever needs collaborators beyond
 * {@see DocumentViewContext} the honest fix is to widen the context, not to
 * give the closure access to a container.
 *
 * Immutable — worker-safe.
 */
final class DocumentView
{
    /**
     * @param string                                              $key         Stable identifier used on the wire.
     * @param string                                              $label       English default; clients translate by key.
     * @param string                                              $description One sentence saying what is in it, shown as help text.
     * @param string                                              $group       Rail section: `derived` (a fact about the document)
     *                                                                         or `personal` (a fact about the caller).
     * @param list<string>                                        $requires    Substrate keys.
     * @param list<array{name: string, required: bool}>           $parameters  Query parameters this view reads.
     * @param Closure(DocumentViewContext): DocumentViewResolution $resolver
     * @param int                                                 $order       Rail ordering; ties break on key.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly string $group,
        public readonly array $requires,
        public readonly array $parameters,
        private readonly Closure $resolver,
        public readonly int $order = 100,
    ) {
    }

    public function resolve(DocumentViewContext $context): DocumentViewResolution
    {
        return ($this->resolver)($context);
    }

    /**
     * Parameters the client MUST supply. A view opened without one is a client
     * error (400), not an unavailable view — "you did not say which collection"
     * is a different statement from "you cannot open collections", and only the
     * first is true.
     *
     * @return list<string>
     */
    public function requiredParameters(): array
    {
        $names = [];
        foreach ($this->parameters as $parameter) {
            if ($parameter['required']) {
                $names[] = $parameter['name'];
            }
        }

        return $names;
    }
}
