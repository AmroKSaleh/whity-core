/**
 * Types for the AI Principals admin page (WC-0208ce4d).
 *
 * Mirror the public contract returned by
 * `GET /api/v1/admin/mcp/tokens` (AiPrincipalsApiHandler::toPublicToken)
 * and `POST /api/v1/mcp/tokens` (McpTokenHandler::create).
 */

export interface AiPrincipal {
  id: number;
  jti: string;
  userId: number;
  tenantId: number;
  name: string;
  principalKind: string;
  scope: string[];
  expiresAt: string | null;
  createdAt: string | null;
}

export interface AiPrincipalPagination {
  page: number;
  perPage: number;
  total: number;
  totalPages: number;
}

export interface AiPrincipalListResponse {
  data: AiPrincipal[];
  pagination: AiPrincipalPagination;
}

/**
 * The one-time credential returned immediately after a successful
 * POST /api/v1/mcp/tokens. The raw `token` value is never surfaced again
 * by the API; the UI must show it once in a modal.
 */
export interface NewCredential {
  jti: string;
  token: string;
  name: string;
  scope: string[];
  expiresAt: string;
}
