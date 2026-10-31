<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Agent\AgentProposalRepository;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\AgentScopeGrant;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\JsonRpc\McpException;
use Whity\Mcp\Tools\ToolsCallHandler;

/**
 * The approvals inbox for agent proposals (WHIT-784; register 7.5a Proposal,
 * 7.5b Approvals inbox; docs/design/MCP-TOOL-SURFACE.md §5.11).
 *
 *   GET  /api/me/agent-proposals                 the caller's proposals (?status=waiting)
 *   POST /api/me/agent-proposals/{id}/approve    run it, optionally with edited `arguments`
 *   POST /api/me/agent-proposals/{id}/decline    don't
 *
 * ONLY THE GRANTING PERSON DECIDES (owner decision 10), because approving runs
 * the operation as their delegate. Every query is scoped to the caller's
 * tenant and to proposals made on their behalf; anyone else's id answers 404.
 *
 * These routes are deliberately NOT on the MCP surface: an agent must never be
 * able to approve its own proposal. They carry no route gate and are not in
 * CoreApiSchemas::AGENT_CALLABLE_UNGATED, so the deriver never offers them.
 *
 * Approving re-runs every check through {@see ToolsCallHandler::runApproved()}:
 * exposure, the credential's toolsets and grant as they were when it asked (a
 * grant turned Off since then still wins), and RBAC for the person now. The
 * decision is taken atomically first, so a double click runs the call once.
 */
final class AgentProposalsApiHandler
{
    public function __construct(
        private readonly AgentProposalRepository $proposals,
        private readonly ToolsCallHandler $toolsCall,
        private readonly ?AuditLoggerInterface $audit = null,
    ) {
    }

    /** @param array<string, string> $params */
    public function list(Request $request, array $params = []): Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }
        [$tenantId, $profileId] = $caller;

        parse_str((string) parse_url($request->getPath(), PHP_URL_QUERY), $query);
        $status = $query['status'] ?? $_GET['status'] ?? null;
        $status = is_string($status) && in_array($status, [
            AgentProposalRepository::WAITING, AgentProposalRepository::APPROVED, AgentProposalRepository::DECLINED,
            AgentProposalRepository::EXPIRED, AgentProposalRepository::UNDONE,
        ], true) ? $status : null;

        $rows  = $this->proposals->listFor($tenantId, $profileId, $status);
        $names = $this->proposals->agentNames($tenantId, array_map(static fn (array $row): string => (string) $row['agent_principal_id'], $rows));

        return Response::json(['data' => array_map(static fn (array $row): array => self::present($row, $names), $rows)], 200);
    }

    /** @param array<string, string> $params */
    public function approve(Request $request, array $params = []): Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }
        [$tenantId, $profileId] = $caller;
        $id = (int) ($params['id'] ?? 0);

        $body      = json_decode($request->getBody(), true);
        $arguments = is_array($body) && array_key_exists('arguments', $body) ? $body['arguments'] : null;
        if ($arguments !== null && !is_array($arguments)) {
            return Response::error('arguments must be an object', 422);
        }

        $proposal = $this->proposals->find($id, $tenantId);
        if ($proposal === null || $proposal['on_behalf_of_profile_id'] !== $profileId) {
            return Response::error('Proposal not found', 404);
        }
        // Atomic: only the caller that moves it from waiting runs it.
        if (!$this->proposals->decide($id, $tenantId, $profileId, AgentProposalRepository::APPROVED, $arguments)) {
            return Response::error('This proposal is no longer waiting', 409);
        }

        $agent = new McpPrincipal(
            $profileId,
            $profileId,
            $tenantId,
            AgentScopeGrant::AGENT_KIND,
            $proposal['scope'],
            $proposal['agent_principal_id'],
        );
        try {
            $result = $this->toolsCall->runApproved($agent, $proposal['tool'], $arguments ?? $proposal['arguments']);
        } catch (McpException $e) {
            // A check that passed when the agent asked and fails now (the grant
            // was turned Off, a permission was removed): recorded as the run's
            // outcome, so the card can say what happened.
            $result = ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
        }
        $this->proposals->recordResult($id, $tenantId, $result);
        $this->audit?->record('agent.proposal.approved', [
            'tenant_id'   => $tenantId,
            'target_type' => 'agent_proposal',
            'target_id'   => $id,
            'metadata'    => ['tool' => $proposal['tool'], 'edited' => $arguments !== null, 'failed' => is_array($result) && ($result['isError'] ?? false) === true],
        ]);

        $fresh = $this->proposals->find($id, $tenantId);
        return Response::json(['data' => $fresh !== null ? self::present($fresh, $this->proposals->agentNames($tenantId, [(string) $fresh['agent_principal_id']])) : null], 200);
    }

    /** @param array<string, string> $params */
    public function decline(Request $request, array $params = []): Response
    {
        $caller = $this->caller($request);
        if ($caller instanceof Response) {
            return $caller;
        }
        [$tenantId, $profileId] = $caller;
        $id = (int) ($params['id'] ?? 0);

        $proposal = $this->proposals->find($id, $tenantId);
        if ($proposal === null || $proposal['on_behalf_of_profile_id'] !== $profileId) {
            return Response::error('Proposal not found', 404);
        }
        if (!$this->proposals->decide($id, $tenantId, $profileId, AgentProposalRepository::DECLINED)) {
            return Response::error('This proposal is no longer waiting', 409);
        }
        $this->audit?->record('agent.proposal.declined', [
            'tenant_id'   => $tenantId,
            'target_type' => 'agent_proposal',
            'target_id'   => $id,
            'metadata'    => ['tool' => $proposal['tool']],
        ]);

        $fresh = $this->proposals->find($id, $tenantId);
        return Response::json(['data' => $fresh !== null ? self::present($fresh, $this->proposals->agentNames($tenantId, [(string) $fresh['agent_principal_id']])) : null], 200);
    }

    /** @return array{0: int, 1: int}|Response */
    private function caller(Request $request): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        $actor    = $request->user;
        $profile  = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;
        if ($tenantId === null || $profile === null) {
            return Response::error('Unauthenticated', 401);
        }
        return [$tenantId, $profile];
    }

    /**
     * What the inbox card shows. The agent's credential id and its full scope
     * stay server-side; the card needs what will happen, not how the token
     * is built.
     *
     * The agent is named by what its credential was called ("Billing
     * assistant"), never by its id (15-agents.md, Words).
     *
     * @param array<string, mixed>  $proposal
     * @param array<string, string> $agentNames principal id => credential name
     * @return array<string, mixed>
     */
    private static function present(array $proposal, array $agentNames = []): array
    {
        return [
            'id'                => $proposal['id'],
            'agent'             => ['name' => $agentNames[(string) $proposal['agent_principal_id']] ?? null],
            'status'            => $proposal['status'],
            'tool'              => $proposal['tool'],
            'summary'           => $proposal['summary'],
            'arguments'         => $proposal['arguments'] === [] ? new \stdClass() : $proposal['arguments'],
            'diff'              => $proposal['diff'],
            'permissions'       => $proposal['permissions'],
            'undoWindowSeconds' => $proposal['undo_window_seconds'],
            'result'            => $proposal['result'],
            'decidedAt'         => $proposal['decided_at'],
            'expiresAt'         => $proposal['expires_at'],
            'createdAt'         => $proposal['created_at'],
        ];
    }
}
