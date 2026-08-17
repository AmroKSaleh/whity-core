<?php

declare(strict_types=1);

namespace Whity\Database;

use PDO;
use Whity\Sdk\Sql\SequenceAllocator;

/**
 * Offline-host copy of production's `src/Database/SequenceCounters.php` —
 * the backing implementation for `Whity\Sdk\Sql\SequenceAllocator`, which a
 * plugin's route handler resolves via `\Whity\app()` (see e.g. Relations'
 * PersonsApiHandler). Copied rather than reinvented: the single-statement
 * `INSERT ... ON CONFLICT DO UPDATE ... RETURNING` mechanism is what makes
 * allocation atomic under concurrency on BOTH PostgreSQL and SQLite (see the
 * original's docblock for the full argument), and that guarantee is exactly
 * what a hand-simplified rewrite would risk losing.
 *
 * Not `implements HostWiredService` here: that marker interface only matters
 * to production's fuller container, whose auto-instantiation fallback this
 * offline host's `\Whity\app()` doesn't have in the first place (see
 * helpers.php's own docblock) — nothing to guard, and the interface isn't
 * even vendored into php-host/src.
 *
 * Backing table: `sequence_counters`, created in
 * `PluginHost\MigrationRunner::bootstrapHostSkeleton()`.
 */
final class SequenceCounters implements SequenceAllocator
{
    private const SYSTEM_TENANT_ID = 0;

    private const MAX_NAME_LENGTH = 128;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function next(int $tenantId, string $name, int $step = 1): int
    {
        return $this->allocate($tenantId, $name, $step);
    }

    /**
     * @return array{first: int, last: int}
     */
    public function nextBlock(int $tenantId, string $name, int $count): array
    {
        if ($count < 1) {
            throw new \InvalidArgumentException(
                "Cannot allocate a block of {$count} from counter '{$name}': count must be positive."
            );
        }

        $last = $this->allocate($tenantId, $name, $count);

        return ['first' => $last - $count + 1, 'last' => $last];
    }

    public function peek(int $tenantId, string $name): int
    {
        $this->assertName($name);

        $statement = $this->pdo->prepare(
            'SELECT value FROM sequence_counters WHERE tenant_id = :tenant AND name = :name'
        );
        $statement->execute([':tenant' => $tenantId, ':name' => $name]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? 0 : (int) $value;
    }

    public function nextPlatformWide(string $name, int $step = 1): int
    {
        return $this->allocate(self::SYSTEM_TENANT_ID, $name, $step);
    }

    private function allocate(int $tenantId, string $name, int $step): int
    {
        $this->assertName($name);

        if ($step < 1) {
            throw new \InvalidArgumentException(
                "Cannot advance counter '{$name}' by {$step}: a counter that can go "
                . 'backwards would re-issue numbers it has already handed out.'
            );
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO sequence_counters (tenant_id, name, value)
             VALUES (:tenant, :name, :seed)
             ON CONFLICT (tenant_id, name)
             DO UPDATE SET value = sequence_counters.value + :step,
                           updated_at = CURRENT_TIMESTAMP
             RETURNING value'
        );
        $statement->bindValue(':tenant', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(':name', $name, PDO::PARAM_STR);
        $statement->bindValue(':seed', $step, PDO::PARAM_INT);
        $statement->bindValue(':step', $step, PDO::PARAM_INT);
        $statement->execute();

        $value = $statement->fetchColumn();

        if ($value === false || $value === null) {
            throw new \RuntimeException(
                "Counter '{$name}' allocated no value. Refusing to guess one — a guessed "
                . 'sequence number is a duplicate waiting to happen.'
            );
        }

        return (int) $value;
    }

    private function assertName(string $name): void
    {
        if (preg_match('/^[a-z][a-z0-9_:-]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid counter name "%s": expected lowercase letters, digits, '
                . 'underscore, colon or hyphen, starting with a letter.',
                $name
            ));
        }

        if (strlen($name) > self::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid counter name "%s": %d characters exceeds the %d-character column.',
                $name,
                strlen($name),
                self::MAX_NAME_LENGTH
            ));
        }
    }
}
