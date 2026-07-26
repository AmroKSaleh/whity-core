# @amroksaleh/features

## 0.1.0

### Minor Changes

- Initial release: the multi-client feature-extraction pilot.
  - App-shell nav contract (`@amroksaleh/features/nav`): `NavConfig`/`NavItemConfig`/`NavGroupConfig` plain-data types, an injectable `NavLinkAdapter`, and `resolveNavGroups()` bridging a client-authored config into `AppSidebar`'s `AppSidebarNavGroup[]` shape (label translation via an optional injected `t()`, active-route matching against the caller's current path). Ships `exampleNavConfig` as a working reference.
  - `DemoCatalogList` / `DemoCatalogDetail` (`@amroksaleh/features/demo-catalog`): the pilot feature itself — a small, deliberately generic list/detail screen pair with zero Next.js dependency and zero direct data fetching. All data access goes through an injected `DemoCatalogAdapter` (`list`/`get`/`save`); `web/` wires it to the `DemoCatalog` plugin's REST API via its own api-client, `packages/spa-harness` wires it to an in-memory store — proving the same components render unmodified under both.
  - Zero-Next-deps guard added to CI (`.github/workflows/publish-features.yml`), mirroring `packages/ui`'s guard.
