/**
 * How each AI app connects to a Whity MCP server (WHIT-697).
 *
 * Pure data and string building, so every client — the web admin, the desktop
 * shell, a whitier's own frontend — offers the same setup for the same app. No
 * user-facing prose lives here: the steps around a snippet are the consumer's
 * to translate. What this returns is what the app itself reads (a command, a
 * file, an install link), which is never translated.
 *
 * The formats were checked against each vendor's documentation on 2026-10-28.
 * They change, so each entry records where its format comes from, and an
 * install link nobody has confirmed against the vendor's own docs for a remote
 * server says so with `verified: false`. The consumer should always show the
 * copy-paste setup beside such a link, so a link that stops working costs the
 * user a click, not the connection.
 */

/** The apps the connect wizard offers, in gallery order. */
export type McpClientId =
  | 'claude-code'
  | 'cursor'
  | 'vscode'
  | 'windsurf'
  | 'codex'
  | 'gemini-cli'
  | 'n8n'
  | 'claude'
  | 'chatgpt'
  | 'other'

/** One connection: the server name the app will show, where, and the token. */
export interface McpConnection {
  /** Shown in the app's server list. Reduced to a safe slug by {@link mcpServerName}. */
  name: string
  url: string
  token: string
}

export interface McpInstallLink {
  href: string
  /** False when the vendor documents the scheme but not for a remote server with headers. */
  verified: boolean
}

export interface McpCommand {
  text: string
}

export interface McpConfigFile {
  /** Where the app reads it. Several, when the app reads more than one place. */
  paths: string[]
  language: 'json' | 'toml'
  text: string
}

/** For apps configured through a form rather than a file: n8n, "other". */
export interface McpFormField {
  /** A stable key the consumer maps to a translated label. */
  key: 'endpoint' | 'transport' | 'authentication' | 'token' | 'headerName' | 'headerValue'
  value: string
  /** Whether the value is the secret, so the consumer can mask or warn. */
  secret?: boolean
}

export interface McpClientSetup {
  install?: McpInstallLink
  command?: McpCommand
  file?: McpConfigFile
  fields?: McpFormField[]
}

export interface McpClientSpec {
  id: McpClientId
  /** The product's own name. A proper noun, so it is not translated. */
  name: string
  /**
   * Apps that connect only through sign-in (OAuth), which this server does not
   * offer yet (WHIT-698). The consumer shows them, marked as coming, so a user
   * looking for them learns why rather than assuming they were forgotten.
   */
  requiresSignIn?: boolean
  /** The vendor page the format was checked against. */
  docs: string
  /**
   * How many tools the app is reported to handle before it drops or ignores
   * some. Community-reported, not vendor-documented (2026-10): treat as a
   * guide for {@link recommendsToolSearch}, not a contract.
   */
  toolLimit?: number
  setup?: (connection: McpConnection) => McpClientSetup
}

/**
 * Past roughly this many tools, agents reportedly choose tools less reliably
 * and every request carries tens of thousands of tokens of definitions, even
 * in an app with no hard cap. A rule of thumb, not a limit.
 */
export const MCP_TOOL_BUDGET = 60

/**
 * Whether this access is too big to load whole into the app: true when the
 * tools it would expose exceed what the app is reported to handle, or the
 * general budget. The plain `/mcp` already lists only search and call
 * (WHIT-748), so this decides whether the wizard warns against `?tools=full`.
 */
export function recommendsToolSearch(client: McpClientSpec, toolCount: number): boolean {
  return toolCount > Math.min(client.toolLimit ?? MCP_TOOL_BUDGET, MCP_TOOL_BUDGET)
}

/**
 * The URL an app connects to: the server's MCP endpoint, which lists a few
 * tools and searches for the rest (WHIT-748), or every tool at once when
 * asked (`?tools=full`). Keeps any query the endpoint already carries.
 */
export function mcpEndpoint(endpoint: string, options: { allTools?: boolean } = {}): string {
  if (options.allTools !== true) return endpoint
  const [base, hash = ''] = endpoint.split('#', 2)
  const separator = base.includes('?') ? '&' : '?'
  return `${base}${separator}tools=full${hash !== '' ? `#${hash}` : ''}`
}

/**
 * The server key an app stores the connection under: lowercase letters, digits
 * and dashes, which every client accepts as a JSON key, a TOML table name and a
 * bare command-line argument.
 */
export function mcpServerName(name: string): string {
  const slug = name
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 48)
    .replace(/-+$/g, '')
  return slug === '' ? 'whity' : slug
}

function bearer(token: string): string {
  return `Bearer ${token}`
}

function json(value: unknown): string {
  return JSON.stringify(value, null, 2)
}

/** Base64 of UTF-8, the way Cursor decodes its `config` parameter. */
function base64(text: string): string {
  const bytes = new TextEncoder().encode(text)
  let binary = ''
  for (const byte of bytes) binary += String.fromCharCode(byte)
  return btoa(binary)
}

/** A TOML basic string. */
function tomlString(value: string): string {
  return `"${value.replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"`
}

/** A double-quoted shell argument, which bash, zsh, PowerShell and cmd all read. */
function shellArg(value: string): string {
  return `"${value.replace(/(["\\$`])/g, '\\$1')}"`
}

export const MCP_CLIENTS: readonly McpClientSpec[] = [
  {
    id: 'claude-code',
    name: 'Claude Code',
    docs: 'https://code.claude.com/docs/en/mcp',
    setup: ({ name, url, token }) => {
      const server = mcpServerName(name)
      return {
        command: {
          text: `claude mcp add --transport http ${server} ${shellArg(url)} --header ${shellArg(`Authorization: ${bearer(token)}`)}`,
        },
        file: {
          paths: ['.mcp.json'],
          language: 'json',
          text: json({ mcpServers: { [server]: { type: 'http', url, headers: { Authorization: bearer(token) } } } }),
        },
      }
    },
  },
  {
    id: 'cursor',
    name: 'Cursor',
    // Widely reported in Cursor's forum: tools past 40 are silently dropped.
    toolLimit: 40,
    docs: 'https://cursor.com/docs/context/mcp/install-links',
    setup: ({ name, url, token }) => {
      const server = mcpServerName(name)
      // The inner server object, without the mcpServers wrapper or its key.
      const config = { url, headers: { Authorization: bearer(token) } }
      return {
        install: {
          href:
            'cursor://anysphere.cursor-deeplink/mcp/install?name=' +
            encodeURIComponent(server) +
            '&config=' +
            encodeURIComponent(base64(JSON.stringify(config))),
          verified: false,
        },
        file: {
          paths: ['~/.cursor/mcp.json', '.cursor/mcp.json'],
          language: 'json',
          text: json({ mcpServers: { [server]: config } }),
        },
      }
    },
  },
  {
    id: 'vscode',
    name: 'VS Code',
    docs: 'https://code.visualstudio.com/docs/agents/reference/mcp-configuration',
    setup: ({ name, url, token }) => {
      const server = mcpServerName(name)
      const config = { type: 'http', url, headers: { Authorization: bearer(token) } }
      return {
        install: {
          href: 'vscode:mcp/install?' + encodeURIComponent(JSON.stringify({ name: server, ...config })),
          verified: false,
        },
        file: {
          // VS Code's own file uses `servers`, not `mcpServers`.
          paths: ['.vscode/mcp.json'],
          language: 'json',
          text: json({ servers: { [server]: config } }),
        },
      }
    },
  },
  {
    id: 'windsurf',
    name: 'Windsurf',
    toolLimit: 100,
    docs: 'https://docs.devin.ai/desktop/cascade/mcp',
    setup: ({ name, url, token }) => ({
      file: {
        paths: ['~/.config/devin/mcp_config.json', '%APPDATA%\\devin\\mcp_config.json'],
        language: 'json',
        // Windsurf documents `serverUrl` for remote servers.
        text: json({ mcpServers: { [mcpServerName(name)]: { serverUrl: url, headers: { Authorization: bearer(token) } } } }),
      },
    }),
  },
  {
    id: 'codex',
    name: 'Codex CLI',
    docs: 'https://developers.openai.com/codex/mcp',
    setup: ({ name, url, token }) => ({
      // `codex mcp add` documents only OAuth flags, so the file is the way in.
      file: {
        paths: ['~/.codex/config.toml'],
        language: 'toml',
        text:
          `[mcp_servers.${mcpServerName(name)}]\n` +
          `url = ${tomlString(url)}\n` +
          `http_headers = { "Authorization" = ${tomlString(bearer(token))} }\n`,
      },
    }),
  },
  {
    id: 'gemini-cli',
    name: 'Gemini CLI',
    docs: 'https://geminicli.com/docs/tools/mcp-server/',
    setup: ({ name, url, token }) => {
      const server = mcpServerName(name)
      return {
        command: {
          text: `gemini mcp add --transport http --scope user --header ${shellArg(`Authorization: ${bearer(token)}`)} ${server} ${shellArg(url)}`,
        },
        file: {
          paths: ['~/.gemini/settings.json'],
          language: 'json',
          // `httpUrl` is streamable HTTP; Gemini reads `url` as SSE.
          text: json({ mcpServers: { [server]: { httpUrl: url, headers: { Authorization: bearer(token) } } } }),
        },
      }
    },
  },
  {
    id: 'n8n',
    name: 'n8n',
    docs: 'https://docs.n8n.io/integrations/builtin/cluster-nodes/sub-nodes/n8n-nodes-langchain.toolmcp/',
    setup: ({ url, token }) => ({
      fields: [
        { key: 'endpoint', value: url },
        { key: 'transport', value: 'HTTP Streamable' },
        { key: 'authentication', value: 'Bearer Auth' },
        // n8n adds the "Bearer " prefix itself in Bearer Auth mode.
        { key: 'token', value: token, secret: true },
      ],
    }),
  },
  { id: 'claude', name: 'Claude', requiresSignIn: true, docs: 'https://www.claude.com/docs/connectors/custom/remote-mcp' },
  { id: 'chatgpt', name: 'ChatGPT', requiresSignIn: true, docs: 'https://developers.openai.com/apps-sdk/build/auth' },
  {
    id: 'other',
    name: 'Other',
    docs: 'https://modelcontextprotocol.io/specification/2025-03-26/basic/transports',
    setup: ({ name, url, token }) => ({
      fields: [
        { key: 'endpoint', value: url },
        { key: 'transport', value: 'Streamable HTTP' },
        { key: 'headerName', value: 'Authorization' },
        { key: 'headerValue', value: bearer(token), secret: true },
      ],
      file: {
        paths: [],
        language: 'json',
        text: json({ mcpServers: { [mcpServerName(name)]: { type: 'http', url, headers: { Authorization: bearer(token) } } } }),
      },
    }),
  },
]

export function mcpClient(id: McpClientId): McpClientSpec {
  const spec = MCP_CLIENTS.find((client) => client.id === id)
  if (spec === undefined) throw new Error(`unknown MCP client: ${id}`)
  return spec
}
