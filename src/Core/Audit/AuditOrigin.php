<?php

declare(strict_types=1);

namespace Whity\Core\Audit;

/**
 * WHERE a set of audit rows was written from, when that was not a web request (#844).
 *
 * The trail's `actor_user_id` answers "who", and for an HTTP request that is the
 * whole answer: a person authenticated, the middleware put their profile id in
 * {@see AuditContext}, and the row names them. A command typed into a shell has
 * no such person — nothing authenticated, so there is nobody the platform can
 * name — and yet the action is every bit as security-relevant as the same
 * action over the API.
 *
 * This value object is the second half of that answer. It is handed to an
 * {@see AuditLogger} at CONSTRUCTION time, and every row that logger writes
 * carries it under {@see self::METADATA_KEY}. So a reader who finds a
 * `role.deleted` with no actor can tell the two silences apart:
 *
 *   - no actor, no origin      → a pre-auth HTTP action (a failed login)
 *   - no actor, origin `cli`   → an operator's shell command
 *
 * WHY THIS IS NOT A SYNTHETIC ACTOR ID. The tempting alternative is to invent a
 * "system user" and stamp its id in `actor_user_id`, which would make CLI rows
 * sort and filter like every other row. It is rejected because `actor_user_id`
 * means "the identity that authenticated", and inventing one puts a claim in
 * that column that nothing can back: an investigator reading it cannot tell an
 * invented actor from a real account, and a row that names the wrong actor is
 * worse than a row that admits it does not know. Provenance and identity are
 * different facts and are recorded in different places.
 *
 * The actor is NOT suppressed either. If a CLI ever authenticates a real
 * operator (an `--as` flag, a device login, or the dedicated CLI service
 * principal the entry point will eventually hold), {@see AuditContext} will
 * carry that identity and the row records it — WITH this origin beside it. Both
 * facts, never one standing in for the other.
 *
 * Immutable and process-scoped by construction: it holds two short strings
 * decided once at bootstrap, so an {@see AuditLogger} that owns one is still
 * the stateless, shared-across-requests infrastructure its own docblock
 * promises (no per-request state, safe on a FrankenPHP persistent worker).
 */
final class AuditOrigin
{
    /**
     * The metadata key the channel is stored under.
     *
     * Underscore-prefixed to mark it as WRITER-owned, matching the `_context`
     * convention in hook payloads. {@see AuditLogger::record()} stamps it AFTER
     * sanitising the caller's metadata, so a hook payload — or a plugin's
     * declared event, which is the same code path — cannot forge or overwrite
     * a row's provenance by shipping a key of this name.
     */
    public const METADATA_KEY = '_origin';

    /**
     * The metadata key the specific unit of work is stored under: the command
     * word for {@see self::CHANNEL_CLI}, the job name for {@see self::CHANNEL_JOB}.
     *
     * One key rather than two, because both answer the reader's same follow-up
     * question — "which one?" — and a single key is what a future
     * `metadata->>'_origin_command'` index wants.
     *
     * Two FLAT keys rather than one nested object, which is what this would
     * naturally be. Both readers of this data want scalars: the admin screen
     * renders the details cell by joining `key: value` pairs, so a nested value
     * arrives as `[object Object]` in front of the operator this stamp exists
     * for — and a future JSONB index wants `metadata->>'_origin'`, which is
     * simpler than reaching a level down. Shape follows the reader.
     */
    public const COMMAND_METADATA_KEY = '_origin_command';

    /**
     * A command-line invocation: `bin/whity-cli …` or `php public/index.php …`.
     *
     * The `queue:work` worker is started this way but does NOT use this channel:
     * what it does is not the operator's command, it is whatever the queue held
     * (#935). See {@see self::CHANNEL_JOB}.
     */
    public const CHANNEL_CLI = 'cli';

    /**
     * Work performed by the background worker on behalf of a queued job (#935).
     *
     * Distinct from {@see self::CHANNEL_CLI} even though `queue:work` is itself a
     * command, because the two answer different questions. `cli` says an operator
     * typed something; `job` says the platform ran work somebody enqueued
     * earlier — possibly a different person, possibly a schedule, and nobody was
     * present when it happened. A reader chasing "who changed this" needs those
     * apart: `cli` points at a shell history, `job` points at whatever enqueued
     * the work.
     */
    public const CHANNEL_JOB = 'job';

    /**
     * What a recorded command name may look like: a lower-case verb, optionally
     * namespaced (`queue:work`), and short.
     *
     * The pattern exists because the caller reads the command out of `argv`, and
     * argv is operator input. Anything that does not match is dropped rather
     * than cleaned up: an unrecognisable token tells a reader nothing, and the
     * trail is not the place to find out what happens when a 4KB string reaches
     * a JSONB column.
     */
    private const COMMAND_PATTERN = '/^[a-z][a-z0-9:._-]{0,63}$/';

    /**
     * @param string      $channel How the process was invoked ({@see self::CHANNEL_CLI}).
     * @param string|null $command The command word, or null when it is not known.
     */
    private function __construct(
        private readonly string $channel,
        private readonly ?string $command
    ) {
    }

    /**
     * The origin of a command-line process.
     *
     * ONLY THE COMMAND WORD, never its arguments — deliberately, and this is the
     * reason the caller passes `argv[1]` alone rather than the whole line. A
     * command line routinely carries secrets (`--admin-password=…`, a token, a
     * DSN), the audit trail is readable by every tenant administrator with
     * `audit:read`, and {@see AuditLogger::sanitizeMetadata()} cannot help here:
     * it drops FORBIDDEN KEYS, and a raw command line is one opaque string with
     * no keys to inspect. "tenant" is all a reader needs to know which command
     * did this; the shell history has the rest.
     *
     * @param string|null $command The command word as typed, or null.
     * @return self
     */
    public static function cli(?string $command = null): self
    {
        return new self(self::CHANNEL_CLI, self::normalizeCommand($command));
    }

    /**
     * The origin of work run by the background worker (#935).
     *
     * Carries no name, deliberately. One worker process runs many different jobs
     * in sequence, so WHICH job wrote a row is a per-unit-of-work fact and not a
     * bootstrap one — and this object is immutable and process-scoped precisely
     * so an {@see AuditLogger} that owns one stays stateless. The job name
     * travels on {@see AuditContext} instead, which is already set and cleared
     * around each job by {@see \Whity\Core\Queue\JobRunner}, and is stamped
     * into {@see self::COMMAND_METADATA_KEY} by the logger.
     *
     * @return self
     */
    public static function job(): self
    {
        return new self(self::CHANNEL_JOB, null);
    }

    /**
     * Whether a name is safe to record as the specific unit of work.
     *
     * Exposed so the logger can apply the SAME rule to a job name that
     * {@see self::cli()} applies to a command word — one pattern, one place.
     * Job names are registry keys (`core.notifications.deliver`, `acme:sync`),
     * so they pass; anything that does not is dropped rather than cleaned up.
     *
     * @param string|null $name The raw candidate.
     * @return string|null The name, or null when it is not usable.
     */
    public static function normalizeUnitName(?string $name): ?string
    {
        return self::normalizeCommand($name);
    }

    /**
     * The origin as it is stored on an audit row.
     *
     * @return array<string, string> Writer-owned metadata keys and their values.
     */
    public function toMetadata(): array
    {
        $metadata = [self::METADATA_KEY => $this->channel];

        if ($this->command !== null) {
            $metadata[self::COMMAND_METADATA_KEY] = $this->command;
        }

        return $metadata;
    }

    /**
     * Accept a command name only when it looks like one.
     *
     * A PHPUnit run, a REPL, or any process whose `argv[1]` is a file path or a
     * flag lands here too; those produce null, so the row still says `cli` and
     * simply does not claim to know which command.
     *
     * @param string|null $command The raw candidate.
     * @return string|null The command name, or null when it is not usable.
     */
    private static function normalizeCommand(?string $command): ?string
    {
        if ($command === null) {
            return null;
        }

        $candidate = strtolower(trim($command));

        return preg_match(self::COMMAND_PATTERN, $candidate) === 1 ? $candidate : null;
    }
}
