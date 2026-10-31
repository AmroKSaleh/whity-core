<?php

declare(strict_types=1);

namespace Whity\Core\Document\Issuing;

use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Container\HostWiredService;
use Whity\Core\Document\DocumentPresenter;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Documents\DocumentNotIssuedException;
use Whity\Sdk\Documents\Documents;
use Whity\Sdk\Documents\IssueRequest;
use Whity\Sdk\Documents\IssueResult;

/**
 * The SDK's {@see Documents} (SDK 1.56, WHIT-742): a plugin issues through
 * the same {@see TemplateIssuing} as `POST /api/documents`, as the person
 * whose request it is in. The tenant and the person come from the host's own
 * request context, never from the plugin, so it can't issue for another
 * workspace or as somebody else.
 *
 * Outside a request there is no person, and it refuses with `no_actor` until
 * the background-issuing allow-list lands (DOCUMENT-AUTOMATION.md 3.7).
 */
final class SdkDocuments implements Documents, HostWiredService
{
    /** @var TemplateIssuing|\Closure(): TemplateIssuing */
    private TemplateIssuing|\Closure $issuing;

    /** @param TemplateIssuing|\Closure(): TemplateIssuing $issuing */
    public function __construct(
        TemplateIssuing|\Closure $issuing,
        private readonly RoleChecker $roleChecker,
    ) {
        $this->issuing = $issuing;
    }

    public function canIssue(): bool
    {
        return $this->actor() !== null;
    }

    public function issue(IssueRequest $request): IssueResult
    {
        $actor = $this->actor();
        if ($actor === null) {
            throw new DocumentNotIssuedException(
                DocumentNotIssuedException::NO_ACTOR,
                'There is no person to issue this document for; plugins issue in a request, as the person making it',
            );
        }

        try {
            $issued = $this->issuing()->issue($actor, new IssueInput(
                slot: $request->template->slot,
                sourceId: $request->template->sourceId,
                record: $request->record,
                values: $request->values === [] ? null : $request->values,
                title: $request->title,
                originKey: $request->originKey,
                render: $request->render,
            ));
        } catch (DocumentIssueRefused $e) {
            throw new DocumentNotIssuedException($e->reason, $e->clientMessage, $e->details);
        }

        $document = DocumentPresenter::document($issued['document'], $issued['artifacts']);

        return new IssueResult(
            (int) $document['id'],
            (string) $document['title'],
            $issued['replayed'],
            is_string($document['content_url'] ?? null) ? $document['content_url'] : null,
        );
    }

    private function actor(): ?ActingAs
    {
        $tenantId = TenantContext::getTenantId();
        $profileId = AuditContext::getActorUserId();
        if ($tenantId === null || $tenantId === 0 || $profileId === null) {
            return null;
        }

        return new ActingAs($tenantId, $profileId, ScopedPermissionSet::forProfile($this->roleChecker, $profileId, $tenantId));
    }

    private function issuing(): TemplateIssuing
    {
        if ($this->issuing instanceof \Closure) {
            $this->issuing = ($this->issuing)();
        }

        return $this->issuing;
    }
}
