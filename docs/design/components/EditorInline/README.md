# Inline tools

What acts on text inside a block (part 4).

- **Selection bubble** (Notion): appears above a text selection after a short pause: bold, italic, underline, strikethrough, code · link, highlight · comment · **Rewrite** (asks an agent; what comes back is a suggestion, E.4). In Viewing mode it shows Comment only; in Suggesting mode formatting changes are suggestions too.
- **Links:** ⌘K with text selected, or the bubble, opens an address field at the selection (with no selection ⌘K opens the command palette, E.36); a bare domain gets `https://`. Hovering a link shows its address with copy, edit and remove. Links to Whity records (an invoice, a customer) show the record's name.
- **Highlight:** one colour, the sky series at 40% (E.25), so colour never carries meaning on paper.
- **Merge fields:** a chip on the tint with braces, the field's plain name ("Customer name"). Hover shows its path (`customer.name`); it's selected and deleted as one character; arrow keys step over it. In preview it shows the record's value with a dotted underline; a missing value shows on the error tint with an alert icon; Whity Render leaves an optional field blank and won't issue any document with a required field missing (fiscal fields are always required, E.27).
- **Field picker:** Field ▾ in the toolbar or typing `{{` opens a searchable list grouped by record (Customer, Invoice, Statement, Company, Today), each with its sample value. Enter inserts; the fields offered depend on the template's record type.
