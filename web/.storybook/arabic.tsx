import * as React from "react"
import type { Decorator } from "@storybook/nextjs-vite"
import { LanguageProvider } from "@whity-org/features/i18n"
import { DirectionProvider } from "@/lib/direction-context"
import arAdmin from "../../database/i18n/ar/admin.json"
import arCommon from "../../database/i18n/ar/common.json"
import arDocuments from "../../database/i18n/ar/documents.json"
import { http, HttpResponse } from "./mocks"

/**
 * Renders a documents story in Arabic, right to left, from the committed
 * Arabic catalogues — so the gallery shows what an Arabic reader gets, not
 * only the English defaults.
 */
const LANGUAGES = [
  { code: "en", name: "English", direction: "ltr" },
  { code: "ar", name: "العربية", direction: "rtl" },
]

const CATALOGUES: Record<string, Record<string, string>> = {
  admin: arAdmin.keys,
  common: arCommon.keys,
  documents: arDocuments.keys,
}

export const arabicHandlers = [
  http.get("*/api/v1/languages", () => HttpResponse.json({ languages: LANGUAGES, i18n_enabled: true })),
  http.get("*/api/v1/settings/language", () =>
    HttpResponse.json({ language_code: "ar", available_languages: LANGUAGES })
  ),
  http.get("*/api/v1/translations/ar/:domain", ({ params }) =>
    HttpResponse.json({ translations: CATALOGUES[String(params.domain)] ?? {} })
  ),
]

export const withArabic: Decorator = (Story) => (
  <LanguageProvider defaultLanguage="ar">
    <DirectionProvider>
      <Story />
    </DirectionProvider>
  </LanguageProvider>
)
