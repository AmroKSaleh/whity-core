/**
 * Example: Using the i18n Hooks
 *
 * This file demonstrates how to use the i18n system in a real application.
 *
 * For a REAL converted screen, read web/app/login/page.tsx — the reference
 * conversion, and the shape every other screen should copy.
 */

import { ReactNode } from 'react'
import {
  LanguageProvider,
  useTranslation,
  useCurrentLanguage,
  useLanguageDirection,
  LanguageSwitcher,
} from './index'

/**
 * Example 1: Using useTranslation to translate strings
 *
 * ALWAYS pass the English source string as the fallback. It is what renders
 * before the bundle arrives, what renders if a key was never seeded, and what
 * a reviewer reads in the diff.
 */
function TranslationExample() {
  // Asking for a domain is what LOADS it — there is no list to register in.
  // Core domains are bare; a plugin's are namespaced ('acme:catalog').
  const t = useTranslation('common')

  return (
    <section>
      <h2>{t('page.title', 'Default Title')}</h2>
      <p>{t('page.description', 'Default description')}</p>
      {/* Whole sentence, one hole — never `t('greeting') + name`, whose word
          order is English-only. */}
      <p>{t('page.greeting', 'Welcome back, {name}', { name: 'Sam' })}</p>
      <button>{t('button.save', 'Save')}</button>
      <button>{t('button.cancel', 'Cancel')}</button>
    </section>
  )
}

/**
 * Example 1b: Reading the interface direction
 *
 * Direction comes from the chosen LANGUAGE's record, so this never needs
 * touching when a new right-to-left language is added. Prefer logical CSS
 * (ms/me, ps/pe, start/end) over reading `dir` at all; read it only when the
 * decision genuinely lives in JS.
 */
function DirectionExample() {
  const dir = useLanguageDirection()

  return <p>The interface is currently {dir === 'rtl' ? 'right-to-left' : 'left-to-right'}.</p>
}

/**
 * Example 2: Using useCurrentLanguage to switch languages
 */
function LanguageSwitchExample() {
  const { currentLanguage, availableLanguages, setLanguage, isLoading, error } =
    useCurrentLanguage()

  if (isLoading) {
    return <p>Loading languages...</p>
  }

  if (error) {
    return <p>Error loading languages: {error.message}</p>
  }

  return (
    <div>
      <p>Current language: {currentLanguage}</p>
      <select
        value={currentLanguage || 'en'}
        onChange={(e) => setLanguage(e.target.value)}
      >
        {availableLanguages.map((lang) => (
          <option key={lang.code} value={lang.code}>
            {lang.name}
          </option>
        ))}
      </select>
    </div>
  )
}

/**
 * Example 3: Using the built-in LanguageSwitcher component
 */
function LanguageSwitcherExample() {
  return (
    <div>
      <h3>Language Switcher (Buttons)</h3>
      <LanguageSwitcher variant="buttons" className="my-custom-class" />

      <h3>Language Switcher (Dropdown)</h3>
      <LanguageSwitcher variant="dropdown" />
    </div>
  )
}

/**
 * Example 4: Multiple domains
 */
function MultiDomainExample() {
  const tCommon = useTranslation('common')
  const tEmail = useTranslation('email')
  const tErrors = useTranslation('errors')

  return (
    <div>
      <h3>Common Domain</h3>
      <p>{tCommon('button.save')}</p>

      <h3>Email Domain</h3>
      <p>{tEmail('email.welcome.subject')}</p>

      <h3>Errors Domain</h3>
      <p>{tErrors('error.notfound')}</p>
    </div>
  )
}

/**
 * Example 5: Bilingual UI (showing multiple languages)
 */
function BilingualExample() {
  const { currentLanguage, availableLanguages } = useCurrentLanguage()

  return (
    <div>
      <p>Current language: {currentLanguage}</p>
      <p>Available languages: {availableLanguages.map((l) => l.code).join(', ')}</p>
    </div>
  )
}

/**
 * Example 6: Full App Setup
 *
 * This is the recommended way to set up your app with i18n support.
 */
export function AppWithI18n() {
  return (
    <LanguageProvider defaultLanguage="en">
      <App />
    </LanguageProvider>
  )
}

function App() {
  return (
    <main>
      <Header />
      <Content />
      <Footer />
    </main>
  )
}

function Header() {
  const t = useTranslation('common')

  return (
    <header>
      <h1>{t('app.title', 'My Application')}</h1>
      <LanguageSwitcher variant="dropdown" />
    </header>
  )
}

function Content() {
  return (
    <div>
      <TranslationExample />
      <DirectionExample />
      <MultiDomainExample />
      <BilingualExample />
    </div>
  )
}

function Footer() {
  const t = useTranslation('common')
  const { currentLanguage } = useCurrentLanguage()

  return (
    <footer>
      <p>
        {t('footer.copyright', '© 2024 My App')} | {t('footer.language')} {currentLanguage}
      </p>
    </footer>
  )
}

/**
 * Usage in SPA Harness (for testing)
 *
 * Add this to packages/spa-harness/src/main.tsx:
 *
 *   import { AppWithI18n } from '@amroksaleh/features/i18n'
 *   import { createRoot } from 'react-dom/client'
 *
 *   createRoot(document.getElementById('root')!).render(
 *     <StrictMode>
 *       <AppWithI18n />
 *     </StrictMode>
 *   )
 *
 * This will require setting up mock translations on the backend API.
 */

export default AppWithI18n
