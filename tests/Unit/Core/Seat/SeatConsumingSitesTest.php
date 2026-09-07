<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Seat;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * EVERY PLACE THAT CAN GIVE SOMEBODY A SEAT, frozen.
 *
 * A seat is taken by creating a membership or by activating one that was
 * suspended, and those happen in NINE files — most of them writing the SQL
 * themselves rather than going through {@see \Whity\Core\Identity\MembershipRepository}.
 * A guard placed in the repository would be bypassed by the two admin paths,
 * self-registration, invitation acceptance and tenant creation, which between
 * them are how almost every real member arrives.
 *
 * This is the same treatment {@see \Tests\Unit\Core\Identity\ProfileCredentialWriteSitesTest}
 * gives credential writes, and its docblock records why: that bug "was reported
 * as one endpoint... It was six". Seats are nine, and the ninth was found by this very test after a careful
 * manual survey had settled on eight — a rule that survives only while
 * everybody remembers it does not survive.
 *
 * WHAT THIS TEST DOES AND DOES NOT CLAIM
 * --------------------------------------
 * It does NOT assert that each site is guarded — {@see \Whity\Core\Seat\SeatService}
 * exists and is tested, and wiring it into these paths is deliberately a
 * separate change, because six of them are authentication-sensitive and each
 * wants its own test.
 *
 * What it asserts is that the SET IS UNCHANGED. A tenth site cannot appear
 * quietly, and the nine below cannot be quietly forgotten while the wiring is
 * done. Without it, "seats are enforced" would be a sentence about five of the
 * six paths people actually arrive through, which is the failure this whole
 * class of test exists to prevent.
 */
final class SeatConsumingSitesTest extends TestCase
{
    /**
     * Files that CREATE a membership, and whether a seat check belongs there.
     *
     * The seeders are marked `false` on purpose: they run before an instance has
     * a plan, from a CLI an operator invoked, and refusing to seed a demo
     * organisation because a limit nobody set was reached would break setup to
     * enforce a commercial rule against nobody.
     *
     * @var array<string, bool> path => needs a seat check
     */
    private const SEAT_CONSUMING_SITES = [
        // A tenant admin adding somebody. Both branches take a seat: the INSERT
        // adds a new person, and the UPDATE re-activates a suspended one, who
        // held no seat while suspended.
        'src/Api/UsersApiHandler.php' => true,
        // Public self-service signup, where the instance allows it.
        'src/Api/RegisterApiHandler.php' => true,
        // APPROVING a pending registration. Found by this scanner rather than by
        // reading the code: an approval is an UPDATE to 'active', so it takes a
        // seat exactly as an insert does, and it was missed by a manual survey
        // that had already found eight. That is the whole argument for scanning.
        'src/Api/RegistrationsApiHandler.php' => true,
        // Accepting an invitation — the moment a promised seat becomes a held
        // one, and the reason `seats.count_invited` defaults to counting it.
        'src/Core/Identity/InvitationService.php' => true,
        // Creating a tenant mints its first owner.
        'src/Api/TenantsApiHandler.php' => true,
        // The shared repository: SSO just-in-time provisioning and email-domain
        // auto-join both arrive here.
        'src/Core/Identity/MembershipRepository.php' => true,

        // Setup and load-generation, not commerce.
        'src/Core/Document/Demo/DemoOrganisationSeeder.php' => false,
        'src/Database/ScaleSeeder/ScaleSeeder.php' => false,
        'src/Database/Seeder.php' => false,
    ];

    private const SCANNED_ROOTS = ['src'];

    /** Creating a membership row. */
    private const CREATES_MEMBERSHIP = '/INSERT\s+INTO\s+memberships\b/i';

    /** Bringing a suspended one back, which takes a seat just as an insert does. */
    private const ACTIVATES_MEMBERSHIP = '/UPDATE\s+memberships\s+SET\s+status/i';

    public function testTheSetOfSeatConsumingSitesIsUnchanged(): void
    {
        $found = [];

        foreach ($this->phpFiles() as $path => $literals) {
            if (
                preg_match(self::CREATES_MEMBERSHIP, $literals) === 1
                || preg_match(self::ACTIVATES_MEMBERSHIP, $literals) === 1
            ) {
                $found[] = $path;
            }
        }

        sort($found);
        $expected = array_keys(self::SEAT_CONSUMING_SITES);
        sort($expected);

        self::assertSame(
            $expected,
            $found,
            "The set of places that can give somebody a seat has changed.\n"
            . "A NEW one must be added to SEAT_CONSUMING_SITES and, unless it is a seeder, "
            . "must consult SeatService before it writes.\n"
            . "A REMOVED one should be dropped from the list."
        );
    }

    /**
     * The list is a claim about behaviour, so it must not contain paths that no
     * longer exist — a stale entry would keep the test green while describing a
     * file nobody can read.
     */
    public function testEveryListedSiteStillExists(): void
    {
        foreach (array_keys(self::SEAT_CONSUMING_SITES) as $path) {
            self::assertFileExists($this->root() . '/' . $path);
        }
    }

    /**
     * Every commercial path is accounted for. Reads as trivial and is not: it is
     * what fails if somebody marks a real path `false` to make the scanner quiet
     * rather than wiring the check.
     */
    public function testSeedersAreTheOnlyExemptions(): void
    {
        foreach (self::SEAT_CONSUMING_SITES as $path => $needsCheck) {
            if ($needsCheck) {
                continue;
            }
            self::assertMatchesRegularExpression(
                '/Seeder/i',
                $path,
                "Only seeders may be exempt from the seat check; {$path} is not one."
            );
        }
    }

    /**
     * The literals of one file joined together, so SQL assembled by
     * concatenation is caught too — `UsersApiHandler` builds its activating
     * UPDATE from a fixed prefix and a conditional fragment, and a per-literal
     * scan would call it clean.
     *
     * Only STRING LITERALS are read ({@see token_get_all}), so a docblock
     * discussing `INSERT INTO memberships` — this file has several — is not a
     * match.
     *
     * @return array<string, string> relative path => joined literals
     */
    private function phpFiles(): array
    {
        $out = [];

        foreach (self::SCANNED_ROOTS as $relativeRoot) {
            $root = $this->root() . '/' . $relativeRoot;
            if (!is_dir($root)) {
                continue;
            }

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());
                $literals = [];
                foreach (token_get_all($source) as $token) {
                    if (is_array($token) && ($token[0] === T_CONSTANT_ENCAPSED_STRING || $token[0] === T_ENCAPSED_AND_WHITESPACE)) {
                        $literals[] = $token[1];
                    }
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($this->root()) + 1));
                $out[$relative] = implode(' ', $literals);
            }
        }

        return $out;
    }

    private function root(): string
    {
        return dirname(__DIR__, 4);
    }
}
