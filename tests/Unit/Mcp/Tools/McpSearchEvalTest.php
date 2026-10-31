<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Tools;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Tools\Discovery\DiscoveryTools;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolsCallHandler;
use Whity\Mcp\Tools\ToolsListHandler;
use Whity\OpenAPI\CoreApiSchemas;

/**
 * Does search find what people ask for? (WHIT-750, docs/design/MCP-TOOL-SURFACE.md §5.5.)
 *
 * With search and call as the default, most operations are reached only
 * through `search_operations`, so its ranking is part of the product. Each
 * case is an everyday request, written the way a person or model would ask,
 * and the operation it should find in the top three, against core's real
 * catalogue. A ranking change that loses one of these fails the build: fix
 * the ranking or the operation's summary, not the case.
 */
final class McpSearchEvalTest extends TestCase
{
    private const TOP = 3;

    private static ?DiscoveryTools $discovery = null;

    public static function setUpBeforeClass(): void
    {
        ToolDeriver::clearCache();
        TenantContext::setTenantId(1);
        $router  = new Router('');
        $deriver = new ToolDeriver(CoreApiSchemas::routes(), CoreApiSchemas::components(), $router, static fn (string $m): null => null);
        // An administrator who holds everything: the eval measures ranking, not access.
        $roles = (new self('eval'))->createStub(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturn(true);
        $roles->method('hasRoleForProfile')->willReturn(true);
        $tokens = (new self('eval'))->createStub(TokenValidator::class);
        $tokens->method('validateBearerForMcp')->willReturn(new McpPrincipal(1, 1, 1, 'user', ['tools:call'], 'jti-eval'));

        self::$discovery = new DiscoveryTools(
            new ToolsListHandler($deriver, $roles, $tokens),
            new ToolsCallHandler($deriver, $router, $roles, $tokens),
            $tokens,
        );
    }

    public static function tearDownAfterClass(): void
    {
        ToolDeriver::clearCache();
        TenantContext::reset();
    }

    /** @return array<string, array{string, string}> */
    public static function requests(): array
    {
        return [
            'who am I'                      => ['who am i', 'get_api_me'],
            'my permissions'                => ['my permissions', 'get_api_me_capabilities'],
            'my notifications'              => ['my notifications', 'get_api_me_notifications'],
            'unread notifications'          => ['unread notification count', 'get_api_me_notifications_unread_count'],
            'my inbox'                      => ['inbox items waiting for me', 'get_api_me_inbox'],
            'search records'                => ['search records', 'get_api_search'],
            'list users'                    => ['list users', 'get_api_users'],
            'one user'                      => ['get user', 'get_api_users_id'],
            'delete a user'                 => ['delete user', 'delete_api_users_id'],
            'invite someone'                => ['invite people', 'post_api_invitations'],
            'list roles'                    => ['list roles', 'get_api_roles'],
            'create a role'                 => ['create role', 'post_api_roles'],
            'org units'                     => ['organizational units', 'get_api_ous'],
            'list forms'                    => ['list forms', 'get_api_forms'],
            'create a form'                 => ['create form', 'post_api_forms'],
            'form submissions'              => ['my form submissions', 'get_api_me_form_submissions'],
            'list documents'                => ['list documents', 'get_api_documents'],
            'one document'                  => ['get document', 'get_api_documents_id'],
            'document templates'            => ['document templates', 'get_api_document_templates'],
            'document trail'                => ['document routing trail', 'get_api_documents_id_trail'],
            'meetings'                      => ['list meetings', 'get_api_meetings'],
            'convening bodies'              => ['convening bodies', 'get_api_convening_bodies'],
            'tags'                          => ['list tags', 'get_api_tags'],
            'tag groups'                    => ['tag groups', 'get_api_tag_groups'],
            'invoices'                      => ['billing invoices', 'get_api_billing_invoices'],
            'audit log'                     => ['audit logs', 'get_api_audit_logs'],
            'settings'                      => ['workspace settings', 'get_api_settings'],
            'change settings'               => ['update settings', 'patch_api_settings'],
            // GET /api/languages is public and ungated, so not on the agent surface.
            'languages'                     => ['languages', 'get_api_admin_languages'],
            'user groups'                   => ['user groups', 'get_api_user_groups'],
            'delegations'                   => ['delegations', 'get_api_delegations'],
            'time windows'                  => ['time windows', 'get_api_time_windows'],
            'route templates'               => ['document route templates', 'get_api_document_route_templates'],
            'Arabic: documents'             => ['المستندات', 'get_api_documents'],
            'Arabic: forms'                 => ['النماذج', 'get_api_forms'],
            'Arabic: meetings'              => ['الاجتماعات', 'get_api_meetings'],
        ];
    }

    #[DataProvider('requests')]
    public function testSearchFindsTheOperationAPersonMeans(string $query, string $expected): void
    {
        $result = (self::$discovery?->callHandler())(['name' => 'search_operations', 'arguments' => ['query' => $query, 'limit' => 10]], 'bearer');
        self::assertIsArray($result);
        $found = json_decode($result['content'][0]['text'], true);
        $names = array_column($found['matches'] ?? [], 'operationId');

        self::assertContains(
            $expected,
            array_slice($names, 0, self::TOP),
            "\"{$query}\" should find {$expected} in the top " . self::TOP . '; got: ' . implode(', ', array_slice($names, 0, 6)),
        );
    }
}
