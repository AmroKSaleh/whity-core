# Changelog fragments

Don't edit `CHANGELOG.md` in a pull request. Add a fragment here instead, one
file per change. Parallel PRs then never conflict on the changelog.

## Name

`<id>.<section>.md`, for example `WHIT-789.added.md`.

- **id** is the WHIT task, or anything unique (`WHIT-789-2` for a second entry
  from the same task, or the PR number for a change without a task).
- **section** is one of `added`, `changed`, `deprecated`, `removed`, `fixed`,
  `security`, as in [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Content

One or more list items, written the way `CHANGELOG.md` entries are: what
changed for the person reading the release page, and why it matters. No
headings; the section comes from the file name.

```markdown
- **The workspace main colour is stored on the server.** Set it once on the
  Branding screen and the app theme follows it in light and dark mode (WHIT-789).
```

## What happens at release

`release-prepare.yml` runs `php scripts/changelog-assemble.php`. It folds every
fragment into `## [Unreleased]` in Keep a Changelog order, deletes the
fragments, and the section is then dated as the release notes. CI runs
`php scripts/changelog-assemble.php --check` on every PR, so a misnamed or empty
fragment fails there.
