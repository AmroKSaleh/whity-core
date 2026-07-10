<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

/**
 * The per-message content an {@see EmailLayout} renders into the branded shell
 * (WC-email). Structured (not raw HTML) so the layout controls escaping — a
 * caller passes plain strings and cannot inject markup.
 *
 * Covers the transactional messages: a heading, one or more body paragraphs, an
 * optional call-to-action button, an optional highlighted callout (e.g. "X
 * invited you to Y"), and an optional small-print footnote (e.g. link expiry).
 */
final class EmailContent
{
    /**
     * @param string      $heading    The message headline.
     * @param list<string> $paragraphs Body paragraphs, in order (plain text).
     * @param string|null $ctaLabel   Button label, or null for no button.
     * @param string|null $ctaUrl     Button URL (required when $ctaLabel is set).
     * @param string|null $callout    Highlighted callout line, or null.
     * @param string|null $footnote   Small-print line under the CTA, or null.
     */
    public function __construct(
        public readonly string $heading,
        public readonly array $paragraphs = [],
        public readonly ?string $ctaLabel = null,
        public readonly ?string $ctaUrl = null,
        public readonly ?string $callout = null,
        public readonly ?string $footnote = null,
    ) {
    }

    /** Whether a usable call-to-action button is present. */
    public function hasCta(): bool
    {
        return $this->ctaLabel !== null && $this->ctaLabel !== ''
            && $this->ctaUrl !== null && $this->ctaUrl !== '';
    }
}
