# Internationalization — extracting strings without a parallel source of truth

Every user-facing string in this product goes through `t()`, and the English
catalogue is **derived from the code that renders it**. There is no separate
`en.json` a human edits, because the second argument of every call site already
holds the English text:

```tsx
const t = useTranslation('auth');

<Button>{t('login.submit', 'Sign in')}</Button>
```

That one property is what the whole pipeline is built on. It means:

- the screen still reads normally in a diff, and renders correctly before the
  translation bundle arrives, or if a key was never seeded;
- English can never drift from the code, because it *is* the code;
- an extraction effort can fan out across many people at once without any two of
  them editing the same list of strings.

---

## The pipeline

```
   web/**, packages/**            database/i18n/<domain>.json          translations table
   ───────────────────            ───────────────────────────          ─────────────────
   t('login.submit', 'Sign in')  →   { "login.submit": "Sign in" }  →   en row, tenant_id NULL
        ↑ you write this            ↑ i18n:extract writes this          ↑ i18n:sync writes this
                                    ↑ CI fails if it drifts             ↑ NEVER overwrites
                                                                             ↓
                                                                     /admin/translations
                                                                     ↑ a human writes `ar`
```

Three commands, in this order:

```bash
php bin/whity-cli i18n:extract          # source  → database/i18n/<domain>.json
php bin/whity-cli i18n:extract --check  # verify only (what CI runs)
php bin/whity-cli i18n:sync             # catalogue → the translations table (English)
php bin/whity-cli i18n:sync --dry-run   # report what it would insert
php bin/whity-cli i18n:sync --language=ar   # database/i18n/ar/ → the same table
php bin/whity-cli i18n:sync --all       # English and every committed language
php bin/whity-cli i18n:coverage         # per-domain translated/missing, no database
```

Nothing ever flows backwards. The catalogue is a **mirror** of the code — a key
that leaves the source leaves the file, and CI insists on it. The database is an
**accumulator** — it only ever gains rows, because what is in it is human work.

### Why a committed file at all, if it is derived?

1. **The backend cannot see the frontend.** `.dockerignore` keeps `web/` out of
   the release image, and rightly so — the backend serves no TSX. But the
   command that seeds the strings runs *inside* that image. `database/` ships,
   so the catalogue is the vehicle that carries the strings across.
2. **It makes the strings reviewable.** "What English did this PR add or
   change?" is one file diff, not a re-read of every screen.
3. **It gives CI something to compare** with no database and no network — the
   same shape as the `public/openapi.json` drift check.

### Why one file per domain

Because that is what lets the work fan out. Two people converting two areas write
two different files and never conflict; a single catalogue would put every one of
them in the same merge. A plugin domain (`acme:catalog`) is stored as
`acme__catalog.json` — `:` is not a legal filename character on Windows.

---

## Converting a screen

The reference conversions are `web/app/login/page.tsx` (the first) and
`web/app/(protected)/admin/translations/*` (the second, done entirely through
this tooling). Read either one.

**1. Pick the domain.** One domain per broad AREA, named for the area. Core
domains are bare (`auth`, `admin`, `common`); a plugin's carries its source slug
(`acme:catalog`). The rule lives in exactly one place,
`src/Core/i18n/TranslationDomain.php`, and every read and write path validates
through it.

**2. Name keys for the PLACE, not the words.** `login.email.label`, never
`enter_your_email`. Rewording copy must never require renaming a key, because a
rename orphans that string in every other language at once. Keys are
dot-delimited paths whose segments start lowercase; the extractor rejects
anything else.

**3. Pass the English text as the fallback, inline, always.** It is what renders
before the bundle arrives, what renders if a key was never seeded, what a
reviewer reads in the diff — and the only place the English catalogue comes
from. A `t()` call with no fallback fails the guard.

**4. Keep sentences whole, with `{placeholders}`.** Never assemble a sentence by
concatenating fragments; word order differs between languages, and a
translator cannot reorder pieces they never see together.

```tsx
// wrong — three fragments in English order
<p>{t('welcome')} {siteName}, {t('choose')}</p>

// right — one translatable unit with a hole in it
<p>{t('login.welcome', 'Welcome to {site}, choose a workspace', { site: siteName })}</p>
```

**5. Style with LOGICAL CSS utilities** (`ms`/`me`, `ps`/`pe`, `start`/`end`,
`text-start`) so the layout follows the writing direction automatically. Never
branch on a language code to decide direction — read `useLanguageDirection()`.

**6. Regenerate and seed:**

```bash
php bin/whity-cli i18n:extract     # commit the changed database/i18n/*.json
php bin/whity-cli i18n:sync        # inserts the new keys as en system defaults
```

**7. Do NOT write Arabic** (or any other language). See below.

---

## Keys a scanner cannot see

A static extractor reads text; it does not evaluate JavaScript. These are
invisible to it:

```tsx
t(entry.key, entry.fallback)          // key comes from a lookup table
t(`status.${row.state}`, '…')         // key is built at runtime
```

The extractor does **not** skip these quietly. It cannot read the key, but it
*can* see that a key is being hidden, and it fails until the file says what is
there. Two ways to answer, and you must pick the one that is TRUE.

### Declare the keys — `@i18n-keys`

When the possible keys are a finite set the code already knows (a lookup table,
a union of literals), list them in a comment anywhere in the file. The extractor
takes the catalogue entries from the block:

```tsx
/**
 * @i18n-keys auth
 *   sso.error.denied = Sign-in was cancelled.
 *   sso.error.failed = Sign-in failed. Please try again.
 */
const SSO_ERROR_KEYS: Record<string, { key: string; fallback: string }> = { … };
```

The block is `@i18n-keys <domain>`, then one `key = English text` per line, until
a blank line or another `@` tag. It also satisfies rule 3 above: a key declared
here does not need a fallback at the call site.

### Acknowledge that they cannot be enumerated — `@i18n-dynamic-ignore:`

When the keys genuinely do not exist in code — they come from the database, from
tenant data, from a plugin — record why:

```tsx
// @i18n-dynamic-ignore: field labels are tenant data, not source strings
```

**A reason is mandatory.** A decision with no reason recorded is
indistinguishable from a silenced alarm — the same doctrine as
`@tenant-guard-ignore:`.

Both tags are **file-scoped**, which is deliberately coarse: the declaration
usually sits on the lookup table forty lines above the call, and a rule that
demanded adjacency would push authors to duplicate it. The cost is that a
*second* dynamic call added to an already-declaring file raises no new alarm.
These files are small and the declaration is right there in the diff.

### How the extractor knows which domain a call belongs to

1. `const t = useTranslation('auth')` binds the name — every `t(...)` in the
   file is `auth`.
2. A helper that takes the function as a parameter (`f(t: TranslateFn)`)
   inherits the file's domain, **provided the file has exactly one**.
3. Anything else — two domains plus a helper, or a domain that is itself a
   variable — is reported, never guessed.

---

## English is generated. Other languages are written by hand.

`i18n:sync` never machine-translates, and it never copies English into another
language's rows. That has not changed and must not: a row containing English
text but claiming to be Arabic is indistinguishable from a finished translation
— to the coverage report, to the console, and to the reviewer deciding what is
left. An untranslated key must be *visibly* untranslated, which means having no
row at all.

What HAS changed is where a translation lives before it reaches the database.

```
database/i18n/<domain>.json        English. GENERATED from t() call sites.
                                   Never hand-edited. CI fails if it drifts.

database/i18n/<code>/<domain>.json Everything else. HAND-WRITTEN.
                                   Never generated. Partial by design.
```

Until that second path existed, English was the only language that **shipped**.
The catalogue carried English into the release image and `i18n:sync` seeded it;
Arabic was expected to be typed into `/admin/translations` by a human, per
deployment, after install. The predictable result was that every deployment
started English-only and stayed that way, and six of the seven domains had no
Arabic at all — not because nobody would write it, but because there was
nowhere to **commit** it. A translation that cannot be committed cannot be
reviewed, cannot ship in the image, and does not survive a database rebuild.

Locale catalogues live one directory DOWN rather than beside the English, and
that is load-bearing: `TranslationCatalog::write()` mirrors the source and
therefore *prunes files it did not expect*. `is_file()` is what makes `ar/`
invisible to the generated half of the pipeline, so one ordinary `i18n:extract`
cannot delete every translation anyone has ever written. A unit test pins it.

Migration `120_seed_translation_catalogues` seeds every committed catalogue at
`migrate run`, because that is the one step every install performs and nothing
ever ran `i18n:sync` on its own.

The runtime is already built for this: the fallback chain resolves
tenant override → system default → English → the caller's fallback → the key, so
a half-translated language renders in English rather than breaking.

> Migration 091 seeded the sign-in screen in both `en` and `ar`, hand-written by
> a speaker of the language, when the whole scope was 60 strings on one screen.
> That does not scale to several hundred, and machine-translating them would be
> worse than leaving them empty: it would erase the distinction between "done"
> and "not started" for every string at once. Everything extracted from here on
> is English-only, and Arabic is filled in through the console.

### Filling the gap: `/admin/translations`

The console answers **"what still needs translating for language X"**:

- a **coverage panel** — every enabled language with `translated of total`, a
  count of what is missing, and a per-domain breakdown; the source language is
  labelled as the source rather than as completed work;
- clicking a domain with a gap loads exactly the outstanding keys;
- the listing includes keys that have **no row in the selected language at all**
  (they are the work), each with an **English source** column showing the text
  being translated FROM;
- an **Only untranslated** filter.

`translations:manage` gates the screen. The system tenant (id 0) edits the
system defaults; a regular tenant edits its own per-tenant overrides.

---

## The CI gate

`scripts/ci-i18n-catalog-drift.php` runs as its own job (`i18n-catalogue`) with
its own path filter. It deliberately does not ride on the `backend` filter,
which would skip it on the frontend-only PRs that produce almost all of the
drift, nor on `frontend`, which is a Node job with no PHP.

It fails when:

| | why |
|---|---|
| a key in code is missing from the catalogue | it will never be seeded, so it will never reach a translator — and nothing else in the system would ever have noticed |
| the catalogue holds a key no source references | the catalogue is a projection of the code; if the key really is gone, regenerating removes it, and if it is not, the scan is broken |
| the English text differs between code and catalogue | someone edited the JSON by hand instead of the call site |
| a dynamic call site has no declaration and no reasoned ignore | the keys are invisible and nobody has said what they are |
| a `t()` call has no English fallback | there is no source text to seed |
| the same key carries two different English strings | one key is one string — the second wording needs its own key |

**Dead keys in the DATABASE warn; they do not fail.** They are a different thing
from a stale catalogue entry, and the distinction is the point:

- the catalogue is regenerated from code, so a removed call site removes the
  entry — a stale entry there is a real inconsistency and fails;
- a *row* in the translations table is expected to outlive its call site. A
  refactor removes a screen for a week, and the row still holds the Arabic
  somebody wrote; a plugin, an email template or an admin using **Add Key**
  creates rows that no frontend source was ever going to reference. Deleting on
  the strength of a scan would make a rename indistinguishable from data loss.

So `i18n:sync` reports dead keys and domains it does not manage, and removes
nothing. CI has no database to ask in any case.

---

## Never overwriting a translation

The single most important property of `i18n:sync`, because the failure mode is
silent and unrecoverable: a translator spends a week filling in Arabic, someone
runs the sync, and it is gone with nothing in the diff to notice.

`src/Core/i18n/TranslationSync.php` therefore **contains no `UPDATE` and no
`DELETE` statement at all** — a unit test asserts that about the class's own
source text, so the guarantee is structural rather than a promise about a
`WHERE` clause. Concretely:

- rows that already exist are never written to, in any language, in any scope;
- an English string a human has edited in the console **stays edited** — the
  sync reports it as divergent from the source and moves on;
- an insert is `INSERT … WHERE NOT EXISTS`, not `ON CONFLICT`: the unique index
  is `(language_id, domain, key, tenant_id)` and these rows carry a NULL
  `tenant_id`, which both PostgreSQL and SQLite treat as distinct from every
  other NULL, so the constraint would never fire and a replay would silently
  duplicate every string;
- running it twice therefore changes nothing the second time.

---

## What the CI gate refuses, and what it only counts

`scripts/ci-i18n-catalog-drift.php` guards both halves, but it cannot ask the
same question of each. English is a projection of the code, so the only
meaningful question is whether the projection is current. A hand-written
language has nothing to regenerate from, and its honest answer to "is it
complete?" is usually *no* — English gains a key the instant a developer writes
one, and the translation follows in a later PR.

So it **fails** on what is decidable:

| failure | why it is a failure and not a warning |
|---|---|
| catalogue drift | the English file no longer matches the `t()` calls that produce it |
| an unreadable key | a computed key no scanner can enumerate, with no `@i18n-keys` block and no recorded reason |
| an **orphan** | a key translated in `ar/` that English no longer has. This is what a rename leaves behind, and it is the one failure that *looks like progress*: the file gets longer, the coverage percentage goes **up**, and the screen stays English |
| an **empty** translation | renders as an empty string — it does **not** fall back to English, so it is strictly worse than leaving the key out |
| a non-canonical locale file | unsorted or reformatted, which buries the next real change in noise |

and it **reports, never fails,** on missing keys. A gate demanding every
language be complete would mean no English string could be added without a
translator in the same PR, and the practical effect of that rule is that people
stop calling `t()` at all.

`whity-cli i18n:coverage` prints the same per-domain numbers from files alone —
no database, so it runs in CI, in a container, and on a laptop with the stack
down. That matters more than it sounds: the reason six domains had no Arabic for
as long as they did is that the gap was not a number anybody could see.
"Translate everything" has no finish line; `documents 0/508` has one.

---

## Files

| path | what it is |
|---|---|
| `src/Core/i18n/TranslationKeyExtractor.php` | the scanner: source → keys, and the diagnostics for what it cannot see |
| `src/Core/i18n/TranslationCatalog.php` | the committed catalogue: read, write, diff |
| `src/Core/i18n/TranslationSync.php` | catalogue → database, insert-only |
| `src/Cli/Commands/I18nCommand.php` | `i18n:extract`, `i18n:sync`, `i18n:coverage` |
| `scripts/ci-i18n-catalog-drift.php` | the CI gate |
| `database/i18n/*.json` | the English catalogue — generated, committed, never hand-edited |
| `database/i18n/<code>/*.json` | one language each — hand-written, committed, never generated |
| `database/migrations/120_seed_translation_catalogues.php` | seeds every committed catalogue at `migrate run` |
| `packages/features/src/i18n/` | the React side: `useTranslation`, the provider, direction |
| `docs/wiki/Internationalization.md` | this page |

Direction, language switching, caching and the provider contract are documented
in `packages/features/src/i18n/README.md`.
