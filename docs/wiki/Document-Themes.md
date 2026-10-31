# Document themes

A document theme decides how a document looks in the editor's page view and
in the PDF Whity Render prints: the print colour, whether headings take it as
well as the title, and which logo and lines the letterhead shows. Everything
else about a document's look (type, sizes, margins, rules, greys) follows the
decision register and is not themeable. Design: `docs/design/DOCUMENT-THEMES.md`;
register E.44 and E.45.

## Where a document's look comes from

Each layer wins over the one before:

1. **Whity's standard theme** (`whity.standard`, in code). Its print colour is
   the workspace main colour (Branding, 2.8), or Whity Blue without one.
2. **A workspace theme** (`tenant.<key>`), when the document names one or a
   workspace default points at it. It sets only the tokens it wants.
3. **The document's own overrides**: the print colour and the letterhead only.

Which theme a document uses: the one it names, else the workspace's default for
its kind (`documents.theme_by_kind`), else the workspace default
(`documents.theme`), else Whity's standard theme. A theme the workspace doesn't
have falls back down that list, and the publish check refuses to publish a
template that names one.

The print colour must be readable on white (4.5:1). One that isn't is darkened
until it is, and the resolution says so in `adjustments`.

## Tokens

| Token | Values | Default |
| --- | --- | --- |
| `accent` | `#rrggbb` | the workspace main colour, else Whity Blue |
| `accentOnDark` | `#rrggbb` | derived from `accent` (2.8b); the editor's sheet in dark mode only, never printed |
| `accentUse` | `title`, `title-and-headings` | `title` |
| `letterhead.logo` | `wide`, `square`, `none` | `wide` |
| `letterhead.lines` | up to four of `address`, `taxId`, `phone`, `email`, in order | `address`, `taxId` |

`phone` and `email` print nothing until the workspace's company details have
them (WHIT-788). The title face and large print come with M8 (WHIT-786).

## Published means frozen

Publishing a template stores its resolved theme with the version. Printing the
version, issuing a document from it and correcting an issued document all use
that frozen theme and never resolve again, so changing a theme reaches a
published template only when someone publishes it again. A draft preview uses
the theme as it resolves now. Templates published before themes existed print
with the current resolution.

## Managing themes

- `GET /api/document-themes` (with `scope` and `archived`),
  `GET /api/document-themes/schema` (the tokens with English and Arabic labels),
  `GET /api/document-themes/{ref}`, `GET /api/document-themes/{ref}/usage` and
  `POST /api/document-themes/resolve` need `documents:read`.
- `POST /api/document-themes`, `PATCH /api/document-themes/{ref}` (send
  `If-Match` with the theme's version: 428 without, 409 when stale),
  `POST …/archive`, `POST …/restore` and `DELETE` need `documents:themes`.
  A theme in use can't be deleted (409 with where it is used); archive it.
- The workspace defaults are the settings `documents.theme` and
  `documents.theme_by_kind`, written through `PATCH /api/settings` with
  `settings:write` **and** `documents:themes`.
- A document's theme is part of its draft: `theme: { ref, overrides }` on
  `PUT /api/document-sources/{id}/draft`, and every source response carries
  `resolvedTheme`.

`documents:themes` is granted on upgrade (migration 172) to every role that
holds `documents:publish`. The old `documents.print_color` setting is gone:
migration 173 moved it into a workspace theme named `workspace`, made the
default. That theme pins the print colour, so documents keep their look but no
longer follow the workspace main colour. To follow it again, clear the accent
on the `workspace` theme, or set the default back to `whity.standard`.

The theme picker and the Document themes screen come once their designs exist
(WHIT-736). Plugin themes come with the M7 SDK (WHIT-738).
