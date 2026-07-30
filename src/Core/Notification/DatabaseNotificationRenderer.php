<?php

declare(strict_types=1);

namespace Whity\Core\Notification;

/**
 * The DB-backed {@see NotificationRenderer} (WC-notifications): it resolves a
 * stored template for the (tenant, type, channel, locale) — a tenant override
 * winning over the global default set, an exact locale over the default locale —
 * and renders its subject/text/HTML by interpolating the notification's `data`.
 *
 * SAFE by default: values interpolated into the HTML body are HTML-escaped
 * (htmlspecialchars), so `data` can never inject markup; subject and plain-text
 * bodies are substituted verbatim (they are not HTML). Unknown `{{tokens}}` are
 * left intact.
 *
 * When NO template is stored for the (type, channel), it delegates to the
 * fallback renderer (the {@see PassthroughRenderer} by default) so the
 * caller-supplied inline subject/body still render — templating is additive.
 */
final class DatabaseNotificationRenderer implements NotificationRenderer
{
    private NotificationTemplateRepository $templates;
    private NotificationRenderer $fallback;

    public function __construct(NotificationTemplateRepository $templates, ?NotificationRenderer $fallback = null)
    {
        $this->templates = $templates;
        $this->fallback = $fallback ?? new PassthroughRenderer();
    }

    /**
     * @param array{subject?: string, body?: string, bodyHtml?: string|null, data?: array<string, mixed>} $context
     */
    public function render(int $tenantId, string $type, string $channel, ?string $locale, array $context): RenderedNotification
    {
        $template = $this->templates->resolve($tenantId, $type, $channel, $locale);
        if ($template === null) {
            return $this->fallback->render($tenantId, $type, $channel, $locale, $context);
        }

        $data = is_array($context['data'] ?? null) ? $context['data'] : [];
        $html = $template['body_html'] ?? null;

        return new RenderedNotification(
            self::interpolate((string) $template['subject'], $data, false),
            self::interpolate((string) $template['body_text'], $data, false),
            is_string($html) ? self::interpolate($html, $data, true) : null,
        );
    }

    /**
     * Replace `{{ key }}` tokens with scalar values from $data. In HTML mode the
     * value is HTML-escaped so `data` can never inject markup; unknown tokens are
     * left verbatim.
     *
     * @param array<string, mixed> $data
     */
    private static function interpolate(string $template, array $data, bool $escapeHtml): string
    {
        if ($template === '' || !str_contains($template, '{{')) {
            return $template;
        }

        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            static function (array $m) use ($data, $escapeHtml): string {
                $value = $data[$m[1]] ?? null;
                if (!is_scalar($value)) {
                    return $m[0];
                }
                $string = (string) $value;

                return $escapeHtml ? htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $string;
            },
            $template
        );
    }
}
