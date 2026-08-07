<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use InvalidArgumentException;
use Throwable;
use Whity\Database\Database;
use Whity\Database\ScaleSeeder\ScaleSeeder;
use Whity\Database\ScaleSeeder\ScaleSeederConfig;
use Whity\Database\ScaleSeeder\ScaleSeederPlan;
use Whity\Database\ScaleSeeder\ScaleSeederResult;

/**
 * Scale-seed CLI command (WC-35 — Performance baseline & load/stress gating,
 * epic #35).
 *
 * Bulk-inserts a parameterized, deterministic, multi-tenant dataset — tenants,
 * OU hierarchies, tenant-scoped custom roles, users (profile + membership),
 * family-relations persons/edges — sized for load testing, OU/graph-hierarchy
 * rendering testing, and pagination testing. "Deterministic" means the same
 * `--seed` plus the same shape flags always produce the same dataset (see
 * {@see \Whity\Database\ScaleSeeder\DeterministicRandom}); re-running with
 * identical flags is idempotent (nothing is duplicated).
 *
 * Every shape/scale knob is a CLI flag with a documented default — see
 * {@see ScaleSeederConfig} for the single source of truth on defaults.
 *
 * Usage:
 *   whity-cli scale:seed [options]
 *   whity-cli scale:seed --dry-run              Preview row counts, no writes
 *   whity-cli scale:seed --tenants=50 --users-per-tenant=200
 *   whity-cli scale:seed --scale=4               Quadruple the per-tenant volume
 *   whity-cli scale:seed --reset                 Wipe this seed's prior data first
 */
class ScaleSeedCommand
{
    /** U+2713 CHECK MARK, expressed as a byte escape to avoid file-encoding ambiguity. */
    private const CHECK_MARK = "\xE2\x9C\x93";

    /** U+2717 BALLOT X, expressed as a byte escape to avoid file-encoding ambiguity. */
    private const CROSS_MARK = "\xE2\x9C\x97";

    public function __construct(private ?Database $injectedDb = null)
    {
    }

    /**
     * @param list<string> $argv
     */
    public function execute(array $argv): int
    {
        if ($this->wantsHelp($argv)) {
            $this->showHelp();
            return 0;
        }

        $options = $this->parseOptions($argv);

        try {
            $config = ScaleSeederConfig::fromOptions($options);
        } catch (InvalidArgumentException $e) {
            echo "\033[0;31mError: " . $e->getMessage() . "\033[0m\n";
            return 1;
        }

        $plan = ScaleSeederPlan::fromConfig($config);
        $this->printPlan($config, $plan);

        if ($config->dryRun) {
            echo "\nDry run: no database changes were made. Re-run without --dry-run to execute.\n";
            return 0;
        }

        try {
            $db = $this->injectedDb ?? Database::connect();
            $seeder = new ScaleSeeder($db);

            if ($config->reset) {
                echo "\nResetting prior scale-seeded data for seed {$config->seed}...\n";
                $resetSummary = $seeder->reset($config);
                echo sprintf(
                    "  Removed %d tenant(s), %d profile(s).\n",
                    $resetSummary['tenantsDeleted'],
                    $resetSummary['profilesDeleted']
                );
            }

            echo "\nSeeding...\n";
            $result = $seeder->run(
                $config,
                static function (int $tenantIndex, int $total, ScaleSeederResult $soFar): void {
                    $usersSoFar = $soFar->usersCreated + $soFar->usersReused;
                    echo "  tenant {$tenantIndex}/{$total} done (users so far: {$usersSoFar})\n";
                }
            );

            $this->printResult($result);

            echo "\n\033[0;32m" . self::CHECK_MARK . " Scale-seed complete.\033[0m\n";
            return 0;
        } catch (Throwable $e) {
            echo "\033[0;31m" . self::CROSS_MARK . " Scale-seed failed: " . $e->getMessage() . "\033[0m\n";
            return 1;
        }
    }

    /** @param array<int, string> $argv */
    private function wantsHelp(array $argv): bool
    {
        return in_array('--help', $argv, true) || in_array('-h', $argv, true) || ($argv[0] ?? null) === 'help';
    }

    private function printPlan(ScaleSeederConfig $config, ScaleSeederPlan $plan): void
    {
        echo "Scale-seed plan (seed={$config->seed}, scale={$config->scale}):\n";
        echo "  tenants:              {$plan->tenants}\n";
        echo "  OUs per tenant:       {$plan->ousPerTenant} (depth={$config->ouDepth}, breadth={$config->ouBreadth})"
            . " -> {$plan->totalOus} total\n";
        echo "  custom roles/tenant:  {$plan->customRolesPerTenant} -> {$plan->totalCustomRoles} total\n";
        echo "  users per tenant:     {$plan->usersPerTenant} -> {$plan->totalUsers} total\n";
        echo "  persons (1:1 users):  {$plan->totalPersons} total\n";
        echo "  relations per tenant: ~{$plan->relationsPerTenant} -> ~{$plan->totalRelations} total"
            . " (density={$config->relationsPerPerson}/person)\n";
        echo "  ~{$plan->totalRows()} rows total (upper bound; a rerun of the same seed inserts nothing new)\n";
    }

    private function printResult(ScaleSeederResult $result): void
    {
        echo "\nResult (created / reused):\n";
        echo "  tenants:      {$result->tenantsCreated} / {$result->tenantsReused}\n";
        echo "  OUs:          {$result->ousCreated} / {$result->ousReused}\n";
        echo "  custom roles: {$result->customRolesCreated} / {$result->customRolesReused}\n";
        echo "  users:        {$result->usersCreated} / {$result->usersReused}\n";
        echo "  persons:      {$result->personsCreated} / {$result->personsReused}\n";
        echo "  relations:    {$result->relationsCreated} / {$result->relationsReused}\n";
    }

    /**
     * Parse `--key=value` / bare `--flag` command-line options.
     *
     * @param array<int, string> $argv
     * @return array<string, string|bool>
     */
    private function parseOptions(array $argv): array
    {
        $options = [];
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--')) {
                $parts = explode('=', substr($arg, 2), 2);
                $key = $parts[0];
                $value = $parts[1] ?? true;
                $options[$key] = $value;
            }
        }

        return $options;
    }

    private function showHelp(): void
    {
        echo "Whity Core scale-seed CLI (WC-35)\n\n";
        echo "Bulk-inserts a parameterized, deterministic multi-tenant dataset (tenants,\n";
        echo "OU hierarchies, custom roles, users, family-relations persons/edges) sized\n";
        echo "for load testing, OU/graph-hierarchy rendering testing, and pagination\n";
        echo "testing. The same --seed plus the same shape flags always produces the\n";
        echo "same dataset; re-running with identical flags is idempotent.\n\n";
        echo "Usage:\n";
        echo "  whity-cli scale:seed [options]\n\n";
        echo "Options:\n";
        echo "  --seed=N                     PRNG seed (default " . ScaleSeederConfig::DEFAULT_SEED . ")\n";
        echo "  --tenants=N                  Number of tenants"
            . " (default " . ScaleSeederConfig::DEFAULT_TENANTS . ")\n";
        echo "  --users-per-tenant=N         Users per tenant, before --scale"
            . " (default " . ScaleSeederConfig::DEFAULT_USERS_PER_TENANT . ")\n";
        echo "  --ou-depth=N                 OU hierarchy levels, including the root"
            . " (default " . ScaleSeederConfig::DEFAULT_OU_DEPTH . ")\n";
        echo "  --ou-breadth=N               Child OUs per node, before --scale"
            . " (default " . ScaleSeederConfig::DEFAULT_OU_BREADTH . ")\n";
        echo "  --relations-per-person=N     Avg. family-relation edges per person, before --scale"
            . " (default " . ScaleSeederConfig::DEFAULT_RELATIONS_PER_PERSON . ")\n";
        echo "  --custom-roles-per-tenant=N  Extra tenant-scoped roles beyond admin/user"
            . " (default " . ScaleSeederConfig::DEFAULT_CUSTOM_ROLES_PER_TENANT . ")\n";
        echo "  --scale=N                    Overall multiplier applied to users-per-tenant,\n";
        echo "                               ou-breadth and relations-per-person"
            . " (default " . ScaleSeederConfig::DEFAULT_SCALE . ")\n";
        echo "  --batch-size=N               Users processed per DB commit"
            . " (default " . ScaleSeederConfig::DEFAULT_BATCH_SIZE . ")\n";
        echo "  --dry-run                    Print the computed plan; make no database changes\n";
        echo "  --reset                      Delete this seed's prior scale-seeded data first\n";
        echo "  --help, -h                   Show this help\n\n";
        echo "Scale-seeded users all share one password, resolved from the\n";
        echo "SCALE_SEED_PASSWORD environment variable (a random one is generated and\n";
        echo "printed once when unset — see INITIAL_ADMIN_PASSWORD for the same pattern).\n";
    }
}
