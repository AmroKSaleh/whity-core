<?php

declare(strict_types=1);

namespace Whity\Core\Document\Organizer;

/**
 * The wire shape of the organizer's rail (#978).
 *
 * WHY A VIEW CARRIES ITS OWN AVAILABILITY INSTEAD OF BEING OMITTED
 * ----------------------------------------------------------------
 * Two different absences reach a client and they must not look the same:
 *
 *  - A view whose FACT SOURCE does not exist never appears in this response at
 *    all. There is nothing to say about "awaiting me" except that this
 *    installation cannot compute it, and a folder that appears and returns
 *    nothing states something false about the reader's workload.
 *
 *  - A view THIS CALLER cannot anchor appears with `available: false` and a
 *    reason. That is #951's rule verbatim — a control the viewer cannot use is
 *    disabled with the cause on it, never hidden, because a hidden control
 *    makes "you have no unit", "the feature was removed" and "it is broken"
 *    pixel-identical.
 *
 * `unavailable_substrates` is the third thing a reader may need: what this
 * installation does not record, and what would supply it. It is a DIAGNOSTIC
 * and deliberately a separate field from `views` — an operator asking "why is
 * there no inbox here" gets an answer, and no client can mistake the list for
 * folders because nothing in it has a key to open.
 */
final class DocumentViewPresenter
{
    private function __construct()
    {
    }

    /**
     * @param DocumentViewResolution|null $resolution The caller-level resolution, or null for a
     *        view whose required parameters make it a TEMPLATE the client instantiates (one
     *        entry per collection, say) rather than a folder that can be resolved on its own.
     * @return array<string, mixed>
     */
    public static function view(DocumentView $view, ?DocumentViewResolution $resolution): array
    {
        return [
            'key' => $view->key,
            // English, for a client that has no translation for a view it has
            // never heard of — which is every client the first time anything
            // registers one. See DocumentView for why the label is server-side.
            'label' => $view->label,
            'description' => $view->description,
            'group' => $view->group,
            'parameters' => $view->parameters,
            'requires' => $view->requires,
            'available' => $resolution === null || $resolution->isAvailable(),
            'unavailable_reason' => $resolution?->unavailableReason,
        ];
    }

    /**
     * A fact source this installation does not have, with what would supply it.
     *
     * @return array<string, mixed>
     */
    public static function substrate(DocumentSubstrate $substrate): array
    {
        return [
            'key' => $substrate->key,
            'description' => $substrate->description,
            'provenance' => $substrate->provenance,
        ];
    }
}
