<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Identity;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The structural half of #917: nothing outside {@see \Whity\Core\Identity\AuthMethod}
 * may write `profiles.password_hash`, and the set of places that CREATE a
 * profile is frozen.
 *
 * WHY A SCANNER AND NOT A DOCBLOCK
 * --------------------------------
 * #917 was reported as one endpoint minting a local password on an IdP-backed
 * account. It was six: the admin PATCH, the self-service PATCH, the
 * forgot-password confirm, the admin approval of a staged reset, the
 * admin-mailed reset link, and 2FA recovery — each of which independently
 * needed to remember to ask a question none of them knew existed. Fixing the
 * six and writing "consult AuthMethod before writing a credential" in a comment
 * would leave the seventh to be written by someone who never read the comment,
 * and would read as complete while being exactly as incomplete as before.
 *
 * So the rule is enforced where a rule can be: the UPDATE statement lives in
 * one class, and this test fails the build if a second one appears. It is the
 * same treatment {@see \Whity\Core\Tenant\TenantOwnedTables} and its guard give
 * the tenant-isolation invariant, and for the same reason — an invariant that
 * survives only while everybody remembers it does not survive.
 *
 * HOW IT LOOKS
 * ------------
 * Only STRING LITERALS are examined ({@see token_get_all}), so a docblock
 * discussing `UPDATE profiles SET password_hash` — several of them do — is not
 * a violation. The literals of one file are joined before matching, so SQL
 * assembled by concatenation is caught too: `handleUpdateMe()` built exactly
 * that shape (`'UPDATE profiles SET ' . implode(...)` with `'password_hash = ?'`
 * arriving from a separate literal) before this landed, and a per-literal scan
 * would have called it clean.
 *
 * The cost is bluntness: a file with an unrelated `UPDATE profiles` literal and
 * an unrelated `password_hash =` literal would be flagged. That is the intended
 * direction to be wrong in — clearing it means adding a name to a list, which is
 * a decision somebody makes on purpose.
 */
final class ProfileCredentialWriteSitesTest extends TestCase
{
    /**
     * The only file permitted to write `profiles.password_hash`.
     *
     * Adding to this list is a security decision, not a refactor: every entry
     * is a place where a local credential can be created for an account that
     * may be governed by an identity provider, and each one has to answer the
     * question AuthMethod answers.
     */
    private const CREDENTIAL_WRITERS = [
        'src/Core/Identity/AuthMethod.php',
    ];

    /**
     * Every file that may CREATE a profile row.
     *
     * Frozen as an exact set rather than a floor. A new one inherits migration
     * 104's `auth_method DEFAULT 'local'`, which is right for a profile created
     * WITH a password and wrong for one created without — the case
     * FederatedIdentityLinker names `'idp'` explicitly. A creator that gets this
     * wrong produces an account the platform believes holds a local credential
     * it does not have, which is the original defect pointing the other way.
     */
    private const PROFILE_CREATORS = [
        'src/Api/RegisterApiHandler.php',
        'src/Core/Identity/FederatedIdentityLinker.php',
        'src/Core/Identity/ProfileProvisioner.php',
        'src/Database/ScaleSeeder/ScaleSeeder.php',
        'src/Database/Seeder.php',
    ];

    /**
     * The trees that ship as part of a deployment.
     *
     * `plugins/` and `sdk/` are included because the guard is about what can
     * reach the column, not about which directory it lives in: an in-tree plugin
     * holds a PDO and could write `profiles` as easily as a core handler. Third
     * party plugins are outside any static check, which is the argument for
     * AuthMethod carrying the refusal in its own statement rather than relying on
     * this test alone.
     */
    private const SCANNED_ROOTS = ['src', 'plugins', 'sdk'];

    /** `UPDATE profiles …` in any string literal of the file. */
    private const UPDATES_PROFILES = '/UPDATE\s+profiles\b/i';

    /**
     * An assignment to the credential column. `\b` matters: it keeps
     * `staged_password_hash = :hash` — which writes `password_resets`, not
     * `profiles` — out of the match.
     */
    private const ASSIGNS_PASSWORD_HASH = '/\bpassword_hash\s*=/i';

    /** `INSERT INTO profiles …` in any string literal of the file. */
    private const CREATES_PROFILE = '/INSERT\s+INTO\s+profiles\b/i';

    public function testOnlyAuthMethodWritesTheLocalCredential(): void
    {
        $offenders = [];

        foreach (self::sourceFiles() as $relative => $literals) {
            if (preg_match(self::UPDATES_PROFILES, $literals) !== 1) {
                continue;
            }
            if (preg_match(self::ASSIGNS_PASSWORD_HASH, $literals) !== 1) {
                continue;
            }
            if (in_array($relative, self::CREDENTIAL_WRITERS, true)) {
                continue;
            }
            $offenders[] = $relative;
        }

        sort($offenders);

        self::assertSame(
            [],
            $offenders,
            "These files write profiles.password_hash directly:\n  " . implode("\n  ", $offenders)
            . "\n\nRoute the write through Whity\\Core\\Identity\\AuthMethod::setPasswordHash() instead. "
            . 'It carries the refusal for identity-provider-backed accounts in the WHERE clause of the '
            . 'statement that writes, and moves auth_method on so the held fact cannot drift from the '
            . 'credential. A path that writes the column itself is a path where an SSO account can be '
            . 'given a local password nobody asked for (#917).'
        );
    }

    /**
     * The AuthMethod file really does contain the write — otherwise the test
     * above passes for the wrong reason.
     *
     * A guard whose subject has moved is worse than no guard: it keeps
     * reporting success about a rule it is no longer checking. This is the
     * cheapest possible way to notice.
     */
    public function testTheSanctionedWriterStillContainsTheWrite(): void
    {
        $files = self::sourceFiles();

        foreach (self::CREDENTIAL_WRITERS as $writer) {
            self::assertArrayHasKey($writer, $files, "{$writer} is allowlisted but does not exist");

            $literals = $files[$writer];
            self::assertMatchesRegularExpression(
                self::UPDATES_PROFILES,
                $literals,
                "{$writer} is allowlisted as the credential writer but holds no `UPDATE profiles` statement. "
                . 'Either the write moved — in which case move the allowlist with it — or this entry is stale.'
            );
            self::assertMatchesRegularExpression(
                self::ASSIGNS_PASSWORD_HASH,
                $literals,
                "{$writer} is allowlisted as the credential writer but assigns no password_hash."
            );
        }
    }

    public function testTheSetOfProfileCreatorsIsUnchanged(): void
    {
        $creators = [];

        foreach (self::sourceFiles() as $relative => $literals) {
            if (preg_match(self::CREATES_PROFILE, $literals) === 1) {
                $creators[] = $relative;
            }
        }

        sort($creators);
        $expected = self::PROFILE_CREATORS;
        sort($expected);

        self::assertSame(
            $expected,
            $creators,
            "The set of files that INSERT INTO profiles has changed.\n\n"
            . "If you added one: decide what its new profiles carry in `auth_method` (migration 104). "
            . "A profile created WITH a password takes the 'local' default and needs nothing; a "
            . "passwordless one provisioned from an identity provider must name '"
            . \Whity\Core\Identity\AuthMethod::IDP . "' explicitly, as FederatedIdentityLinker does — "
            . "leaving it defaulted tells every password-write path the account holds a local credential "
            . "it does not have. Then add the file here.\n\n"
            . 'If you removed one: drop it from PROFILE_CREATORS.'
        );
    }

    /**
     * Every `.php` under the scanned roots, mapped from its repo-relative posix
     * path to the concatenated text of its string literals.
     *
     * @return array<string, string>
     */
    private static function sourceFiles(): array
    {
        $repo = dirname(__DIR__, 4);
        $files = [];

        foreach (self::SCANNED_ROOTS as $rootName) {
            $root = $repo . DIRECTORY_SEPARATOR . $rootName;
            self::assertDirectoryExists($root, "the {$rootName}/ tree must be locatable from the test file");

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = $rootName . '/' . str_replace(
                    '\\',
                    '/',
                    substr($file->getPathname(), strlen($root) + 1)
                );
                $files[$relative] = self::stringLiteralsOf((string) file_get_contents($file->getPathname()));
            }
        }

        self::assertNotSame([], $files, 'the scanned trees must contain PHP files');

        return $files;
    }

    /**
     * The text of every string literal in a PHP source, joined by newlines.
     *
     * Comments, docblocks and identifiers are excluded by construction — the
     * tokeniser tells them apart, and a regex over raw source could not. The
     * join means SQL split across concatenated literals still matches as one
     * body, which is the point.
     */
    private static function stringLiteralsOf(string $source): string
    {
        $parts = [];

        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING || $token[0] === T_ENCAPSED_AND_WHITESPACE) {
                $parts[] = $token[1];
            }
        }

        return implode("\n", $parts);
    }
}
