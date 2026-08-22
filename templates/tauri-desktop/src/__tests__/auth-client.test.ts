/**
 * #914: the IPC surface of the two-step enrollment.
 *
 * These assert the WIRE, not behaviour, because the wire is where this flow
 * broke and where nothing else would catch it: the Rust command names and its
 * camelCase argument keys are matched by string at runtime, so a rename on
 * either side is invisible to `tsc` and fails only on a real device. Two
 * details in particular are pinned here:
 *
 *   - `auth_enroll_with_tenant` receives `selectionToken` / `tenantId` /
 *     `deviceName` / `email` (Tauri lowercases these to the Rust snake_case
 *     parameters); a typo would arrive as `undefined` and the backend would
 *     answer 401 rather than anything diagnostic;
 *   - tenant id `0` — the SYSTEM tenant — survives the trip as `0`. It is the
 *     one id a truthiness check anywhere along this path would silently eat,
 *     and it is exactly the account the demo signs in with.
 */

import { invoke } from "@tauri-apps/api/core"

import { authClient, type EnrollResult } from "../auth-client"

jest.mock("@tauri-apps/api/core", () => ({ invoke: jest.fn() }))
const mockInvoke = invoke as jest.MockedFunction<typeof invoke>

const ENROLLED: EnrollResult = { status: "enrolled", email: "admin@example.com", deviceId: 7 }

beforeEach(() => {
  mockInvoke.mockReset()
  mockInvoke.mockResolvedValue(ENROLLED)
})

describe("authClient.enrollWithTenant", () => {
  it("names the command and its arguments exactly as Rust declares them", async () => {
    await authClient.enrollWithTenant("sel-tok", 4, "Front desk", "admin@example.com")

    expect(mockInvoke).toHaveBeenCalledWith("auth_enroll_with_tenant", {
      selectionToken: "sel-tok",
      tenantId: 4,
      deviceName: "Front desk",
      email: "admin@example.com",
    })
  })

  it("forwards the system tenant as 0 rather than dropping it", async () => {
    await authClient.enrollWithTenant("sel-tok", 0, "Front desk", "admin@example.com")

    const [, args] = mockInvoke.mock.calls[0]
    expect((args as { tenantId: number }).tenantId).toBe(0)
  })

  it("returns the command's result unchanged", async () => {
    await expect(
      authClient.enrollWithTenant("sel-tok", 4, "Front desk", "admin@example.com")
    ).resolves.toEqual(ENROLLED)
  })
})

describe("authClient.enroll", () => {
  it("still calls the single-step command with the credentials", async () => {
    await authClient.enroll("admin@example.com", "hunter2", "Front desk")

    expect(mockInvoke).toHaveBeenCalledWith("auth_enroll", {
      email: "admin@example.com",
      password: "hunter2",
      deviceName: "Front desk",
    })
  })
})
