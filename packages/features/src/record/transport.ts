/**
 * The transport seam every adapter in this package is written over.
 *
 * Introduced by the roles pilot and moved here when the SECOND adapter needed it
 * (#882): `{status, body}` is the natural least-common-denominator between web's
 * `fetch` `Response` and the desktop side's Rust `remote_request` command, which
 * already returns exactly this shape. An adapter is then a thin function over
 * one of these, and the per-client differences collapse to which transport gets
 * passed in.
 *
 * `@amroksaleh/features/roles` still exports both names, unchanged, so nothing
 * that already imports them from there has to move.
 */

/** A single transport round-trip result. */
export interface TransportResponse {
  /** HTTP-equivalent status code. */
  status: number;
  /** Parsed JSON response body (or `null` when there was no body). */
  body: unknown;
}

export interface Transport {
  /** method + app-relative path (e.g. "/api/v1/roles?per_page=100"); JSON body optional. */
  request(method: string, path: string, body?: unknown): Promise<TransportResponse>;
}
