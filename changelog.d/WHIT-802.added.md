- **The OAuth sign-in server can ask which workspaces a person belongs to.**
  `GET /api/v1/oauth/workspaces` answers the authorization server configured
  as `MCP_OAUTH_ISSUER` with the person's active workspaces (id, name, slug), so
  its consent screen can let them pick one for the AI app they are connecting.
  It accepts only a short-lived assertion signed with that server's published
  key, and is never offered to agents as an MCP tool (WHIT-802).
