# Stepper

Where a person is in a flow of 3 to 6 steps: setting up a workspace, importing a file, onboarding a partner. Done steps are a check on the main colour with a line in the main colour after them; the current step is ringed on the tint and its label is 600; steps ahead are grey.

**Props:** `steps` (`[{label, hint?}]`), `current` (index), `orientation` (`horizontal` or `vertical`), `label`, `labels.step` ("Step {n} of {total}").

Under 600px the horizontal stepper keeps the dots and shows "Step 3 of 4 · Team" above them. It mirrors in Arabic. Steps aren't links by default: people move with the form's own Back and Continue.
