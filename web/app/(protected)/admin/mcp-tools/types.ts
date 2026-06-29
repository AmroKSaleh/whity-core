/**
 * Types for the MCP Tools admin page (WC-0208ce4d).
 *
 * The page derives tool metadata from the live OpenAPI spec
 * (`GET /api/openapi.json`) so no additional backend endpoint is needed.
 * Each tool corresponds to a schema-bearing route that the ToolDeriver would
 * expose via tools/list to an authenticated MCP client.
 */

export interface McpTool {
  /** Derived operationId / tool name. */
  name: string;
  /** Human-readable description from the route schema. */
  description: string;
  /** HTTP method the tool maps to. */
  method: string;
  /** API path pattern the tool maps to (e.g. /api/v1/users). */
  path: string;
  /** Required permission slug, or null for open tools. */
  requiredPermission: string | null;
  /** Required role name, or null for open tools. */
  requiredRole: string | null;
}

/**
 * Minimal subset of the OpenAPI 3.x path item we read.
 */
export interface OpenApiPathItem {
  summary?: string;
  description?: string;
  operationId?: string;
  security?: unknown[];
  [key: string]: unknown;
}

export interface OpenApiSpec {
  paths?: Record<string, Record<string, OpenApiPathItem>>;
  [key: string]: unknown;
}
