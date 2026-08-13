<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * Optional theme-override contribution point for plugins (SDK v1.12).
 *
 * A plugin MAY implement this interface — in addition to
 * {@see PluginInterface} — to let the host apply a set of design-token CSS
 * variable overrides at render time (e.g. a "theme creator" plugin that lets
 * an admin pick colors, then repaints the live app). Like the other sibling
 * capability interfaces ({@see PluginFrontendInterface},
 * {@see PluginMcpInterface}), this is purely additive: plugins that do not
 * implement it load exactly as before, and the host degrades to "no
 * overrides" on any error — a theme-override plugin can never break page
 * render.
 *
 * Route ownership (same trust model as data-bound blocks, WC-230)
 * -----------------------------------------------------------------
 * {@see getThemeOverrideRoute()} returns the plugin's OWN unversioned GET
 * path — the host verifies this is a route the SAME plugin actually
 * registered (first-registration-wins if more than one plugin implements
 * this interface; a later declarant is dropped with a logged warning) before
 * ever calling it. A path the plugin does not itself serve is REJECTED.
 *
 * The route's handler runs exactly like any other plugin route (its own
 * `requiredPermission` is enforced), tenant-scoped via the normal
 * `TenantContext` the handler already reads from, and MUST return
 * `{"data": {"<token>": "<#rrggbb>", ...}}` — token names are whatever the
 * design system's `src/design/tokens/generated/theme.json` calls them (e.g.
 * `primary`, `success`); the host validates every key against that known set
 * and every value against a strict `#rrggbb` hex pattern before ever
 * interpolating it into a `<style>` tag — an unknown key or malformed value
 * is silently dropped, never trusted blindly even though the route itself is
 * already permission-gated.
 */
interface PluginThemeInterface
{
    /**
     * @return string the plugin's own unversioned GET path, e.g.
     *                 '/api/plugins/theme-creator/current'
     */
    public function getThemeOverrideRoute(): string;
}
