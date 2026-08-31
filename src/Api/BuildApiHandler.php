<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Throwable;
use Whity\Core\BuildIdentity;
use Whity\Core\CoreVersion;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Database\ConnectionException;
use Whity\Database\Database;

/**
 * `GET /api/build` — the backend's answer to "which build am I running?" (#1049).
 *
 * The deliberate mirror of the web tier's `GET /web-build` (WHIT-587), field
 * name for field name where the two mean the same thing, so an operator
 * comparing them is comparing like with like:
 *
 *     curl -s https://host/web-build | jq '{commit, core_version}'
 *     curl -s https://host/api/build | jq '{commit, core_version}'
 *
 * Two probes, one origin, no shell, no session. They disagree loudly when one
 * tier was deployed and the other was not — the failure that has now happened
 * in BOTH directions on this project: a frontend 268 commits behind an API
 * reporting the new version, and a backend found four days behind while
 * `/api/health` answered `{"status":"ok"}` throughout.
 *
 * WHY A SIBLING ENDPOINT AND NOT THREE MORE KEYS ON `/api/health`
 * ---------------------------------------------------------------
 * `/api/health` is a load-balancer liveness probe. Every deployment points
 * something at it on a few-second interval, and its docblock and CHANGELOG
 * both record the discipline that keeps it answerable while everything else is
 * down: dependency-light, computed from constants, no query beyond `SELECT 1`.
 * `pending_migration_count` — the field #1049 calls the highest-value one — is
 * a table scan of `core_schema_migrations` plus a directory listing. Putting
 * that on the liveness path would make the probe an orchestrator restarts
 * containers over do work proportional to the schema, and would tie a route
 * whose contract monitoring parses to a subsystem that can be mid-migration.
 * `/web-build` split for exactly this reason, and splitting here keeps the
 * symmetry: identity is its own question, asked by a human or a monitor when
 * something looks wrong, not thousands of times an hour by a load balancer.
 *
 * Registered UNVERSIONED (`/api/build`, never `/api/v1/build`) beside
 * `/api/health` and `/api/version`, so a runbook or an alert rule written today
 * survives an API version bump — the same reason those two are unversioned.
 *
 * DISCLOSURE NOTE — THE COMMIT IS PUBLIC HERE, DELIBERATELY.
 * ---------------------------------------------------------
 * This endpoint is unauthenticated. A commit hash is information some operators
 * would rather not publish, so this is a decision and not an oversight:
 *
 *  - THE OPERATOR DIAGNOSING DRIFT OFTEN CANNOT AUTHENTICATE, AND THAT IS NOT
 *    A HYPOTHETICAL. The concrete incident behind #1049 is a database sitting
 *    at migration 100 under a checkout at 115, with `profiles.auth_method` not
 *    yet existing — the exact column the login path reads. A build-identity
 *    endpoint behind a session would have been unreachable in the incident it
 *    exists to explain, and would have answered only once the operator no
 *    longer needed it.
 *  - A GATED ANSWER IS NOT COMPARABLE. The whole check is `/api/build` against
 *    `/web-build`, and `/web-build` is served unauthenticated by a separate
 *    tier that holds no session of this one. Gating one half turns a two-curl
 *    comparison into a credential-bearing script that a blackbox monitor
 *    cannot run, which is how the previous version of this capability came to
 *    require `docker exec`, which is how it came to be nobody's routine.
 *  - THE MARGINAL DISCLOSURE IS SMALL. `/api/health` has published the core
 *    version since WC-172 and the SDK version since WHIT-587, and the release
 *    tags are public. A commit narrows "which release" to "which commit of
 *    that release", against a codebase that ships as a public repository and a
 *    public GHCR image.
 *
 * It is nonetheless REVERSIBLE IN ONE LINE, and cheaply: nothing in the product
 * reads this route — no UI, no client, no plugin — so deleting its registration
 * in `public/index.php` costs a monitoring surface and no feature. An operator
 * who weighs the disclosure differently is one line from a private deployment.
 *
 * WHAT THE FIELDS MEAN, AND THE ONE PAIR THAT IS THE WHOLE POINT
 * -------------------------------------------------------------
 *  - `commit`           : the checkout THIS WORKER IS RUNNING, frozen at boot.
 *  - `source`           : where that came from — `build` (baked into the image),
 *                         `checkout` (read from `.git` at boot), or `unknown`.
 *                         See {@see BuildIdentity} for why it is reported and
 *                         not inferred.
 *  - `core_version`     : `CoreVersion::VERSION` — named as `/web-build` names
 *                         it, so the two documents diff directly. It is the
 *                         same value `/api/health` publishes as `version`,
 *                         which keeps ITS name because alerting parses it.
 *  - `built_at`         : when the identity was captured; null unless `source`
 *                         is `build`.
 *  - `booted_at`        : when this worker started. A restart is the third act
 *                         of a deployment (pull, migrate, restart) and the one
 *                         nothing reported: workers never recycle here, so an
 *                         old `booted_at` under a new checkout IS the bug.
 *  - `checkout_commit`  : the commit ON DISK, read per request. Null in an
 *                         image deployment, which has no `.git` and needs none.
 *  - `applied_migration_count` / `latest_applied_migration` /
 *    `pending_migration_count` : the schema state this instance is actually in.
 *                         CORE migrations only — plugin migrations share the
 *                         same ledger table and are excluded; see
 *                         {@see self::appliedMigrationNames()} for why counting
 *                         them made two of these three fields wrong.
 *
 * `commit` versus `checkout_commit` is the pair that makes the reported
 * incident self-evident from one request. They are two different questions —
 * what this process LOADED, and what is on the filesystem NOW — and they are
 * equal on a healthy instance. When they differ, the code was updated and the
 * workers were never restarted, so the backend is serving the old build and
 * every other signal, `/api/health` included, says everything is fine.
 * `pending_migration_count > 0` is the other half: the code is new and the
 * schema is not.
 *
 * There is deliberately no `build_id`. `/web-build` carries one because Next
 * produces a bundle with an id of its own; PHP loads source, so a second name
 * for the commit would be a field that can only ever agree with `commit` or be
 * wrong.
 *
 * ALWAYS 200, INCLUDING WITH A DEAD DATABASE. The identity half is computed
 * from a boot-time value and answers when nothing else does — which is exactly
 * when somebody asks which build is misbehaving. The schema half degrades to
 * nulls rather than to zeroes, because `pending_migration_count: 0` means
 * "nothing to apply" and must never be produced by a query that failed.
 * Liveness stays `/api/health`'s job, and it still 503s.
 */
final class BuildApiHandler
{
    private Database $db;

    /** Application root — where `build-identity.json` and `.git` are looked for. */
    private string $repoRoot;

    /** Directory holding the numbered core migration files. */
    private string $migrationsDir;

    /** Unix timestamp captured at worker boot (shared with `/api/health`'s uptime). */
    private int $bootTimestamp;

    /**
     * Resolved ONCE, here, because a handler is constructed during the worker
     * bootstrap and held for the process's life. See {@see BuildIdentity}.
     */
    private BuildIdentity $identity;

    /**
     * @param Database            $db            Worker-scoped database wrapper (schema state only).
     * @param string              $repoRoot      Application root.
     * @param string              $migrationsDir Core migrations directory.
     * @param int|null            $bootTimestamp Worker boot time; defaults to "now".
     * @param BuildIdentity|null  $identity      Pre-resolved identity; defaults to resolving from $repoRoot.
     */
    public function __construct(
        Database $db,
        string $repoRoot,
        string $migrationsDir,
        ?int $bootTimestamp = null,
        ?BuildIdentity $identity = null
    ) {
        $this->db = $db;
        $this->repoRoot = $repoRoot;
        $this->migrationsDir = $migrationsDir;
        $this->bootTimestamp = $bootTimestamp ?? time();
        $this->identity = $identity ?? BuildIdentity::resolve($repoRoot);
    }

    /**
     * Handle `GET /api/build`.
     *
     * @param Request $request The incoming request (unused; the endpoint takes no input).
     */
    public function handle(Request $request): Response
    {
        $schema = $this->schemaState();

        $body = [
            // NOT CoreVersion::VERSION, and the distinction is the entire
            // feature: a constant moves with the source whether or not the
            // source was deployed. tests/Api/BuildApiHandlerTest.php fails if
            // this ever silently becomes a constant again.
            'commit' => $this->identity->commit,
            'source' => $this->identity->source,
            'core_version' => CoreVersion::VERSION,
            'built_at' => $this->identity->builtAt,
            'booted_at' => gmdate('Y-m-d\TH:i:s\Z', $this->bootTimestamp),
            'uptime_seconds' => max(0, time() - $this->bootTimestamp),
            // Read per request on purpose — this one is a question about the
            // filesystem NOW, not about the process.
            'checkout_commit' => BuildIdentity::commitFromCheckout($this->repoRoot),
            'applied_migration_count' => $schema['applied'],
            'latest_applied_migration' => $schema['latest'],
            'pending_migration_count' => $schema['pending'],
        ];

        // A cached build identity is the previous build's identity, which is the
        // very lie being hunted. Same header, same reason, as `/web-build`.
        return Response::json($body, 200)->withHeaders(['Cache-Control' => 'no-store']);
    }

    /**
     * The schema state this instance is in: how many core migrations are
     * recorded as applied, the highest one, and how many files on disk are not.
     *
     * All three are null together when the database could not answer. Zero is a
     * claim; null is the absence of one.
     *
     * @return array{applied: int|null, latest: string|null, pending: int|null}
     */
    private function schemaState(): array
    {
        $applied = $this->appliedMigrationNames();

        if ($applied === null) {
            return ['applied' => null, 'latest' => null, 'pending' => null];
        }

        $appliedSet = array_flip($applied);
        $pending = 0;

        foreach ($this->migrationFileNames() as $name) {
            if (!isset($appliedSet[$name])) {
                $pending++;
            }
        }

        // The files are `NNN_snake_case.php`, zero-padded, so a plain sort is
        // migration order. Reading the maximum NAME rather than the maximum
        // `executed_at` is deliberate: a batch run stamps many rows with the
        // same instant, and "the newest row" would then be whichever the engine
        // happened to return.
        sort($applied);

        return [
            'applied' => count($applied),
            'latest' => $applied === [] ? null : $applied[count($applied) - 1],
            'pending' => $pending,
        ];
    }

    /**
     * CORE migration names recorded in `core_schema_migrations`, or null when
     * the database could not be asked.
     *
     * A MISSING TABLE IS NOT AN ERROR, it is an answer: an instance whose
     * database has never been migrated has applied nothing, and reporting
     * `applied: 0, pending: <all of them>` describes it exactly. That is the
     * same reading {@see MigrationsApiHandler::getExecutedMigrations()} takes of
     * the same condition.
     *
     * PLUGIN ROWS SHARE THIS TABLE and are excluded here. {@see
     * \Whity\Core\PluginMigrationRunner} records its own under
     * `plugin:<Plugin>:<Class>`, which is not a filename in `database/
     * migrations` and therefore has no pending side to compare against — so
     * counting them would inflate `applied_migration_count` against a
     * `pending_migration_count` computed from core files only, and (because
     * `plugin:` sorts after every `NNN_`) would make
     * `latest_applied_migration` name a plugin class instead of the core
     * migration the schema is at. Reaching the plugin side properly means
     * loading every plugin, which is exactly the dependency this endpoint must
     * not take on to answer a question about the platform.
     *
     * @return list<string>|null
     */
    private function appliedMigrationNames(): ?array
    {
        try {
            $statement = $this->db->query('SELECT migration_name FROM core_schema_migrations');

            /** @var list<string> $names */
            $names = [];

            /** @var array<int, mixed> $rows */
            $rows = $statement->fetchAll(PDO::FETCH_COLUMN);

            foreach ($rows as $row) {
                if (is_string($row) && !str_starts_with($row, 'plugin:')) {
                    $names[] = $row;
                }
            }

            return $names;
        } catch (ConnectionException) {
            // The database is unreachable. `/api/health` is what reports that;
            // here it just means the schema question has no answer.
            return null;
        } catch (Throwable $error) {
            if (str_contains($error->getMessage(), 'core_schema_migrations')) {
                return [];
            }

            return null;
        }
    }

    /**
     * Core migration file names (without the `.php`), in filename order.
     *
     * Core only, and the endpoint's field names say `migration` rather than
     * `core_migration` for `/web-build` symmetry — so this is stated here: a
     * plugin's own migrations are tracked per plugin by
     * {@see \Whity\Core\PluginMigrationRunner} and are NOT counted. Reaching
     * them means loading every plugin, which is exactly the dependency this
     * endpoint must not take on to answer a question about the platform.
     *
     * @return list<string>
     */
    private function migrationFileNames(): array
    {
        if (!is_dir($this->migrationsDir)) {
            return [];
        }

        $entries = @scandir($this->migrationsDir);

        if ($entries === false) {
            return [];
        }

        $names = [];

        foreach ($entries as $entry) {
            if (pathinfo($entry, PATHINFO_EXTENSION) === 'php') {
                $names[] = pathinfo($entry, PATHINFO_FILENAME);
            }
        }

        sort($names);

        return $names;
    }
}
