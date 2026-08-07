<?php

declare(strict_types=1);

namespace Whity\Core\Notification;

use PDO;

/**
 * Seeds the GLOBAL default core set of notification templates (tenant_id = 0,
 * default locale) — the operator-managed baseline every tenant inherits until it
 * registers its own override. Called from {@see \Whity\Database\Seeder}; inserts
 * are ON CONFLICT DO NOTHING so re-seeding is idempotent and never clobbers an
 * operator's edits.
 *
 * The set is intentionally small (the renderer falls back to inline content for
 * any un-templated type); it grows as core notification types are formalised.
 */
final class NotificationTemplateSeeder
{
    /**
     * @return list<array{type: string, channel: string, locale: string, subject: string, body_text: string, body_html: string}>
     */
    public static function defaults(): array
    {
        return [
            [
                'type' => 'account.welcome',
                'channel' => 'email',
                'locale' => '',
                'subject' => 'Welcome to {{app_name}}',
                'body_text' => 'Hi {{name}}, welcome to {{app_name}}. Your account is ready.',
                'body_html' => '<p>Hi {{name}}, welcome to <strong>{{app_name}}</strong>. Your account is ready.</p>',
            ],
            [
                'type' => 'password.reset',
                'channel' => 'email',
                'locale' => '',
                'subject' => 'Reset your password',
                'body_text' => 'Use this link to reset your password: {{reset_url}}',
                'body_html' => '<p>Use this link to reset your password: <a href="{{reset_url}}">{{reset_url}}</a></p>',
            ],
        ];
    }

    /**
     * Insert the default core set as global (tenant 0) templates. Returns how many
     * rows were newly inserted.
     */
    public static function seed(PDO $pdo): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO notification_templates (tenant_id, type, channel, locale, subject, body_text, body_html, created_at, updated_at)
             VALUES (0, :type, :channel, :locale, :subject, :body_text, :body_html, NOW(), NOW())
             ON CONFLICT (tenant_id, type, channel, locale) DO NOTHING'
        );

        $inserted = 0;
        foreach (self::defaults() as $t) {
            $stmt->execute([
                ':type'      => $t['type'],
                ':channel'   => $t['channel'],
                ':locale'    => $t['locale'],
                ':subject'   => $t['subject'],
                ':body_text' => $t['body_text'],
                ':body_html' => $t['body_html'],
            ]);
            $inserted += $stmt->rowCount();
        }

        return $inserted;
    }
}
