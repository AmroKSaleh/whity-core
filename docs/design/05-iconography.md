# Iconography

**Tabler outline icons** (MIT), the set the product already uses through `@tabler/icons-react`: a 24px grid, round caps and joins, drawn in `currentColor`. This system curates 116 of them under Whity's own names, so screens and docs use the same word for the same thing.

## Size and weight

| Size | Stroke | Where |
| --- | --- | --- |
| 16px | 2.25 (renders 1.5px) | The default, beside 14px text: buttons, navigation, table rows, menus |
| 20px | 2 | Touch navigation, cards |
| 24px | 2 | Page headers, empty states |
| 12–14px | 2.25 | Inside badges, chips and metadata |

The 16px stroke is thicker on purpose: it renders at the same optical weight as 20 and 24px (decision 5.2). `Icon` sets it from the size.

## Names

Whity's words map to one icon each: `tenant` (building), `role` (shield-lock), `permission` (key), `delegation` (user-share), `ai-principal` and `robot` (robot face), `approval` (rubber stamp), `scope` (lock-access), `routing` (route), `inbox`, `plugin` (puzzle), `tool` (MCP tools), `audit` (history), `invoice`, `quote`, `receipt`, `money`. The document editor adds 28 (2.2): `bold`, `italic`, `underline`, `strike`, `code`, `highlight`, `heading-1` to `heading-3`, `list-bullet`, `list-numbered`, `list-todo`, `blockquote`, `table`, `image`, `columns`, `divider`, `page-break`, `field` (braces), `comment`, `comment-add`, `drag` (grip), `redo`, `text`, `outline`, `suggest`, `page` and `continuous`. The full list, with each Tabler name, is in `handoff/icons/icons.json` and on the Icon card.

## Agents

An agent is always Tabler's **robot face**, in the main colour on the tint (decision 5.1), in its avatar, actor chip and the AI principals screen. Never sparkles, brains, glowing effects or illustrated robots.

## Direction

`chevron-left`, `chevron-right`, `arrow-right`, `send`, `undo`, `external` and `logout` mirror in right-to-left layouts; `Icon` does this by itself (`mirror: false` stops it). The mark never mirrors.

## Rules

- An icon beside a word repeats it; an icon alone needs a label (`IconButton` takes `label`, which is also its tooltip).
- Don't mix in other libraries. A missing icon comes from Tabler's outline set first; anything drawn new follows the same grid, stroke and corners.
- Icons are never coloured for decoration: they take the text colour, or a status colour when they show a status.

Files: `handoff/icons/ink/*.svg` (for light grounds), `handoff/icons/paper/*.svg` (for dark grounds), `icons.json`, and Tabler's licence.
