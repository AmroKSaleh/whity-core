# i18n — Internationalization Hooks

Frontend internationalization (i18n) support for Whity Core applications. Provides React hooks and context for managing language switching and translations with client-side caching via localStorage.

## Features

- **Language Management**: Switch between available languages with one hook call
- **Direction Follows the Language**: each language carries its own `'ltr'`/`'rtl'`, so choosing Arabic mirrors the interface — there is no separate direction toggle, and no code branches on a language code
- **The Whole Surface Is Flaggable**: an operator can switch i18n off entirely (`i18n.enabled`), and the product becomes single-language, left-to-right, with no language affordance anywhere — without losing a single stored preference or translation. See [Switching i18n Off](#switching-i18n-off)
- **Lazy Domains**: asking for a domain is what loads it; there is no central list to maintain
- **Translation Caching**: LocalStorage caching with 24-hour TTL reduces API calls
- **Bilingual Support**: Multiple languages cached simultaneously for instant switching
- **Fallback Chain**: Automatic fallback chain (translation → supplied English fallback → key)
- **Type-Safe**: Full TypeScript support with types for all API responses
- **No External Dependencies**: Uses standard React hooks and Web APIs

## Architecture

```
┌─────────────────────────────────────────┐
│   LanguageProvider (Context Root)       │
│  - Fetches available languages          │
│  - Fetches user's language preference   │
│  - Manages translation state            │
└──────────────────┬──────────────────────┘
                   │
        ┌──────────┴──────────┐
        │                     │
    ┌───────────────┐  ┌──────────────┐
    │ useTranslation│  │useCurrentLang│
    │  (domain)     │  │   uage       │
    └───────────────┘  └──────────────┘
        │                     │
        └────┬────────────────┘
             │
      ┌──────────────────┐
      │ API Calls        │
      │ (with cache)     │
      └────────┬─────────┘
               │
        ┌──────┴──────┐
        │             │
    ┌─────────┐  ┌────────────┐
    │ Backend │  │ LocalStorage│
    │  API    │  │  Cache     │
    └─────────┘  └────────────┘
```

## Usage

### 1. Wrap Your App with LanguageProvider

```tsx
import { LanguageProvider } from '@amroksaleh/features/i18n'

export default function App() {
  return (
    <LanguageProvider defaultLanguage="en">
      <MainApp />
    </LanguageProvider>
  )
}
```

### 2. Use `useTranslation` Hook to Translate Strings

```tsx
import { useTranslation } from '@amroksaleh/features/i18n'

export function MyComponent() {
  const t = useTranslation('common')

  return (
    <>
      <h1>{t('page.title')}</h1>
      <button>{t('button.save')}</button>
      <span>{t('unknown.key', 'Default text')}</span>
    </>
  )
}
```

### 3. Use `useCurrentLanguage` Hook to Switch Languages

```tsx
import { useCurrentLanguage } from '@amroksaleh/features/i18n'

export function LanguageSwitcher() {
  const { currentLanguage, availableLanguages, setLanguage } = useCurrentLanguage()

  return (
    <select value={currentLanguage || 'en'} onChange={(e) => setLanguage(e.target.value)}>
      {availableLanguages.map((lang) => (
        <option key={lang.code} value={lang.code}>
          {lang.name}
        </option>
      ))}
    </select>
  )
}
```

### 4. (Optional) Use the Built-in Language Switcher Component

```tsx
import { LanguageSwitcher } from '@amroksaleh/features/i18n'

export function Header() {
  return (
    <header>
      <h1>My App</h1>
      <LanguageSwitcher variant="dropdown" />
    </header>
  )
}
```

## API Endpoints

The i18n system uses the following backend API endpoints:

### GET /api/v1/languages
Public endpoint (no auth required) that returns available languages. Each record
carries its `direction` — the interface writing direction that language implies.
`i18n_enabled` is the instance's `i18n.enabled` flag; it rides on this call
because it must be known before a session exists (the sign-in screen mounts the
provider too).

**Response:**
```json
{
  "languages": [
    { "code": "en", "name": "English", "direction": "ltr" },
    { "code": "ar", "name": "العربية", "direction": "rtl" }
  ],
  "i18n_enabled": true
}
```

The `languages` array is **not** narrowed when the flag is off — it is the
catalogue, and the admin translations screen reads it to prepare a language
before the feature is switched on.

### GET /api/v1/settings/language
Authenticated endpoint that returns the user's EFFECTIVE language preference.
While `i18n_enabled` is `false` that is `null` (= the default language) whatever
the profile stores; the stored value is untouched and comes back the moment the
flag is switched on again.

**Response:**
```json
{
  "language_code": "ar",
  "available_languages": [
    { "code": "en", "name": "English", "direction": "ltr" },
    { "code": "ar", "name": "العربية", "direction": "rtl" }
  ],
  "i18n_enabled": true
}
```

### PATCH /api/v1/settings/language
Authenticated endpoint to update user's language preference. **Refused with 503
while `i18n.enabled` is off** — storing a preference nothing would honour is a
silent no-op, and the refusal is what guarantees no stored language is
overwritten while the feature is disabled.

**Request:**
```json
{
  "language_code": "ar"
}
```

**Response:**
```json
{
  "language_code": "ar"
}
```

### GET /api/v1/translations/{language_code}/{domain}
Authenticated endpoint that returns translations for a specific language and domain.

**Response:**
```json
{
  "translations": {
    "button.save": "Save",
    "button.cancel": "Cancel",
    "page.title": "My Page"
  }
}
```

## LocalStorage Caching

Translations are automatically cached in localStorage with the following key format:

```
i18n_translations_{language_code}_{domain}
```

Example:
- `i18n_translations_en_common` → English translations for the "common" domain
- `i18n_translations_ar_email` → Arabic translations for the "email" domain

### Cache Entry Structure

```typescript
{
  version: 1,
  timestamp: 1628000000000,
  data: {
    "button.save": "Save",
    "button.cancel": "Cancel"
  }
}
```

### Cache TTL

Cache entries expire after **24 hours**. On expiry, fresh data is fetched from the API.

### Bilingual Support

Multiple language caches can coexist simultaneously in localStorage. This allows bilingual users to switch instantly between languages without refetching.

Example:
- User logs in with preference "en"
- `i18n_translations_en_common` is cached
- User switches to "ar"
- `i18n_translations_ar_common` is cached (en cache remains)
- User switches back to "en" → instant load (no API call)

## Fallback Chain

When translating a key, the system uses this fallback chain:

1. **Tenant Override** (if user is in a tenant with custom translations)
2. **System Default** (the canonical translation)
3. **English Fallback** (if translation not in requested language)
4. **Key Itself** (if no translation found anywhere)

Example:
```
t('button.save')
→ Tenant override for 'button.save' in French? No
→ System default for 'button.save' in French? Yes → return "Enregistrer"

t('unknown.key')
→ System default for 'unknown.key' in French? No
→ System default for 'unknown.key' in English? No
→ Return "unknown.key"

t('unknown.key', 'Default text')
→ ... (same checks as above)
→ Return "Default text" (provided fallback)
```

## Hook Reference

### `useTranslation(domain: string)`

Returns a translation function for the specified domain.

Calling it also REGISTERS the domain, which is what loads that bundle.

**Parameters:**
- `domain` (string): Translation domain — bare for core (`'auth'`, `'common'`), namespaced for a plugin (`'acme:catalog'`)

**Returns:**
```typescript
(key: string, fallback?: string, vars?: Record<string, string | number>) => string
```

**Outside a `<LanguageProvider>` it does NOT throw** — it returns the fallback,
exactly as it does before a bundle has loaded. A translated component must stay
renderable in a unit test or a Storybook story without every one of them wiring
up a provider (and paying for the two network fetches it makes on mount, which
is how ordered fetch mocks desync). `useCurrentLanguage` still throws, since
switching the language genuinely needs the provider.

**Example:**
```tsx
const t = useTranslation('auth')
const submit = t('login.submit', 'Sign in')             // Translated, or 'Sign in'
const missing = t('missing.key')                        // Returns 'missing.key'
const hello = t('login.welcome', 'Welcome to {site}', { site: 'Acme' })
```

### `useCurrentLanguage()`

Returns current language info and language-switching function.

**Returns:**
```typescript
{
  currentLanguage: string | null      // Current language code (e.g., 'en', 'ar')
  availableLanguages: Language[]      // List of available languages
  isLoading: boolean                  // True while initializing
  error: Error | null                 // Error if initialization failed
  setLanguage: (code: string) => Promise<void>  // Switch language
}
```

**Throws:** Error if used outside `<LanguageProvider>`

**Example:**
```tsx
const { currentLanguage, setLanguage, availableLanguages } = useCurrentLanguage()

// Switch language
await setLanguage('ar')

// Render available languages — each carries its own writing direction
availableLanguages.forEach(lang => console.log(lang.code, lang.name, lang.direction))
```

Never branch on a language code to decide layout — read `useLanguageDirection()`
instead, so a language added later needs no code change.

### `useLanguageDirection()`

Returns `'ltr'` or `'rtl'` for the resolved language, read off the language
record. Non-throwing: `'ltr'` when no provider is mounted, and before a language
resolves.

```tsx
const dir = useLanguageDirection()
```

### `useI18nEnabled()`

Returns whether this instance offers a CHOICE of language (`i18n.enabled`).
Non-throwing, like `useLanguageDirection`: `false` when no provider is mounted,
and `false` until the catalogue has answered — no affordance is drawn until the
instance has said it offers one.

Read it wherever the interface would otherwise present a language affordance, so
that the surrounding chrome disappears with the control:

```tsx
const isI18nEnabled = useI18nEnabled()

{isI18nEnabled && (
  <div className="language-row">
    <GlobeIcon />
    <LanguageSwitcher variant="dropdown" />
  </div>
)}
```

It does **not** gate translation: `useTranslation` returns real text either way.

### `<LanguageProvider>`

Context provider that manages language state and translations.

**Props:**
- `children` (ReactNode): App content to wrap
- `defaultLanguage` (string, default: 'en'): Fallback language if user preference unavailable
- `identityKey` (string | number | null): An opaque handle for who is signed in
  (a profile id, or null). Changing it re-resolves the language from the new
  identity's profile. Without it, signing in — a client-side navigation — would
  leave the anonymous language in place until the next full page load. The
  provider takes a handle rather than reading an auth context so non-Next
  shells can supply their own notion of identity.

**Example:**
```tsx
<LanguageProvider defaultLanguage="en" identityKey={user?.id ?? null}>
  <App />
</LanguageProvider>
```

Language resolution order: **profile preference → the code remembered in
localStorage (for signed-out visitors) → `defaultLanguage`.**

### `<LanguageSwitcher />`

Optional pre-built component for switching languages.

**Props:**
- `variant` ('buttons' | 'dropdown', default: 'buttons'): UI style
- `className` (string, optional): CSS class for styling

**Example:**
```tsx
<LanguageSwitcher variant="dropdown" className="my-switcher" />
```

## Testing

Tests are provided in `__tests__/`:

```bash
# Run all tests
npm test

# Run i18n tests only
npm test -- i18n

# Run with coverage
npm test -- --coverage
```

### Test Utilities

Advanced users can import caching utilities for custom testing:

```typescript
import {
  getCachedTranslations,
  setCachedTranslations,
  clearLanguageCache,
  clearAllTranslationCaches,
} from '@amroksaleh/features/i18n'

// Clear cache before tests
beforeEach(() => {
  clearAllTranslationCaches()
})

// Manually cache translations for testing
test('respects cached translations', () => {
  setCachedTranslations('en', 'test', { 'key': 'value' })
  const cached = getCachedTranslations('en', 'test')
  expect(cached).toEqual({ 'key': 'value' })
})
```

## Browser Support

- Modern browsers with `localStorage` support (Chrome, Firefox, Safari, Edge)
- Gracefully degrades if localStorage is unavailable (private browsing, quota exceeded)
- Requires React 19+

## Error Handling

All API calls include automatic error handling:

```tsx
const { currentLanguage, error, setLanguage } = useCurrentLanguage()

if (error) {
  console.error('Language system error:', error.message)
  // App continues to work with default language
}

try {
  await setLanguage('ar')
} catch (err) {
  console.error('Failed to switch language:', err)
}
```

## Performance

- **Instant Language Switching**: Bilingual users get instant switches via localStorage
- **Lazy Translation Loading**: Translations loaded on-demand per domain
- **No Runtime Overhead**: Pure React hooks, no external state managers
- **Efficient Caching**: Only API calls if cache miss or expired

## Domain Naming

A **domain** is the bundle a set of keys belongs to, and the unit the client
fetches. There is exactly one naming rule, and it is enforced server-side by
`src/Core/i18n/TranslationDomain.php`:

- **core domains are BARE** — `auth`, `common`, `errors`, `email`
- **a plugin's are `<source-slug>:<slug>`** — `acme:catalog`

The separator and the reasoning are identical to `ResourceTypeRegistry`'s
`acme:record`: the prefix comes from the SOURCE the plugin loader supplies,
never from the plugin's own data. So two plugins both shipping a `catalog`
domain get different bundles and cannot overwrite each other's strings, and no
plugin can produce a bare key that shadows a core domain. Core stays unprefixed
because that is how `common`/`email`/`errors` are already stored.

**Keys inside a domain** are dot-delimited lowercase paths, named for the SCREEN
or feature rather than for the English text: `login.email.label`, not
`enter_your_email`. Rewording copy must never require renaming a key — a rename
orphans that string in every other language at once.

There is **no list of domains to register.** Calling `useTranslation('auth')` is
what loads `auth`; the provider fetches each (language, domain) pair once.
Converting a screen is a local change to that screen plus its seeded rows.

## RTL Support

**Direction is a property of the LANGUAGE, not a separate setting.** Every
language record carries `direction` (`'ltr'`/`'rtl'`, `languages.direction`), the
provider resolves it alongside the language, and the app sets `<html dir>` from
it (`web/lib/direction-context.tsx`). Choosing Arabic mirrors the interface;
choosing English un-mirrors it.

Nothing in this package — or in any consumer — tests a language CODE to decide
direction. Adding Hebrew, Farsi or Urdu is one row through the admin languages
API, not a code change. Read the current direction with `useLanguageDirection()`.

Style with LOGICAL CSS utilities (`ms`/`me`, `ps`/`pe`, `start`/`end`,
`border-s`/`border-e`, `text-start`/`text-end`) so components follow the
direction automatically.

## Switching i18n Off

A deployment that is not ready to ship a second language sets the operator flag
`i18n.enabled` to `false` (admin → Settings → Feature Flags, system tenant +
`settings:manage`). It defaults to **`true`**: i18n shipped before the flag did,
so an upgrade must never switch a live feature off underneath a deployment
already using it.

With the flag off:

| | Behaviour |
|---|---|
| Resolved language | `defaultLanguage` for everyone, whatever `profiles.language_code` says |
| `<html dir>` | `ltr`, pinned — not read off the default language's record |
| `useI18nEnabled()` | `false` |
| `<LanguageSwitcher />` | renders `null`; its surrounding chrome must gate on `useI18nEnabled()` too |
| `useTranslation` / `t()` | **unchanged** — bundles still load, real text still resolves, a key is never rendered raw |
| `PATCH /api/v1/settings/language` | refused, 503 |
| Admin Languages + Translations pages | **still reachable and fully functional**, with a notice explaining the flag |

**Disabling is never a data migration.** `profiles.language_code` keeps its
value, the locally remembered code is neither read nor overwritten, and
translation rows are untouched — so switching the flag back on restores every
user's language exactly as they left it. That property is what makes the switch
safe to flip while investigating a problem, and it is pinned by a test.

The admin surfaces stay reachable on purpose: preparing the languages and
strings BEFORE turning the feature on is the entire reason to have a flag rather
than a code branch. Hiding them would make the feature impossible to get ready.

## Known Limitations

1. Translations are per-language, per-domain — there's no per-message RTL override
2. Cache is per-user (localStorage is browser-specific, not synced across devices)
3. Language preference is per-profile; a signed-out visitor falls back to the
   code remembered in localStorage, and the profile wins as soon as there is a session
4. Maximum cache size depends on browser localStorage quota (~5-10MB typical)

## Contributing

Reference conversions: `web/app/login/page.tsx` (the first) and
`web/app/(protected)/admin/translations/*` (done end to end through the
tooling). The full playbook is `docs/wiki/Internationalization.md`.

1. Pick the domain by the rule above; keys name the screen, not the words
2. Pass the English source string as the `t()` fallback at every call site, so
   the screen reads normally in a diff and renders before the bundle arrives.
   **That fallback IS the English catalogue** — it is extracted from the source,
   so there is no parallel file to keep in sync, and a missing one fails CI
3. Keep sentences whole with `{placeholders}` — never concatenate fragments,
   whose order differs between languages
4. Declare any key a scanner cannot read (`t(entry.key)`) with an `@i18n-keys
   <domain>` block, or record why it cannot be enumerated with
   `// @i18n-dynamic-ignore: <reason>`. A reason is mandatory
5. Regenerate and seed:
   ```bash
   php bin/whity-cli i18n:extract   # source → database/i18n/<domain>.json (commit it)
   php bin/whity-cli i18n:sync      # → the translations table, English only
   ```
   Do NOT write other languages by hand and do not machine-translate: a row
   containing English but labelled Arabic is indistinguishable from a finished
   translation. Missing rows are how `/admin/translations` reports the gap.
   (Migration 091 seeded `ar` for the sign-in screen when the whole scope was
   one screen; that route is closed — a numbered migration per converted area
   collides on the next number the moment two people convert two areas.)
6. Test with multiple languages before shipping
