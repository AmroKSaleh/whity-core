# Products, names and plans

Whity is a branded house: one name and one plain noun that says what each thing does.

| Name | What it is | Licence and price | Status |
| --- | --- | --- | --- |
| Whity Core | The platform: identity, tenants, permissions, documents, forms, plugins, MCP server | AGPL-3.0 with the plugin exception, free | Built |
| Whity SDK | The plugin SDK and UI kit | MIT, free | Built |
| Whity Render | Pixel-exact PDF and image export for documents, invoices, labels (the 13 document templates in section 16) | Closed, paid service | Inside Core today; to split out |
| Whity ID | Single sign-on and identity provider (OIDC) | Closed, paid service | Planned |
| Whity Front Office | First app: leads, quotes and compliant invoices | Closed, paid app | Proposed |
| Whity Marketplace | Partners and developers sell plugins | Revenue share | Later |

When a plan doesn't include a feature, the screen shows it greyed behind a `PlanGate` card naming the plan: owners can upgrade, everyone else can ask an owner. Never hide it (decision 2.1d).

## Rules

- Whity + one noun, in English in every interface language. No "AI", "Pro", "Plus" or version numbers in a product name.
- Third-party plugins name the job first: "Inventory for Whity", "WhatsApp Connector for Whity".
- Partner products carry the partner's own name, with the optional Built on Whity badge.
- Features are lower-case nouns, not brands: approvals, scopes, activity, branding, delegations.
- Agents are named by role, by the customer: "Billing assistant", "Front desk". Never a human first name.

## Plans

Plans are entitlement bundles (ADR 0010) with a payment wall that defaults to "warn" (ADR 0011). Names and prices are not decided: use [plan name] and [price] in every mockup until they are. Pricing will be a base plan plus usage for agent work.
