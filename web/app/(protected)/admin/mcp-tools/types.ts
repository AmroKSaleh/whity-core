/**
 * Types for the MCP Tools admin page (WC-0208ce4d, WHIT-754).
 *
 * `GET /api/v1/admin/mcp/tools` returns every tool an MCP client can be
 * offered in this workspace, core and plugin, with its access requirement and
 * its place on the tool surface (docs/design/MCP-TOOL-SURFACE.md §5.10).
 */

/** How a tool reaches a client: listed directly, found through search, or not at all. */
export type McpToolVisibility = 'always-on' | 'discoverable' | 'off';

/** What an AI agent may do with the tool on its own (design §5.11). */
export type McpAgentPolicy = 'allow' | 'propose' | 'suggest-only' | 'refuse';

export interface McpTool {
  /** Tool name, as an MCP client sees it. */
  name: string;
  description: string;
  /** Required permission slug, or null. */
  requiredPermission: string | null;
  /** Required role name, or null. */
  requiredRole: string | null;
  /** The toolset it belongs to, e.g. `core.documents` or `period-close`. */
  toolset: string;
  visibility: McpToolVisibility;
  agentPolicy: McpAgentPolicy;
  /** The plugin class that authored it, or null for a core operation. */
  plugin: string | null;
  /** A plugin tool that asks to be listed directly. */
  alwaysOnRequested: boolean;
  /** Listed directly in this workspace: a core pick, or a plugin tool the admin turned on. */
  alwaysOnEnabled: boolean;
}

export interface McpListSize {
  tools: number;
  bytes: number;
}

export interface McpToolListResponse {
  data: McpTool[];
  /** What each list mode costs a client before it does anything, for an administrator. */
  sizes?: {
    default: McpListSize;
    full: McpListSize;
    discover: McpListSize;
  };
}
