import { forwardOAuthMetadata } from '@/lib/forward-oauth-metadata';

/** RFC 9728 metadata for this origin's MCP endpoint, served by the backend (WHIT-709). */
export async function GET() {
  return forwardOAuthMetadata('/.well-known/oauth-protected-resource');
}
