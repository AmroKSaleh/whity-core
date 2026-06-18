import type { Metadata } from "next";
import { Inter, Geist_Mono } from "next/font/google";
import "./globals.css";
import { cn } from "@/lib/utils";
import { AuthProvider } from "@/lib/auth-context";
import { ToastProvider } from "@/lib/toast-context";
import { NavigationProvider } from "@/lib/navigation-context";
import { PluginFeaturesProvider } from "@/lib/plugin-features-context";
import { ToastContainer } from "@/components/ui/toast-container";
import "@/lib/plugin-screens";

// Design-token font families (see src/design/tokens/base.json):
// Inter drives --font-sans / --font-heading, Geist Mono drives --font-mono.
const inter = Inter({ subsets: ["latin"], variable: "--font-sans" });

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  title: "Whity Core",
  description: "Authentication and plugin management platform",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html
      lang="en"
      className={cn("h-full", "antialiased", inter.variable, geistMono.variable, "font-sans")}
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
      </body>
    </html>
  );
}
