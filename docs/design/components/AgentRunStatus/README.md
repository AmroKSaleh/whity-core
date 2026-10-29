# AgentRunStatus

What an agent is doing now, in plain words (stage 7): **Running** ("Reading 48 open invoices", with progress when the count is known and Stop), **Waiting for approval** (dashed, with Review, because nothing has happened yet), **Done** (what it did and how long it took) and **Failed** (what went wrong and what to do next, with Try again).

**Props:** `status` (`running` · `waiting` · `done` · `failed`), `agent`, `onBehalfOf`, `title`, `progress` (`{done, total}`), `detail`, `next` (failed only), `time`, `onStop`, `onReview`, `onRetry`, `actions`, `compact` (a pill for the top bar or a table cell), `labels`.

The agent comes first, then who it acts for, then the action. Status is carried by the word and the icon, never colour alone. A failure never says "error" without a next step.
