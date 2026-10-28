import { forwardOAuthMetadata } from '@/lib/forward-oauth-metadata';

/**
 * RFC 9728 metadata at the path-suffixed location (`…/oauth-protected-resource/mcp`),
 * which clients try first for a resource at `/mcp` (WHIT-709).
 */
export async function GET() {
  return forwardOAuthMetadata('/.well-known/oauth-protected-resource/mcp');
}
