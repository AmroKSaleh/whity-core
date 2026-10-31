<?php

declare(strict_types=1);

namespace Whity\Mcp\Auth;

use Whity\Mcp\Tools\ToolSurface;

/**
 * What an agent may do with each permission: Off, Ask first or Allowed
 * (WHIT-753; register: Scope grant; engineering handoff item 11;
 * docs/design/MCP-TOOL-SURFACE.md §5.11).
 *
 * Stored with the credential's scope, as entries `grant:<permission>=<mode>`
 * (`grant:invoices:send=allow`). A permission the grant doesn't name takes the
 * owner's defaults (decision 9): a read the person holds starts Allowed,
 * everything else starts Ask first. An operation gated by a role, or not gated,
 * has no permission to name: its reads are Allowed and its writes Ask first.
 *
 * The grant only ever narrows. RBAC is checked separately, against the person
 * who granted it, so an agent never does more than that person may do now.
 *
 * Two things lower an operation's mode further, whatever the grant says:
 *  - its route's `agentPolicy` ceiling (`refuse` → Off; `propose` and
 *    `suggest-only` → at most Ask first);
 *  - having no undo (decision 11): a write that can't be undone is always Ask
 *    first.
 *
 * Applies to `ai-agent` principals only. A person's own client acts as that
 * person and has no grant.
 */
final class AgentScopeGrant
{
    public const OFF   = 'off';
    public const ASK   = 'ask';
    public const ALLOW = 'allow';

    public const MODES = [self::OFF, self::ASK, self::ALLOW];

    public const PREFIX = 'grant:';

    /** @param array<string, string> $modes permission => mode, as granted */
    private function __construct(private readonly array $modes)
    {
    }

    /** @param list<string> $scope */
    public static function fromScope(array $scope): self
    {
        $modes = [];
        foreach ($scope as $entry) {
            $parsed = self::parse($entry);
            if ($parsed !== null) {
                $modes[$parsed[0]] = $parsed[1];
            }
        }
        return new self($modes);
    }

    /** The principal kind of an agent credential (ADR-0006 §3). Not minted until agent proposals exist (WHIT-784). */
    public const AGENT_KIND = 'ai-agent';

    /** Whether a grant governs this principal: agents only; a person's own client acts as that person. */
    public static function appliesTo(McpPrincipal $principal): bool
    {
        return $principal->principalKind === self::AGENT_KIND;
    }

    public static function of(McpPrincipal $principal): self
    {
        return self::fromScope(array_values(array_filter($principal->scope, 'is_string')));
    }

    /** The mode the grant gives a permission, or the default for an undeclared one. */
    public function modeFor(?string $permission, bool $readOnly): string
    {
        if ($permission !== null && $permission !== '' && isset($this->modes[$permission])) {
            return $this->modes[$permission];
        }
        return $readOnly ? self::ALLOW : self::ASK;
    }

    /**
     * What an agent may do with one operation: the grant's mode, lowered by the
     * route's ceiling and by having no undo.
     */
    public function effectiveMode(?string $permission, bool $readOnly, string $agentPolicy, bool $undoable): string
    {
        $mode = $this->modeFor($permission, $readOnly);

        $mode = match ($agentPolicy) {
            ToolSurface::AGENT_REFUSE                              => self::OFF,
            ToolSurface::AGENT_PROPOSE, ToolSurface::AGENT_SUGGEST_ONLY => self::lower($mode, self::ASK),
            default                                                => $mode,
        };

        if (!$readOnly && !$undoable) {
            $mode = self::lower($mode, self::ASK);
        }

        return $mode;
    }

    /**
     * Problems with grant entries requested at issuance; empty when fine.
     *
     * @param list<string> $scope
     * @param list<string> $knownPermissions
     * @return list<string>
     */
    public static function issuanceErrors(array $scope, array $knownPermissions): array
    {
        $errors = [];
        foreach ($scope as $entry) {
            if (!str_starts_with($entry, self::PREFIX)) {
                continue;
            }
            $parsed = self::parse($entry);
            if ($parsed === null) {
                $errors[] = "malformed grant: {$entry} (use grant:<permission>=off|ask|allow)";
            } elseif (!in_array($parsed[0], $knownPermissions, true)) {
                $errors[] = "unknown permission in grant: {$entry}";
            }
        }
        return $errors;
    }

    /** @return array{0: string, 1: string}|null */
    private static function parse(string $entry): ?array
    {
        if (!str_starts_with($entry, self::PREFIX)) {
            return null;
        }
        $body = substr($entry, strlen(self::PREFIX));
        $eq   = strrpos($body, '=');
        if ($eq === false) {
            return null;
        }
        $permission = substr($body, 0, $eq);
        $mode       = substr($body, $eq + 1);
        if ($permission === '' || !in_array($mode, self::MODES, true)) {
            return null;
        }
        return [$permission, $mode];
    }

    /** The stricter of two modes. */
    private static function lower(string $a, string $b): string
    {
        $rank = [self::OFF => 0, self::ASK => 1, self::ALLOW => 2];
        return $rank[$a] <= $rank[$b] ? $a : $b;
    }
}
