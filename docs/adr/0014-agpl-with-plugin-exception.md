# ADR 0014 — AGPL-3.0-only with a plugin linking exception; Commons Clause withdrawn

- Status: Accepted
- Date: 2026-09-10
- Deciders: Whity-Core maintainers
- Related: ADR 0003 (supersedes its licensing sections — §4, §"Licence discrepancy", and the Commons-Clause premise in §Option C), `LICENSE`, `CLA.md`, `TRADEMARK.md`, `NOTICE`

## Context

`LICENSE` carried a **custom, unusually broad Commons Clause** condition bolted
onto the full text of AGPL-3.0. ADR 0003 already recorded that `composer.json`
declared `AGPL-3.0-only` while `LICENSE` said otherwise, and flagged the
discrepancy as needing correction. It was never corrected, and the problem is
larger than a mismatched field.

**The condition was self-contradictory.** It forbade "any use ... in connection
with or to support any commercial, for-profit, or revenue-generating
activities", then twelve lines later permitted "internal business use". Both
cannot be true, and ambiguity in a licence is construed against the drafter.

**It was probably unenforceable as written.** AGPLv3 §7 permits a recipient to
*remove* further restrictions imposed on top of the licence. Publishing the
full AGPL text alongside an added restriction invites precisely that argument.

**It was strategically backwards.** It produced the adoption penalty of a
proprietary licence and the disclosure exposure of copyleft, with the
protection of neither: competitors could read everything, while the customers
and integrators who would carry the project to market were forbidden from
using it.

**And it blocked the business model.** The intended model is Odoo-shaped — a
free core, first-party applications (some paid), and eventually third-party
apps in a marketplace. That model has a hard prerequisite: **a plugin author
must be able to sell a closed-source plugin.** Odoo licenses its Community
framework LGPL, not GPL, for exactly this reason.

Two facts made a clean fix available:

1. **Sole copyright.** `git log` shows one human author across all 1,572
   commits; every other author is Dependabot or a GitHub Actions bot. The
   project can still be relicensed unilaterally — a property that disappears
   permanently on the first outside contribution.

2. **The boundary already exists.** `whity/plugin-sdk` is a separate MIT
   package that "depends on nothing but PHP at runtime". Plugins import only
   `Whity\Sdk\*`; core's `Response`/`Request` are *subclasses* of the SDK's, so
   a plugin never touches core types. The architecture was already built for
   this; the licence simply did not say so.

## Decision

**1. Whity Core is AGPL-3.0-only.** The Commons Clause is withdrawn in full.
Commercial use is permitted on the AGPL's terms.

**2. Add the Whity Plugin Exception** (`LICENSE`), an *additional permission*
under AGPLv3 §7 — it grants rights and removes none, which is what §7 allows.
A work that uses only interfaces published by the Plugin SDK, and does not
incorporate core source, may be licensed on any terms its author chooses,
including proprietary and commercial ones. Modified versions of Whity Core
itself remain fully AGPL, §13 network clause included.

**3. The plugin boundary is MIT on both sides.** `whity/plugin-sdk` already
was. The four published client packages — `@amroksaleh/ui`,
`@amroksaleh/features`, `@amroksaleh/api-client`, `@amroksaleh/tokens` — were
published to GitHub Packages declaring **`UNLICENSED`**, which grants no rights
to anybody. They are now MIT. This was not cosmetic: downstream consumers,
including Elmak, had no legal basis to use them, and no plugin author could
have built a UI.

**4. Add a CLA** (`CLA.md`), signed by adding a line to `CONTRIBUTORS.md`.
Contributors keep their copyright and grant permission to relicense. This is
what preserves the ability to sell commercial exceptions.

**5. Add a trademark policy** (`TRADEMARK.md`). The code is free; the name is
not. This is where commercial leverage actually lives once the licence is
permissive — the same arrangement Linux, Mozilla, WordPress, Odoo and Grafana
all use.

**6. Add `NOTICE`**, attributing third-party dependencies and recording the
licence audit.

## Consequences

**Adoption is unblocked.** A company's counsel can read `LICENSE` and reach a
decision. Integrators and hosts may build businesses on Whity, which is the
only known way a substrate reaches a market.

**Paid plugins are legally sound**, not merely "probably defensible". That
distinction matters: nobody funds a product on an unresolved derivative-work
question.

**Competitors may run Whity commercially.** This is a real cost and it is
accepted. AGPL §13 means a competitor offering a modified Whity as a service
must publish their modifications, and the trademark means they cannot call it
Whity. Those two together are the protection; the Commons Clause was not.

**The CLA is now load-bearing and time-sensitive.** It must be in place before
the first outside contribution. Retrofitting requires the agreement of every
past contributor, and one refusal blocks commercial relicensing permanently.

**A future proprietary edition has one known constraint.** `sharp`'s libvips
binaries are LGPL-3.0-or-later. LGPL §4 permits bundling in a proprietary work
only where the user can replace the library — satisfied today, since it is an
ordinary npm dependency, but recorded in `NOTICE` so it is not rediscovered
late.

**ADR 0003's licensing analysis is superseded.** Its §4 and its
"licence discrepancy" note both assumed AGPL + Commons Clause. The
architectural conclusions in ADR 0003 are unaffected — indeed the MIT SDK it
argued for is what makes this decision possible.

## Alternatives considered

**Keep the Commons Clause.** Rejected: self-contradictory, probably strippable
under §7, blocks the app ecosystem, and deters the exact audience needed.

**Go LGPL, like Odoo Community.** Rejected: LGPL permits a competitor to run a
modified Whity as a closed SaaS with no obligation to share anything. AGPL's
§13 is the whole reason to prefer it for network software, and the plugin
exception recovers the one property LGPL was wanted for.

**Go permissive (MIT/Apache) throughout.** Rejected: gives away the network
clause for nothing in return at this stage.

**Open-core: hold features back as proprietary.** Not rejected, but not decided
here. It remains available later — the CLA is what keeps it available. Note
that gating SSO specifically is a well-known way to lose a community's
goodwill; branding removal, multi-company consolidation and compliance exports
are the less contentious lines.

## Not a lawyer

This ADR and the licence text it describes were drafted by the maintainer with
AI assistance. The reasoning is sound as engineering; it is not legal advice.
Have counsel review `LICENSE`, `CLA.md` and `TRADEMARK.md` before relying on
them commercially — particularly the plugin exception's wording, which is the
clause a plugin author's own lawyer will read most closely.
