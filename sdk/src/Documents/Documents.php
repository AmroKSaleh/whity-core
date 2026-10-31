<?php

declare(strict_types=1);

namespace Whity\Sdk\Documents;

/**
 * Issue documents from the workspace's templates (SDK 1.56, WHIT-742,
 * DOCUMENT-AUTOMATION.md 3.9). Resolve it with
 * `\Whity\app(\Whity\Sdk\Documents\Documents::class)`.
 *
 * The host implements it; a plugin never does. It acts as the person whose
 * request it is in, with their permissions, and records them as the issuer:
 * a plugin can't widen what that person may do. Outside a request (a job, a
 * listener in the relay) there is nobody to act for, and issuing is refused
 * with {@see DocumentNotIssuedException::NO_ACTOR} until the workspace's
 * background-issuing allow-list arrives.
 *
 * For documents from templates this replaces `DocumentRenderer::issue()`:
 * they carry the workspace's letterhead, theme, fields and QR code.
 */
interface Documents
{
    /** Whether this host can issue now: there is a workspace and a person. */
    public function canIssue(): bool;

    /**
     * Issue a document, or return the one `originKey` already names.
     *
     * @throws DocumentNotIssuedException when nothing was issued, with why
     */
    public function issue(IssueRequest $request): IssueResult;
}
