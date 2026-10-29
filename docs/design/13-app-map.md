# App map (v2.2)

The screens of whity-core's web app as they exist in `web/app`, grouped as the sidebar groups them, with IDs for design work. **Template** names the template in this system; **Needs** marks server work for the agent features.

## Navigation

Sidebar groups: (top) Dashboard · Inbox · Approvals — People — AI — Documents — Platform — Settings. The tenant switcher sits under the logo; the user and account menu at the foot. The system tenant (id 0) sees Platform; tenants see their own items. Plugin items arrive from `GET /api/navigation`.

## Screens

| ID | Screen | Route | Template | Needs |
| --- | --- | --- | --- | --- |
| **Overview** | | | | |
| A01 | Dashboard | `/admin`, `/dashboard` | `TplAdminDashboard`; shell states `AppShellCollapsed`, `AppShellMobile` | |
| A02 | Inbox (documents awaiting a decision) | `/admin/inbox` | | |
| A03 | Approvals (agent proposals) | new: `/admin/approvals` | `TplAgentApprovals`, `TplAgentApprovalsAr`, `ApprovalQueue` | server 10 |
| **People** | | | | |
| A10 | Users · user | `/admin/users`, `/admin/users/[id]` | | |
| A11 | User groups | `/admin/user-groups` | | |
| A12 | Roles · role | `/admin/roles`, `/admin/roles/[id]` | | |
| A13 | Organisational units | `/admin/ous` | | |
| A14 | Delegations | `/admin/delegations` | | |
| A15 | Relations | `/admin/relations` | | |
| A16 | Registrations · invitations | `/admin/registrations` | | |
| **AI** | | | | |
| A20 | AI principals | `/admin/ai-principals` | | |
| A21 | AI principal (scope, credential) | new: `/admin/ai-principals/[id]` | `TplAgentScope` | server 11 |
| A22 | MCP tools | `/admin/mcp-tools` | | |
| A23 | Agent activity | new (from audit logs) | `AgentActivity` (filter by agent), `Timeline`, `UndoBar` | |
| **Documents** | | | | |
| A30 | Document library · document | `/admin/document-library`, `/[id]` | `DocPreview`, `Drawer` | |
| A31 | Document templates | `/admin/document-templates` | | |
| A32 | Route templates · routing | `/admin/document-route-templates`, `/admin/document-routing/[documentId]` | | |
| A33 | Document designer | `/admin/documents` (editor) | `TplDocDesigner`; the Document editor cards (section 18) | the editor, built from `handoff/editor/` |
| A34 | Forms · public form | `/forms/[id]`, `/f/[slug]` | | |
| **Platform** | | | | |
| A40 | Tenants · tenant | `/admin/tenants`, `/[id]` | (table in `TplAdminDashboard`), `TenantSwitcher` | |
| A41 | Plugins · store | `/admin/plugins`, `/admin/plugins/store` | `PluginCard` | |
| A42 | Billing · plans | `/admin/billing`, `/billing` | | |
| A43 | Promotions | `/admin/promotions` | | |
| A44 | Audit logs · errors · stats | `/admin/audit-logs`, `/admin/errors`, `/admin/stats` | | |
| A45 | Languages · translations | `/admin/languages`, `/admin/translations` | | |
| A46 | Tags · tag groups | `/admin/tags`, `/admin/tag-groups` | | |
| A47 | Notifications | `/admin/notifications` | | |
| A48 | Approval gating | `/admin/approval-gating/*` | | |
| **Settings** | | | | |
| A50 | Branding | `/admin/settings/branding` | `TplAdminBranding` | |
| A51 | Email · email domains | `/admin/settings/email`, `/email-domains` | | |
| A52 | Security · SSO · sign-up | `/admin/settings/security`, `/sso`, `/signup` | | |
| A53 | Storage · feature flags · error tracking | `/admin/settings/storage`, `/feature-flags`, `/error-tracking` | | |
| **Account** | | | | |
| U01 | Sign in · register · verify email | `/login`, `/register`, `/verify-email` | `TplAuthSignIn` | |
| U01b | Two-step code · turn on · recovery codes | `/login` (step 2), `/settings` | `TplAuthTwoStep` | |
| U02 | Forgot · reset password · recovery | `/forgot-password`, `/reset-password`, `/account-recovery` | `TplAuthReset` | |
| U03 | Accept invitation · onboarding | `/accept-invitation`, `/onboarding` | `TplAuthInvite` | |
| U04 | Settings · licensing | `/settings`, `/licensing` | | |
| U05 | Status | `/status` | `TplSystemStates` | |
| U06 | 404 · 403 · 500 · offline · maintenance · rate limit | `not-found`, `error`, middleware | `TplSystemStates` | |
| **Partner apps (built on Whity)** | | | | |
| P01 | Front Office: invoices | plugin route | `TplPartnerInvoices`, `DataTableSelect` | Front Office app, JoFotara integration |
| **Documents (Whity Render)** | | | | |
| R01 | Quote | Whity Render | `TplDocQuote` | online acceptance |
| R02 | Tax invoice (English or Arabic leads, continuation) | Whity Render | `TplDocInvoice`, `TplDocInvoiceAr`, `TplDocInvoiceLong` | JoFotara QR and reference |
| R03 | Credit note | Whity Render | `TplDocCreditNote` | JoFotara |
| R04 | Receipt (A5) | Whity Render | `TplDocReceipt` | |
| R05 | Statement of account | Whity Render | `TplDocStatement` | |
| R06 | Payment reminder | Whity Render | `TplDocReminder` | agent sending with approval |
| R07 | Delivery note | Whity Render | `TplDocDeliveryNote` | |
| R08 | Payslip | Whity Render | `TplDocPayslip` | HR app |
| R09 | Employment letter | Whity Render | `TplDocEmploymentLetter` | HR app, verification page |
| R10 | Leave approval | Whity Render | `TplDocLeaveApproval` | HR app, audit log |
| R11 | Report | Whity Render | `TplDocReport` | |
| R12 | Letter | Whity Render | `TplDocLetter` | |
| R13 | Purchase order | Whity Render | `TplDocPurchaseOrder` | |

Next templates to draw: agent notifications (email, push, WhatsApp), the tenant table (A40), the plugins store (A41), the mobile approval screen, and the 80 mm thermal receipt. The P2 components exist since 2.1 (section 17), so the dashboard, onboarding (stepper), import (file upload) and plan screens can be drawn from them.
