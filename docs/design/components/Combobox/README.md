# Combobox

A select you can type into, for long lists (customers, products, accounts). It filters on the label and the hint, moves with the arrow keys, picks with Enter and closes with Escape. The selected option is tinted (7.3). Empty says what was searched for; `loading` shows a spinner while results come from the server (`onQuery` fires on each keystroke). A footer can offer to create the missing record.

In whity-core this maps to shadcn's Command inside a Popover; this is the look and behaviour to match.
