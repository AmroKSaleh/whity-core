import type { Metadata } from "next";
import { Noto_Sans, Noto_Sans_Arabic, Geist_Mono } from "next/font/google";
import "./globals.css";
import { cn } from "@/lib/utils";
import { AuthProvider } from "@/lib/auth-context";
import { ToastProvider } from "@/lib/toast-context";
import { NavigationProvider } from "@/lib/navigation-context";
import { DirectionProvider } from "@/lib/direction-context";
import { PluginFeaturesProvider } from "@/lib/plugin-features-context";
import { ToastContainer } from "@/components/ui/toast-container";
import "@/lib/plugin-screens";
import { getBranding } from "@/lib/branding";
import { BrandingProvider } from "@/lib/branding-context";
import { getThemeOverrides } from "@/lib/theme";

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
      lang="en"
      className={cn(
        "h-full",
        "antialiased",
        notoSans.variable,
        notoSansArabic.variable,
        geistMono.variable,
        "font-sans"
      )}
    >
      {/*
        suppressHydrationWarning (one level deep, body attributes only): browser
        extensions such as Grammarly inject attributes onto <body> after the SSR
        HTML is sent but before React hydrates (e.g. data-gr-ext-installed,
        data-new-gr-c-s-check-loaded), which otherwise trips a dev-only
        hydration-mismatch warning. This does NOT suppress mismatches in the app's
        own markup below <body>.
      */}
      <body className="min-h-full flex flex-col" suppressHydrationWarning>
        {/* React 19 hoists <style> into <head> regardless of nesting position. */}
        {overrideCss !== "" && <style>{`:root{${overrideCss}}`}</style>}
        <BrandingProvider initial={branding}>
          <DirectionProvider>
            <AuthProvider>
              <ToastProvider>
                <NavigationProvider>
                  <PluginFeaturesProvider>
                    {children}
                    <ToastContainer />
                  </PluginFeaturesProvider>
                </NavigationProvider>
              </ToastProvider>
            </AuthProvider>
          </DirectionProvider>
        </BrandingProvider>
      </body>
    </html>
  );
}
