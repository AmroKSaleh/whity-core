/**
 * #914: enrolling a desktop device on a profile that is active in more than one
 * tenant — which the system administrator is, making this the account the demo
 * actually uses. Before this, the gate answered every such login with "the
 * template's enrollment supports single-tenant accounts only" and stopped.
 *
 * Driven through `<AuthGate>` rather than the form in isolation, because the
 * defect was never in one component: it was that the enrollment path had no
 * second step at all. So these run the real provider, the real gate, the real
 * card, and mock only the `@tauri-apps/api` transport.
 *
 * The rule under test, from `AuthHandler::handleSelectTenant()`'s docblock: the
 * client must NEVER auto-select. Tenant 0 is not special-cased — a profile that
 * genuinely holds an active tenant-0 membership choosing it is legitimate system
 * authority, and what was closed off is that tenant being picked SILENTLY. The
 * assertions below therefore pin "nothing is preselected" and "0 is a real,
 * selectable choice" as hard requirements, not incidental UI behaviour.
 */

import React from "react"
import { render, screen, waitFor } from "@testing-library/react"
import { userEvent } from "@testing-library/user-event"
import { invoke } from "@tauri-apps/api/core"
import { listen } from "@tauri-apps/api/event"

import { AppStateProvider, AuthGate } from "../app-state-provider"
import type { EnrollResult } from "../auth-client"

jest.mock("@tauri-apps/api/core", () => ({ invoke: jest.fn() }))
jest.mock("@tauri-apps/api/event", () => ({ listen: jest.fn() }))

const mockInvoke = invoke as jest.MockedFunction<typeof invoke>
const mockListen = listen as jest.MockedFunction<typeof listen>

/** The two tenants the demo's system admin is actually active in. */
const MEMBERSHIPS = [
  { tenantId: 0, tenantName: "System", role: "system_admin" },
  { tenantId: 4, tenantName: "شركة الأمل", role: "admin" },
]

const SELECTION: EnrollResult = {
  status: "requiresTenantSelection",
  selectionToken: "sel-tok",
  memberships: MEMBERSHIPS,
}

/**
 * A fake device: unenrolled until a command enrolls it, so the gate's own
 * transition (form -> app) is what proves the flow finished, not a spy call.
 */
function mockDevice(handlers: Record<string, (args: unknown) => unknown> = {}) {
  let enrolled = false

  mockListen.mockResolvedValue(() => {})
  mockInvoke.mockImplementation((cmd: string, args?: unknown) => {
    if (cmd in handlers) {
      const result = handlers[cmd](args)
      if (result !== undefined && (result as EnrollResult).status === "enrolled") enrolled = true
      return Promise.resolve(result)
    }
    switch (cmd) {
      case "auth_status":
        return Promise.resolve({
          enrolled,
          email: enrolled ? "admin@example.com" : null,
          deviceId: enrolled ? 7 : null,
          activeTenantId: enrolled ? 0 : null,
          credentialExpiresAt: null,
          lastOnlineAuthAt: enrolled ? 1_760_000_000 : null,
          maxLoginSeconds: 259_200,
          serverUrl: "https://whity.example.org",
        })
      case "auth_lock_state":
        return Promise.resolve({ locked: false, reason: null, secondsRemaining: 1000 })
      case "get_sync_status":
        return Promise.resolve({ unsyncedCount: 0, conflictCount: 0, lastPullAt: null, lastPushAt: null })
      case "list_conflicts":
        return Promise.resolve([])
      default:
        return Promise.resolve(null)
    }
  })
}

function renderGate() {
  return render(
    <AppStateProvider>
      <AuthGate>
        <div>Signed in</div>
      </AuthGate>
    </AppStateProvider>
  )
}

/** Fill the credentials step and submit it. */
async function signIn(user: ReturnType<typeof userEvent.setup>) {
  const email = await screen.findByLabelText("Email")
  await user.type(email, "admin@example.com")
  await user.type(screen.getByLabelText("Password"), "hunter2")
  await user.click(screen.getByRole("button", { name: "Enroll device" }))
}

beforeEach(() => {
  mockInvoke.mockReset()
  mockListen.mockReset()
})

describe("multi-tenant enrollment", () => {
  it("prompts with the tenants by name instead of refusing the login", async () => {
    const user = userEvent.setup()
    mockDevice({ auth_enroll: () => SELECTION })
    renderGate()

    await signIn(user)

    expect(await screen.findByText("Choose a tenant")).toBeInTheDocument()
    // Real names, not bare ids — the whole reason the Rust carries the list.
    expect(screen.getByText("System")).toBeInTheDocument()
    expect(screen.getByText("شركة الأمل")).toBeInTheDocument()
    // And nothing resembling the old dead end.
    expect(screen.queryByText(/single-tenant accounts only/i)).not.toBeInTheDocument()
  })

  it("preselects nothing and refuses to submit until the operator chooses", async () => {
    const user = userEvent.setup()
    mockDevice({ auth_enroll: () => SELECTION })
    renderGate()

    await signIn(user)
    await screen.findByText("Choose a tenant")

    for (const radio of screen.getAllByRole("radio")) {
      expect(radio).not.toBeChecked()
    }
    expect(screen.getByRole("button", { name: "Enroll device" })).toBeDisabled()

    await user.click(screen.getByRole("radio", { name: /System/ }))
    expect(screen.getByRole("button", { name: "Enroll device" })).toBeEnabled()
  })

  it("completes enrollment with the chosen tenant, carrying the selection token", async () => {
    const user = userEvent.setup()
    mockDevice({
      auth_enroll: () => SELECTION,
      auth_enroll_with_tenant: () => ({ status: "enrolled", email: "admin@example.com", deviceId: 7 }),
    })
    renderGate()

    await signIn(user)
    await screen.findByText("Choose a tenant")
    await user.click(screen.getByRole("radio", { name: /الأمل/ }))
    await user.click(screen.getByRole("button", { name: "Enroll device" }))

    await waitFor(() => expect(screen.getByText("Signed in")).toBeInTheDocument())

    const call = mockInvoke.mock.calls.find(([cmd]) => cmd === "auth_enroll_with_tenant")
    expect(call?.[1]).toEqual({
      selectionToken: "sel-tok",
      tenantId: 4,
      deviceName: "Whity Desktop",
      email: "admin@example.com",
    })
  })

  /**
   * The system tenant is the demo account's own, and `0` is the one id a
   * truthiness check would drop. It must reach Rust as `0`.
   */
  it("treats the system tenant as an ordinary, selectable choice", async () => {
    const user = userEvent.setup()
    mockDevice({
      auth_enroll: () => SELECTION,
      auth_enroll_with_tenant: () => ({ status: "enrolled", email: "admin@example.com", deviceId: 7 }),
    })
    renderGate()

    await signIn(user)
    await screen.findByText("Choose a tenant")
    await user.click(screen.getByRole("radio", { name: /System/ }))
    await user.click(screen.getByRole("button", { name: "Enroll device" }))

    await waitFor(() => expect(screen.getByText("Signed in")).toBeInTheDocument())

    const call = mockInvoke.mock.calls.find(([cmd]) => cmd === "auth_enroll_with_tenant")
    expect((call?.[1] as { tenantId: number }).tenantId).toBe(0)
  })

  it("offers a nameless tenant by id rather than dropping it from the choices", async () => {
    const user = userEvent.setup()
    mockDevice({
      auth_enroll: () => ({
        status: "requiresTenantSelection",
        selectionToken: "sel-tok",
        memberships: [
          { tenantId: 0, tenantName: "System", role: "system_admin" },
          { tenantId: 9, tenantName: "", role: "" },
        ],
      }),
    })
    renderGate()

    await signIn(user)
    await screen.findByText("Choose a tenant")

    expect(screen.getAllByRole("radio")).toHaveLength(2)
    expect(screen.getByText("Tenant 9")).toBeInTheDocument()
  })

  /**
   * The selection token lives 300 seconds. Lapsing must return the operator to
   * the credentials step — fields intact, one click from retrying — rather than
   * stranding a half-finished enrollment behind a screen with no way forward.
   */
  it("sends a lapsed selection back to the credentials step, retryably", async () => {
    const user = userEvent.setup()
    mockDevice({
      auth_enroll: () => SELECTION,
      auth_enroll_with_tenant: () => ({ status: "selectionLapsed" }),
    })
    renderGate()

    await signIn(user)
    await screen.findByText("Choose a tenant")
    await user.click(screen.getByRole("radio", { name: /System/ }))
    await user.click(screen.getByRole("button", { name: "Enroll device" }))

    // Both <Alert> and its description carry role="alert", so match the copy.
    expect(await screen.findByText(/sign in again/i)).toBeInTheDocument()
    // Back on the credentials step, still filled in: retrying is one click.
    expect(screen.getByLabelText("Email")).toHaveValue("admin@example.com")
    expect(screen.getByRole("button", { name: "Enroll device" })).toBeEnabled()
    expect(screen.queryByText("Choose a tenant")).not.toBeInTheDocument()
  })

  it("lets the operator back out to a different account", async () => {
    const user = userEvent.setup()
    mockDevice({ auth_enroll: () => SELECTION })
    renderGate()

    await signIn(user)
    await screen.findByText("Choose a tenant")
    await user.click(screen.getByRole("button", { name: "Use a different account" }))

    expect(await screen.findByLabelText("Password")).toBeInTheDocument()
    expect(screen.queryByText("Choose a tenant")).not.toBeInTheDocument()
  })

  /** Still a gap, and deliberately so — pinned here so it fails loudly if the
   * message is ever mistaken for a working path. */
  it("still reports 2FA as unsupported", async () => {
    const user = userEvent.setup()
    mockDevice({ auth_enroll: () => ({ status: "requires2fa", tempToken: "tmp" }) })
    renderGate()

    await signIn(user)

    expect(await screen.findByText(/two-factor/i)).toBeInTheDocument()
  })
})
