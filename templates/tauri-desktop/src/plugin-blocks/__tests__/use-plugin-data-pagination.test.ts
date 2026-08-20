/**
 * #867: the desktop hook has web's pagination defect, so it gets web's rules.
 *
 * A data-bound block presents its `source` as the whole collection, and one
 * request through the PHP host is one page of 25 — so a `dataTable` or
 * `referenceSelect` over any core list endpoint used to draw page 1 and nothing
 * else, with nothing on screen to say so. These mirror
 * `web/__tests__/use-plugin-data.test.tsx`'s pagination cases case for case:
 * later pages are fetched, a walk that cannot be finished is reported rather
 * than rendered, the walk is bounded, and an unpaginated source is fetched
 * exactly as it was.
 */

import { renderHook, waitFor } from "@testing-library/react"
import { invoke } from "@tauri-apps/api/core"

import { usePluginData } from "../use-plugin-data"

jest.mock("@tauri-apps/api/core", () => ({ invoke: jest.fn() }))
const mockInvoke = invoke as jest.MockedFunction<typeof invoke>

/** PaginationParams::DEFAULT_PER_PAGE / MAX_PER_PAGE — the server's numbers. */
const SERVER_DEFAULT_PER_PAGE = 25
const SERVER_MAX_PER_PAGE = 100

/** A parse function that accepts a non-empty array of records. */
function parseRows(body: unknown): Record<string, unknown>[] | null {
  if (!Array.isArray(body) || body.length === 0) return null
  return body as Record<string, unknown>[]
}

function makeRows(count: number) {
  return Array.from({ length: count }, (_, i) => ({ id: i + 1, name: `Row ${i + 1}` }))
}

/** Slice and clamp the way the API does; the clamp is why one request is never
 * enough at any page size. */
function servePaginated(dataset: Record<string, unknown>[], path: string) {
  const query = new URLSearchParams(path.split("?")[1] ?? "")
  const perPage = Math.min(Number(query.get("per_page") ?? SERVER_DEFAULT_PER_PAGE), SERVER_MAX_PER_PAGE)
  const page = Number(query.get("page") ?? "1")
  const offset = (page - 1) * perPage

  return Promise.resolve({
    status: 200,
    body: {
      data: dataset.slice(offset, offset + perPage),
      pagination: { page, perPage, total: dataset.length, totalPages: Math.ceil(dataset.length / perPage) },
    },
  })
}

function requestedPaths(): string[] {
  return mockInvoke.mock.calls.map(([, args]) => String((args as { path: string }).path))
}

/** The `path` of the request the host was asked for. */
function pathOf(args: unknown): string {
  return String((args as { path: string }).path)
}

describe("desktop usePluginData pagination", () => {
  beforeEach(() => {
    mockInvoke.mockReset()
  })

  it("loads rows that fall beyond the server default page size", async () => {
    const dataset = makeRows(48)
    mockInvoke.mockImplementation((_command, args) => servePaginated(dataset, pathOf(args)))

    const { result } = renderHook(() => usePluginData("/api/v1/x/rows", parseRows))

    await waitFor(() => expect(result.current.status).toBe("ready"))

    if (result.current.status === "ready") {
      expect(result.current.data).toHaveLength(48)
    }
  })

  it("loads every row past the server maximum page size", async () => {
    // 240 rows cannot arrive in one request at ANY page size — the server
    // clamps per_page to 100 — so a bigger page size was never the fix.
    const dataset = makeRows(240)
    mockInvoke.mockImplementation((_command, args) => servePaginated(dataset, pathOf(args)))

    const { result } = renderHook(() => usePluginData("/api/v1/x/rows", parseRows))

    await waitFor(() => expect(result.current.status).toBe("ready"))

    if (result.current.status === "ready") {
      expect(result.current.data).toHaveLength(240)
    }
    expect(requestedPaths()).toEqual([
      "/api/v1/x/rows",
      "/api/v1/x/rows?page=1&per_page=100",
      "/api/v1/x/rows?page=2&per_page=100",
      "/api/v1/x/rows?page=3&per_page=100",
    ])
  })

  it("leaves an unpaginated source fetched exactly as before", async () => {
    // The regression this must not cause: a plugin's own route, answering a
    // bare `{data}`, is still one request at the declared path.
    mockInvoke.mockResolvedValue({ status: 200, body: { data: makeRows(40) } })

    const { result } = renderHook(() => usePluginData("/api/v1/x/rows", parseRows))

    await waitFor(() => expect(result.current.status).toBe("ready"))

    if (result.current.status === "ready") {
      expect(result.current.data).toHaveLength(40)
    }
    expect(requestedPaths()).toEqual(["/api/v1/x/rows"])
  })

  it("does not re-request a paginated source that already arrived whole", async () => {
    mockInvoke.mockImplementation((_command, args) => servePaginated(makeRows(3), pathOf(args)))

    const { result } = renderHook(() => usePluginData("/api/v1/x/rows", parseRows))

    await waitFor(() => expect(result.current.status).toBe("ready"))

    expect(requestedPaths()).toEqual(["/api/v1/x/rows"])
  })

  it("appends to a source that already carries a query string", async () => {
    const dataset = makeRows(120)
    mockInvoke.mockImplementation((_command, args) => servePaginated(dataset, pathOf(args)))

    const { result } = renderHook(() => usePluginData("/api/v1/x/rows?parent_id=7", parseRows))

    await waitFor(() => expect(result.current.status).toBe("ready"))

    expect(requestedPaths().slice(1)).toEqual([
      "/api/v1/x/rows?parent_id=7&page=1&per_page=100",
      "/api/v1/x/rows?parent_id=7&page=2&per_page=100",
    ])
  })

  it("reports a walk it could not finish instead of a short list", async () => {
    const dataset = makeRows(240)
    const parse = jest.fn(parseRows)
    mockInvoke.mockImplementation((_command, args) => {
      const path = pathOf(args)
      if (new URLSearchParams(path.split("?")[1] ?? "").get("page") === "2") {
        return Promise.resolve({ status: 500, body: { error: "boom" } })
      }
      return servePaginated(dataset, path)
    })

    const { result } = renderHook(() => usePluginData("/api/v1/x/rows", parse))

    await waitFor(() => expect(result.current.status).toBe("error"))

    // The 100 rows that did arrive never reached the block.
    expect(parse).not.toHaveBeenCalled()
  })

  it("surfaces the request cap rather than truncating at it", async () => {
    // A `totalPages` that never terminates. The bound exists so the window does
    // not hang; reaching it is a failure, not a quiet stop with what it had.
    const parse = jest.fn(parseRows)
    mockInvoke.mockImplementation((_command, args) => {
      const page = Number(new URLSearchParams(pathOf(args).split("?")[1] ?? "").get("page") ?? "1")
      return Promise.resolve({
        status: 200,
        body: {
          data: makeRows(SERVER_MAX_PER_PAGE),
          pagination: { page, perPage: SERVER_MAX_PER_PAGE, total: 1_000_000, totalPages: 10_000 },
        },
      })
    })

    const { result } = renderHook(() => usePluginData("/api/v1/x/rows", parse))

    await waitFor(() => expect(result.current.status).toBe("error"))

    // The probe plus the cap — not the 10 000 pages the server claimed.
    expect(mockInvoke).toHaveBeenCalledTimes(101)
    expect(parse).not.toHaveBeenCalled()
  })
})
