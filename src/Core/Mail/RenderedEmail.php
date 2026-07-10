<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

/**
 * The output of {@see EmailLayout::render()} (WC-email): the two alternative
 * bodies for one message. Pass straight to {@see Mailer::send()} as
 * `send($to, $subject, $rendered->text, $rendered->html)`.
 */
final class RenderedEmail
{
    public function __construct(
        public readonly string $text,
        public readonly string $html,
    ) {
    }
}
