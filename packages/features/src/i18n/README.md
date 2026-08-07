# i18n — Internationalization Hooks

Frontend internationalization (i18n) support for Whity Core applications. Provides React hooks and context for managing language switching and translations with client-side caching via localStorage.

## Features

- **Language Management**: Switch between available languages with one hook call
- **Translation Caching**: LocalStorage caching with 24-hour TTL reduces API calls
- **Bilingual Support**: Multiple languages cached simultaneously for instant switching
- **Fallback Chain**: Automatic fallback chain (translation → English → key)
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
Public endpoint (no auth required) that returns available languages.

**Response:**
```json
{
  "languages": [
    { "code": "en", "name": "English" },
    { "code": "ar", "name": "العربية" }
  ]
}
```

### GET /api/v1/settings/language
Authenticated endpoint that returns user's language preference.

**Response:**
```json
{
  "language_code": "ar",
  "available_languages": [
    { "code": "en", "name": "English" },
    { "code": "ar", "name": "العربية" }
  ]
}
```

### PATCH /api/v1/settings/language
Authenticated endpoint to update user's language preference.

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

**Parameters:**
- `domain` (string): Translation domain name (e.g., 'common', 'email', 'errors')

**Returns:**
```typescript
(key: string, fallback?: string) => string
```

**Throws:** Error if used outside `<LanguageProvider>`

**Example:**
```tsx
const t = useTranslation('common')
const saved = t('messages.saved')  // From translations
const missing = t('missing.key')    // Returns 'missing.key'
const withDefault = t('missing.key', 'Not found')  // Returns 'Not found'
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

// Check current language
if (currentLanguage === 'ar') {
  // ...
}

// Render available languages
availableLanguages.forEach(lang => console.log(lang.code, lang.name))
```

### `<LanguageProvider>`

Context provider that manages language state and translations.

**Props:**
- `children` (ReactNode): App content to wrap
- `defaultLanguage` (string, default: 'en'): Fallback language if user preference unavailable

**Example:**
```tsx
<LanguageProvider defaultLanguage="en">
  <App />
</LanguageProvider>
```

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

## Supported Domains

The following translation domains are supported by default:

- `common` — UI chrome and generic strings
- `email` — Email template strings
- `errors` — Error messages

Additional domains can be added by:
1. Adding translations in the backend database
2. Requesting them in the LanguageProvider (see `LanguageProvider.tsx` line 80)

## RTL Support

The i18n system is language-agnostic and works with RTL (right-to-left) languages like Arabic. The app layout direction is controlled separately via `direction-context.tsx` (see web/).

## Known Limitations

1. Translations are per-language, per-domain — there's no per-message RTL override
2. Cache is per-user (localStorage is browser-specific, not synced across devices)
3. Language preference is per-profile (not per-browser)
4. Maximum cache size depends on browser localStorage quota (~5-10MB typical)

## Contributing

When adding new translation domains:

1. Add the domain name to the backend's translation table
2. Add it to the `LanguageProvider`'s domain list (see `LanguageProvider.tsx` line 80)
3. Ensure translations are seeded via migrations
4. Test with multiple languages before shipping
