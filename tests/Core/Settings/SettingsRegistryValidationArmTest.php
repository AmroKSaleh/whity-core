<?php

declare(strict_types=1);

namespace Tests\Core\Settings;

use PHPUnit\Framework\TestCase;
use Whity\Core\Settings\SettingsRegistry;

/**
 * Every registry key must be able to validate its own default.
 *
 * THE DEFECT THIS CLOSES HAS NOW HAPPENED TWICE. {@see SettingsRegistry::validate()}
 * is a `match` over explicit keys ending in `default => "Unknown setting key"`.
 * A key can therefore be declared with a default, a type, enum options and a
 * global-only marking — everything that makes it look complete — and still have
 * no arm, so every attempt to SET it is refused as unknown. The setting exists,
 * reads correctly, appears on the settings screen, and cannot be saved.
 *
 * It happened first to the five `error_tracking.*` keys, whose own comment in
 * that match records it. It happened again, two hundred lines below that
 * comment, to six of the `#billing` free-text keys — a company name, an
 * address, a tax registration, a bank alias — which is how this test came to
 * exist.
 *
 * WHY THE EXISTING CATALOGUE PIN DID NOT CATCH IT. That pin asserts the SET of
 * keys, their defaults and their types. All three were correct in both
 * incidents: the key was declared properly and the omission was somewhere else
 * entirely. Pinning what a key IS says nothing about whether it can be written.
 *
 * WHY VALIDATING THE DEFAULT IS THE RIGHT PROBE. A default is a value the
 * registry itself asserts is acceptable, so any key rejecting its own default
 * is either missing an arm or has one that contradicts the default — and both
 * are bugs. It needs no per-key fixture, so it cannot fall behind the registry
 * the way a hand-maintained list of specimen values would.
 */
final class SettingsRegistryValidationArmTest extends TestCase
{
    /**
     * The whole point. `validate()` must not answer "Unknown setting key" for a
     * key `keys()` just handed us.
     */
    public function testEveryKeyHasAValidationArm(): void
    {
        $unreachable = [];

        foreach (SettingsRegistry::keys() as $key) {
            $reason = SettingsRegistry::validate($key, SettingsRegistry::defaultFor($key));

            if (is_string($reason) && str_contains($reason, 'Unknown setting key')) {
                $unreachable[] = $key;
            }
        }

        self::assertSame(
            [],
            $unreachable,
            "These keys are declared but cannot be SET — validate() has no arm for them, so every "
            . "PATCH is refused as unknown:\n  " . implode("\n  ", $unreachable)
        );
    }

    /**
     * And nothing may reject its own default for any other reason either.
     *
     * A key whose default fails its own validator is a fresh installation that
     * cannot save the settings page without first changing a field nobody
     * touched — the shape of bug where "it works on my machine" means "my
     * machine was configured before the validator was tightened".
     *
     * Asset keys are exempt: their stored value is a storage key written by the
     * upload endpoints, and `validate()` deliberately refuses them as text.
     */
    public function testNoKeyRejectsItsOwnDefault(): void
    {
        $rejected = [];

        foreach (SettingsRegistry::keys() as $key) {
            if (SettingsRegistry::kindFor($key) === 'asset') {
                continue;
            }

            $reason = SettingsRegistry::validate($key, SettingsRegistry::defaultFor($key));

            if ($reason !== null) {
                $rejected[] = "{$key}: {$reason}";
            }
        }

        self::assertSame([], $rejected, "A key rejected its own default:\n  " . implode("\n  ", $rejected));
    }

    /**
     * The guard is only meaningful if it is actually looking at every key, so
     * this asserts it saw a plausible number of them. Without it, a `keys()`
     * that returned nothing would make both tests above pass vacuously.
     */
    public function testTheGuardActuallySweepsTheRegistry(): void
    {
        self::assertGreaterThan(70, count(SettingsRegistry::keys()));
    }
}
