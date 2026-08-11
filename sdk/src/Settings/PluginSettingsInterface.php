<?php

declare(strict_types=1);

namespace Whity\Sdk\Settings;

/**
 * Declares the CONFIGURATION SETTINGS a plugin owns (SDK 1.21, #713 item 1).
 *
 * The host already has a settings layer: two tables (one global, one per-tenant),
 * a resolution chain, typed validation, normalisation, and an admin screen. What
 * it did not have was a way for a plugin to put anything into it. So every plugin
 * that needed configuration built the same thing again, worse — most often a
 * private table shaped exactly like `tenant_settings` but with no declared keys
 * and no validation, where a typo writes a new invisible row instead of failing.
 *
 * This interface is the seam. A plugin declares its keys; the host stores them in
 * ITS OWN tables, resolves them through the SAME chain, and validates every write
 * against the plugin's own declaration.
 *
 * Resolution chain
 * ----------------
 * A declared key resolves exactly as a core key does:
 *
 *     per-tenant override  ??  global default  ??  the `default` declared here
 *
 * Nothing is written to either table at registration: an undisturbed key simply
 * resolves to its declared default, so shipping a new key needs no migration and
 * no seeding, and removing the plugin removes the key without orphaning a row.
 *
 * Declaration shape
 * -----------------
 *     public function getSettings(): array
 *     {
 *         return [
 *             'sync_interval' => [
 *                 'type'        => 'int',
 *                 'default'     => 300,
 *                 'min'         => 60,
 *                 'max'         => 86400,
 *                 'label'       => ['en' => 'Sync interval', 'ar' => 'فترة المزامنة'],
 *                 'description' => 'Seconds between directory synchronisations.',
 *                 'admin'       => true,
 *             ],
 *             'mode' => [
 *                 'type'    => 'enum',
 *                 'options' => ['off', 'shadow', 'live'],
 *                 'default' => 'off',
 *             ],
 *             'endpoint' => [
 *                 'type'       => 'string',
 *                 'default'    => '',
 *                 'max_length' => 200,
 *                 'pattern'    => '^(https://.+)?$',
 *             ],
 *             'verbose' => ['type' => 'bool', 'default' => false],
 *         ];
 *     }
 *
 * Fields, all optional except `type` and `default`:
 *
 *  - `type`        — `string` | `bool` | `int` | `enum`. Drives the control the
 *                    admin screen renders and the validation the host applies.
 *  - `default`     — the fallback value. A string, or a bool/int the host
 *                    stringifies to its canonical stored form (`true`/`false`,
 *                    `300`). It MUST itself satisfy the constraints below, or the
 *                    declaration is refused: a default that fails its own
 *                    validation is a key that can never be reset.
 *  - `options`     — REQUIRED for `enum`, forbidden otherwise: the allowed values.
 *  - `min` / `max` — `int` only: the inclusive bounds.
 *  - `max_length`  — `string` only: maximum length in CHARACTERS, not bytes.
 *  - `pattern`     — `string` only: an anchorless regular expression WITHOUT
 *                    delimiters (`^[a-z]+$`, never `/^[a-z]+$/`). The host
 *                    supplies the delimiters, so a declaration cannot smuggle in
 *                    pattern modifiers.
 *  - `label`       — locale => display name, e.g. `['en' => 'Sync interval']`.
 *                    Falls back to the bare key.
 *  - `description` — one line of operator-facing help.
 *  - `global_only` — `true` marks the key operator-level: settable on the global
 *                    surface only, never as a per-tenant override. Use it when a
 *                    per-tenant value would be inert (an infrastructure switch),
 *                    not merely when you expect it to be set once.
 *  - `admin`       — `true` publishes the key on the HOST'S OWN settings screens.
 *                    Defaults to FALSE; see "The admin surface is opt-in" below.
 *
 * Namespacing and ownership
 * -------------------------
 * Declare BARE keys. The host stores them under this plugin's own namespace, so
 * `sync_interval` becomes `acme:sync_interval` — two plugins may both declare
 * `sync_interval`, and none can shadow a core key such as `site_name`. The prefix
 * derives from the plugin NAME the loader supplies, never from anything returned
 * here: a plugin may declare any key it likes; it cannot declare who said it.
 *
 * Ask the host for the canonical key rather than concatenating the prefix by
 * hand, or a change to the namespacing rule silently orphans every value the
 * plugin has written.
 *
 * The admin surface is opt-in
 * ---------------------------
 * A declared key is always stored, resolved and validated by the host. Whether it
 * also APPEARS on the host's operator settings screens is a separate decision,
 * and it defaults to no.
 *
 * The reason is authorization, not tidiness. Those screens are gated on the core
 * `settings:read` / `settings:write` / `settings:manage` permissions. A key that
 * appears there is readable and writable by everyone holding those — which is not
 * the same population as the holders of the plugin's own permissions. Publishing
 * every declared key automatically would silently widen who can reconfigure a
 * plugin, and would do it at install time, with no one deciding it.
 *
 * So `admin => true` is a deliberate statement: *this key is safe for a host
 * operator to see and change*. Keys without it remain fully functional — the
 * plugin reads them through the host's settings service and manages them on its
 * own RBAC-gated screens, which is the right home for anything gated on the
 * plugin's own permissions.
 *
 * Secrets are NOT settings
 * -----------------------
 * This contract carries no secret-shaped type, and a declaration asking for one
 * is REFUSED at registration rather than quietly downgraded to a plain string.
 * Settings are stored as readable TEXT and served by a read API; a credential
 * placed here would be retrievable by anyone holding `settings:read`.
 *
 * Core keeps its own credentials out of the registry for exactly this reason —
 * the SMTP password and the error-tracking DSN are stored encrypted, write-only,
 * behind dedicated endpoints, with only their PRESENCE exposed. A plugin needing
 * a credential should follow that pattern on its own routes. A first-class
 * plugin-facing secret contract is worth having; it is deliberately not this
 * change, and refusing the declaration is how a plugin finds that out at load
 * time instead of after shipping a readable password.
 *
 * OPTIONAL. Plugins that do not implement it are untouched.
 */
interface PluginSettingsInterface
{
    /**
     * The settings this plugin owns, keyed by BARE key.
     *
     * A bare key is lowercase, starts with a letter, contains only letters,
     * digits and underscores, and may use dots to group related keys
     * (`smtp.host`) exactly as core's own keys do. It may NOT contain a colon,
     * which is the namespace separator the host applies.
     *
     * An invalid declaration is rejected with a logged warning rather than
     * crashing the host, and rejection is per setting: one malformed entry does
     * not discard the plugin's other keys.
     *
     * @return array<string, array<string, mixed>> bare key => declaration
     */
    public function getSettings(): array;
}
