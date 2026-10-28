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
  /** WHIT-697: null until an app first calls the server with it. */
  lastUsedAt: string | null;
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
 * `GET /api/v1/mcp/connect` (McpConnectHandler::info, WHIT-697): where an app
 * connects, whether it can yet, and the scope behind each preset.
 */
export interface McpConnectInfo {
  /** Null when the deployment's APP_URL is unset. */
  endpoint: string | null;
  serverEnabled: boolean;
  tenantEnabled: boolean;
  presets: {
    everything: string[];
    /** Null when no read permission exists to narrow to. */
    readOnly: string[] | null;
  };
  areas: { area: string; permissions: string[] }[];
}

/** One entry of `POST /api/v1/mcp/connect/preview`. */
export interface McpPreviewTool {
  name: string;
  description: string;
}

/** One entry of `GET /api/v1/mcp/tokens` — the caller's own tokens, snake_case. */
export interface OwnMcpToken {
  jti: string;
  name: string;
  last_used_at: string | null;
}
