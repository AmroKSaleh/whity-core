# Button

The filled `default` button is the one thing a view is for, and the only button that sits on the plane (`plane-primary-rest`, lifting to `plane-primary-hover`).

**Variants:** `default` once per view · `outline` the second action · `secondary` low emphasis · `ghost` Cancel and toolbars · `destructive` deletes and irreversible actions · `link` navigation that reads as text. **Sizes:** `sm` 28px for toolbars and tables · `default` 36px · `lg` 40px for a single call to action (decision 4.1); corners are ¼ of the height. **Props:** `icon`, `iconEnd`, `loading`, plus native button attributes.

Verb first, sentence case, say what happens: “Send invoice”, never “Submit”. Hand-written from `packages/ui/src/button.tsx`; its Tailwind-palette colour variants are left out until they move to tokens.
