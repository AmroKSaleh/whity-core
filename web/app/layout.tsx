import type { Metadata } from "next";
import { Noto_Sans, Noto_Sans_Arabic, Geist_Mono } from "next/font/google";
import "./globals.css";
import { cn } from "@/lib/utils";
import { AuthProvider } from "@/lib/auth-context";
import { ToastProvider } from "@/lib/toast-context";
import { NavigationProvider } from "@/lib/navigation-context";
import { DirectionProvider } from "@/lib/direction-context";
import { PluginFeaturesProvider } from "@/lib/plugin-features-context";
import { CapabilitiesProvider } from "@/lib/capabilities-context";
import { ToastContainerMount } from "@/components/ui/toast-container-mount";
import { PluginScreenRegistrations } from "@/lib/plugin-screens";
import { getBranding } from "@/lib/branding";
import { BrandingProvider } from "@/lib/branding-context";
import { getThemeOverrides } from "@/lib/theme";
import { ThemeModeProvider, ThemeModeInitScript } from "@/lib/theme-mode-context";
import { DirectionInitScript } from "@/lib/direction-context";
import { AppLanguageProvider } from "@/lib/app-language-provider";
import { getUiPreferences } from "@/lib/ui-preferences";
import { UiPreferencesProvider } from "@/lib/ui-preferences-context";

// Design-token font families (see src/design/tokens/base.json): Noto Sans
// (latin) + Noto Sans Arabic together drive --font-sans / --font-heading (see
// the composed stack in globals.css), Geist Mono drives --font-mono. Loading
// both scripts unconditionally — rather than swapping fonts on `dir` — lets
// the browser fall through per-glyph, which is correct for bidi/mixed-script
// content (e.g. an Arabic name inside an English sentence).
const notoSans = Noto_Sans({ subsets: ["latin"], variable: "--font-noto-sans" });
const notoSansArabic = Noto_Sans_Arabic({
  subsets: ["arabic"],
  variable: "--font-noto-sans-arabic",
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export async function generateMetadata(): Promise<Metadata> {
  const b = await getBranding();
  return {
    title: b.siteName,
    description: "Authentication and plugin management platform",
    ...(b.faviconUrl ? { icons: { icon: b.faviconUrl } } : {}),
  };
}

export default async function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  const branding = await getBranding();
  // #1068: resolved on the SERVER so the first paint already honours it. A
  // client-only fetch would render every date and then blank it a moment
  // later, which is the setting being briefly false on every navigation.
  const uiPreferences = await getUiPreferences();
  // WC-242: color overrides an installed plugin may contribute (see
  // web/lib/theme.ts). Both the server (ThemeApiHandler) and the client
  // (getThemeOverrides) already restrict keys to known design-token names
  // and values to strict '#rrggbb' hex, so building this CSS string by plain
  // concatenation is safe — neither component can contain quotes or braces.
  const themeOverrides = await getThemeOverrides();
  const overrideCss = Object.entries(themeOverrides)
    .map(([key, value]) => `--${key}:${value};`)
    .join("");
  return (
    <html
      // `lang` and `dir` are the SERVER's best guess and nothing more. The
      // server cannot know the reader's language: the durable preference lives
      // on their profile and is fetched after hydration. So this is the neutral
      // starting point, and DirectionInitScript (in <head>) corrects both from
      // the last resolved values BEFORE first paint — which is the difference
      // between an Arabic reader seeing a mirrored interface immediately and
      // watching an English left-to-right one flip a moment later.
      lang="en"
      dir="ltr"
      className={cn(
        "h-full",
        "antialiased",
        notoSans.variable,
        notoSansArabic.variable,
        geistMono.variable,
        "font-sans"
      )}
      // The blocking init scripts (see <head> below) set the `dark` class and
      // the `dir`/`lang` attributes on this element before hydration, from
      // localStorage and system preferences the server can't know — expected,
      // benign mismatches.
      suppressHydrationWarning
    >
      <head>
        {/*
          Blocking, must run before first paint to avoid a flash of the wrong
          color scheme (see lib/theme-mode-context.tsx) — rendered here, ahead
          of globals.css, rather than left to ThemeModeProvider's own effects.
        */}
        <ThemeModeInitScript />
        {/*
          Blocking for the same reason, and a louder one: `dir` decides where
          everything on the page IS. Applied after the theme script only because
          both are synchronous and the order between them does not matter.
        */}
        <DirectionInitScript />
      </head>
      {/*
        suppressHydrationWarning (one level deep, body attributes only): browser
        extensions such as Grammarly inject attributes onto <body> after the SSR
        HTML is sent but before React hydrates (e.g. data-gr-ext-installed,
        data-new-gr-c-s-check-loaded), which otherwise trips a dev-only
        hydration-mismatch warning. This does NOT suppress mismatches in the app's
        own markup below <body>.
      */}
      <body className="min-h-full flex flex-col" suppressHydrationWarning>
        {/*
          #964: the app-owned plugin screen overrides, registered in the
          BROWSER. This used to be a bare `import "@/lib/plugin-screens"` at
          the top of this SERVER component, which ran the registrations in the
          server's module graph only — leaving the client-side registry (the
          one `/admin/x/[featureId]` actually consults) empty on every render,
          so no override ever rendered. Rendering the client module here is
          what puts it in the shell's client bundle.
        */}
        <PluginScreenRegistrations />
        {/* React 19 hoists <style> into <head> regardless of nesting position. */}
        {overrideCss !== "" && <style>{`:root{${overrideCss}}`}</style>}
        <BrandingProvider initial={branding}>
          <ThemeModeProvider>
            <AuthProvider>
              {/*
                Inside AuthProvider for the reason the language provider below
                is: `ui.hide_dates` resolves per TENANT, and a tenant switch
                changes the identity without reloading the page. See
                lib/ui-preferences-context.tsx.
              */}
              <UiPreferencesProvider initial={uiPreferences}>
                {/*
                  The language provider is ABOVE DirectionProvider deliberately:
                  direction is derived from the resolved language's `direction`
                  property, so the language must resolve first. See
                  lib/direction-context.tsx. It sits INSIDE AuthProvider because
                  it re-resolves the preference when the signed-in identity
                  changes — see lib/app-language-provider.tsx.
                */}
                <AppLanguageProvider>
                  <DirectionProvider>
                    <CapabilitiesProvider>
                      <ToastProvider>
                        <NavigationProvider>
                          <PluginFeaturesProvider>
                            {children}
                            <ToastContainerMount />
                          </PluginFeaturesProvider>
                        </NavigationProvider>
                      </ToastProvider>
                    </CapabilitiesProvider>
                  </DirectionProvider>
                </AppLanguageProvider>
              </UiPreferencesProvider>
            </AuthProvider>
          </ThemeModeProvider>
        </BrandingProvider>
      </body>
    </html>
  );
}
