import type { WhityDocument } from "./contract"

const t = (text: string) => ({ type: "text" as const, text })

/** Example data for stories and tests: Example Bakery, fictional (CLAUDE.md). */
export const SAMPLE_DOCUMENT: WhityDocument = {
  id: "doc-payment-reminder",
  kind: "note",
  title: "Payment reminder",
  lang: "en",
  defaultView: "continuous",
  blocks: [
    { id: "b1", type: "paragraph", content: [t("Dear Example Clinic,")] },
    {
      id: "b2",
      type: "paragraph",
      content: [
        t("Invoice INV-1042 for JOD 301.600 was due on 14 Oct 2026. "),
        { type: "text", text: "Please pay within 14 days", marks: [{ type: "bold" }] },
        t(" of this letter, by bank transfer or "),
        { type: "text", text: "online", marks: [{ type: "link", href: "https://example.jo/pay/INV-1042" }] },
        t("."),
      ],
    },
    { id: "b3", type: "heading", level: 1, content: [t("Open invoices")] },
    { id: "b4", type: "bullet", level: 0, content: [t("INV-1042, due 14 Oct 2026, JOD 301.600")] },
    { id: "b5", type: "bullet", level: 0, content: [t("INV-1051, due 21 Oct 2026, JOD 96.000")] },
    { id: "b6", type: "heading", level: 2, content: [t("Before you send it")] },
    { id: "b7", type: "checklist", level: 0, checked: true, content: [t("Check the amounts against the statement")] },
    { id: "b8", type: "checklist", level: 0, checked: false, content: [t("Ask Rana to approve the wording")] },
    { id: "b9", type: "quote", content: [t("If you have already paid, thank you, and please ignore this letter.")] },
    { id: "b10", type: "paragraph", content: [t("Kind regards,")] },
    { id: "b11", type: "paragraph", content: [t("Rana Haddad"), { type: "break" }, t("Example Bakery")] },
  ],
  comments: [],
  suggestions: [],
  status: "draft",
  updatedAt: "2026-10-29T14:02:00Z",
  updatedBy: { kind: "person", id: "u-rana", name: "Rana Haddad" },
}
