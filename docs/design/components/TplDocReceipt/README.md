# Receipt

An A5 receipt for money received. The amount comes first, large, with the amount in words in both languages, then what it paid: the invoice, its total, any credit, and what was paid now, with the balance left on the account. It ends with who received it and a signature line. A receipt is not a tax document, so there is no JoFotara stamp; the QR lets the customer check the receipt online.

A5 follows the A4 rules (same 16 mm margins and type sizes). Here Example Clinic pays the JOD 203.000 still due on INV-1042 after credit note CN-0012. Built from `Doc` (`size: 'A5'`), `DocAmount` and `DocSign`. 80 mm thermal receipts get their own template later.
