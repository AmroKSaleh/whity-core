# Design asks for the brand team

Screens the app needs that have no card in `components/` and aren't drawn in the app map. Owner rule (31 Oct 2026): don't invent layouts beyond the existing patterns. Each ask goes to the brand team through the owner. Meanwhile the screen is built from existing components behind a seam (one layout or card component), so the design can drop in later.

Every ask needs both languages (English and Arabic, RTL) and both themes, and follows the register: tokens only, Noto Sans at 14px, one filled main button, dashed for "not happened yet".

| Task | Ask | What the screen does | States to draw | Built meanwhile |
| --- | --- | --- | --- | --- |
| WHIT-826 | **Settings area** with a grouped sub-nav | One Settings item at the foot of the sidenav opens it (owner decision D1). Groups: Workspace (General, Branding) · Sign-in and security (Security, SSO, Sign-up) · Email (Email, Email domains) · Data (Storage, Tags, Languages, Translations) · Advanced (Feature flags, Error tracking). Each page keeps its own permission. | Sub-nav at 1280+, under 1024 (rail) and under 768 (drawer); a page inside it; a group the person can't see; system workspace vs tenant | WHIT-806: a side list of links over the existing pages, forms capped at 720px |
| WHIT-827 | **User menu** at the foot of the sidenav | The card shows only the user row. The app also has account settings, interface language (which sets direction), light/dark and sign out there, and the personal account stays here (D1). | Open menu, collapsed rail, mobile drawer | Today's stacked buttons |
| WHIT-828 | **Approvals: Decided history, and Edit before approving** | `TplAgentApprovals` draws Waiting only. Decided lists what the person approved, declined or let expire. Edit changes what the agent will send, then approves. | Many decided items, filters; an edited proposal; invalid input; an irreversible proposal | WHIT-809: a column of ActionProposal cards; a dialog with one field per argument |
| WHIT-829 | **Proposal with a Whity Builder impact plan** | Publishing or rolling back a workspace plugin arrives as a proposal carrying an impact plan: changes marked safe, data or destructive, each with English and Arabic text and the records touched, plus test results. | Safe only; data changes; destructive (Approve never pre-selected); failed tests | Rows in ActionProposal's change list |
| WHIT-830 | **Issuing an agent credential** | Name it by role, who it acts for (the issuer), the starting grant (held reads Allowed, everything else Ask first, no-undo writes never Allowed), then the token shown once to copy. The connect wizard links here for an unattended agent. `TplAgentScope` draws an existing principal only. | Each step; a grant above the person's own permissions refused; the token shown once | WHIT-810: ScopeGrant on a plain page |

Related lists: the agents pages, preset gallery, whity-agent screens and Whity ID agent consent are in `AGENT-PERSONAS-AND-WHITY-AGENT.md` §8 (WHIT-821).

## Waiting on the brand team, not an ask

- **Store revamp.** The brand team revamped the store; it isn't in this repository yet. When it lands here, the plugin and app store pages are aligned to it (WHIT-831).
