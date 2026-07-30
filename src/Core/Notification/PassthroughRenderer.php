<?php

declare(strict_types=1);

namespace Whity\Core\Notification;

/**
 * The default {@see NotificationRenderer}: it renders the caller-supplied
 * subject/body verbatim, substituting `{{key}}` (or `{{ key }}`) tokens with
 * scalar values from the notification's `data` bag. An unknown token is left
 * untouched (so a typo is visible, not silently blanked).
 *
 * This is the zero-config baseline so the dispatcher works before the full
 * per-type/channel/locale templating engine (a later task) is wired in — that
 * engine implements the same interface and replaces this one.
 */
final class PassthroughRenderer implements NotificationRenderer
{
    /**
     * @param array{subject?: string, body?: string, bodyHtml?: string|null, data?: array<string, mixed>} $context
     */
    public function render(string $type, string $channel, ?string $locale, array $context): RenderedNotification
    {
        $data = $context['data'] ?? [];
        $bodyHtml = $context['bodyHtml'] ?? null;

        return new RenderedNotification(
            self::interpolate((string) ($context['subject'] ?? ''), $data),
            self::interpolate((string) ($context['body'] ?? ''), $data),
            $bodyHtml !== null ? self::interpolate((string) $bodyHtml, $data) : null,
        );
    }

    /**
     * Replace `{{ key }}` tokens with scalar values from $data; unknown tokens
     * are left verbatim.
     *
     * @param array<string, mixed> $data
     */
    private static function interpolate(string $template, array $data): string
    {
        if ($template === '' || !str_contains($template, '{{')) {
            return $template;
        }

        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            static function (array $m) use ($data): string {
                $value = $data[$m[1]] ?? null;

                return is_scalar($value) ? (string) $value : $m[0];
            },
            $template
        );
    }
}
