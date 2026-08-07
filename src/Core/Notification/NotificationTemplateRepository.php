<?php

declare(strict_types=1);

namespace Whity\Core\Notification;

use PDO;

/**
 * Data-access layer for `notification_templates` (WC-notifications): resolve the
 * best template for a (tenant, type, channel, locale), plus CRUD for a tenant's
 * own overrides. `tenant_id` 0 is the global default core set; > 0 is a tenant
 * override. Every query binds tenant_id in the SQL literal (guard-visible); a
 * caller reads its own overrides + the global 0 set, and writes only its own.
 */
final class NotificationTemplateRepository
{
    /** The global default core set is owned by the system tenant (id 0). */
    public const GLOBAL_TENANT = 0;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Resolve the best-matching template for a (tenant, type, channel, locale),
     * or null when none exists (the renderer then falls back to inline content).
     * Precedence: a tenant override beats the global default; an exact locale
     * beats the default ('') locale.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(int $tenantId, string $type, string $channel, ?string $locale): ?array
    {
        $locale = (string) $locale;
        // The caller's own tenant rows + the global (0) set, in the matching or
        // default locale. tenant_id is in the SQL literal so the guard sees it.
        $stmt = $this->pdo->prepare(
            "SELECT * FROM notification_templates
              WHERE type = :type AND channel = :channel
                AND (tenant_id = :tenant_id OR tenant_id = 0)
                AND (locale = :locale OR locale = '')"
        );
        $stmt->execute([':type' => $type, ':channel' => $channel, ':tenant_id' => $tenantId, ':locale' => $locale]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }

        // $rows is non-empty (guarded above), so the first iteration always sets
        // $best; the highest score (tenant override + exact locale) wins.
        $best = $rows[0];
        $bestScore = -1;
        foreach ($rows as $row) {
            $rowTenant = (int) $row['tenant_id'];
            $rowLocale = (string) $row['locale'];
            $score = ($rowTenant !== self::GLOBAL_TENANT && $rowTenant === $tenantId ? 2 : 0)
                + ($rowLocale !== '' && $rowLocale === $locale ? 1 : 0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }

        return self::map($best);
    }

    /**
     * Read one exact template row (no fallback) scoped to a tenant.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $tenantId, string $type, string $channel, string $locale): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM notification_templates
              WHERE tenant_id = :tenant_id AND type = :type AND channel = :channel AND locale = :locale'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':type' => $type, ':channel' => $channel, ':locale' => $locale]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::map($row);
    }

    /**
     * List a tenant's own template overrides (not the global set).
     *
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM notification_templates WHERE tenant_id = :tenant_id ORDER BY type ASC, channel ASC, locale ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_map(static fn (array $r): array => self::map($r), $rows));
    }

    /**
     * Upsert a template for a tenant (idempotent via the unique index).
     *
     * @param array{subject?: string, body_text?: string, body_html?: string|null} $fields
     */
    public function upsert(int $tenantId, string $type, string $channel, string $locale, array $fields): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO notification_templates (tenant_id, type, channel, locale, subject, body_text, body_html, created_at, updated_at)
             VALUES (:tenant_id, :type, :channel, :locale, :subject, :body_text, :body_html, NOW(), NOW())
             ON CONFLICT (tenant_id, type, channel, locale)
             DO UPDATE SET subject = :subject, body_text = :body_text, body_html = :body_html, updated_at = NOW()'
        );
        $bodyHtml = $fields['body_html'] ?? null;
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':type'      => $type,
            ':channel'   => $channel,
            ':locale'    => $locale,
            ':subject'   => (string) ($fields['subject'] ?? ''),
            ':body_text' => (string) ($fields['body_text'] ?? ''),
            ':body_html' => $bodyHtml === null ? null : (string) $bodyHtml,
        ]);
    }

    /**
     * Delete a tenant's own template override. Returns whether a row was removed.
     */
    public function delete(int $tenantId, string $type, string $channel, string $locale): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM notification_templates
              WHERE tenant_id = :tenant_id AND type = :type AND channel = :channel AND locale = :locale'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':type' => $type, ':channel' => $channel, ':locale' => $locale]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function map(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'tenant_id'  => (int) $row['tenant_id'],
            'type'       => (string) $row['type'],
            'channel'    => (string) $row['channel'],
            'locale'     => (string) $row['locale'],
            'subject'    => (string) $row['subject'],
            'body_text'  => (string) $row['body_text'],
            'body_html'  => isset($row['body_html']) && $row['body_html'] !== null ? (string) $row['body_html'] : null,
        ];
    }
}
