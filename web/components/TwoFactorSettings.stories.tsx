import type { Meta, StoryObj } from "@storybook/nextjs-vite"

import { TwoFactorSettings } from "./TwoFactorSettings"
import { defaultHandlers, http, HttpResponse } from "../.storybook/mocks"

const meta = {
  title: "App/TwoFactorSettings",
  component: TwoFactorSettings,
  tags: ["autodocs"],
  parameters: { layout: "padded" },
} satisfies Meta<typeof TwoFactorSettings>

export default meta
type Story = StoryObj<typeof meta>

// A demo TOTP secret + otpauth URI so the QR renders in the enable wizard.
const setupHandlers = [
  http.post("*/api/v1/auth/2fa/setup", () =>
    HttpResponse.json({
      secret: "JBSWY3DPEHPK3PXP",
      qrCodeUrl:
        "otpauth://totp/Acme:admin@acme.test?secret=JBSWY3DPEHPK3PXP&issuer=Acme",
    })
  ),
  http.post("*/api/v1/auth/2fa/confirm", () =>
    HttpResponse.json({ backup_codes: ["1111-2222", "3333-4444", "5555-6666"] })
  ),
  http.post("*/api/v1/auth/2fa/disable", () => HttpResponse.json({ success: true })),
  http.post("*/api/v1/auth/2fa/regenerate-codes", () =>
    HttpResponse.json({ backup_codes: ["7777-8888", "9999-0000"] })
  ),
]

export const Disabled: Story = {
  parameters: {
    msw: {
      handlers: [
        http.get("*/api/v1/auth/2fa/status", () =>
          HttpResponse.json({ enabled: false, backup_codes_available: 0 })
        ),
        ...setupHandlers,
        ...defaultHandlers,
      ],
    },
  },
}

export const Enabled: Story = {
  parameters: {
    msw: {
      handlers: [
        http.get("*/api/v1/auth/2fa/status", () =>
          HttpResponse.json({ enabled: true, backup_codes_available: 7 })
        ),
        ...setupHandlers,
        ...defaultHandlers,
      ],
    },
  },
}
