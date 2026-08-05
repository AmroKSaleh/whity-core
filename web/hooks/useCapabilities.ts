import { useCapabilitiesContext } from '@/lib/capabilities-context';
import type { CapabilitiesContextValue } from '@/lib/capabilities-context';

/**
 * Shared capability surface for the currently active user + tenant
 * (WC-663f6b6b). Backed by a single `CapabilitiesProvider` fetch of
 * `GET /api/v1/me/capabilities` (mounted once in the root layout) rather than
 * one fetch per call site, so every consumer sees the SAME answer and a
 * tenant switch invalidates all of them together.
 *
 * `has(capability)` / `hasAny([...])` / `hasAll([...])` are the canonical
 * checks. `hasPermission` is kept as an alias of `has` for existing call
 * sites (WC-176/WC-177, #205).
 *
 * Fail-closed: while loading (including the in-flight window right after the
 * active user or tenant changes) or on any error (network failure, non-ok
 * response, malformed body), every check returns `false`. The server stays
 * authoritative — these slugs are UI hints only and grant nothing.
 *
 * Must be called under `CapabilitiesProvider` (mounted in the root layout);
 * throws otherwise, mirroring `useAuth`/`useNavigation`/`usePluginFeatures`.
 */
export type UseCapabilitiesResult = CapabilitiesContextValue;

export function useCapabilities(): UseCapabilitiesResult {
  return useCapabilitiesContext();
}
