<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Convening\AgendaRepository;
use Whity\Core\Convening\AttendanceEntry;
use Whity\Core\Convening\AttendanceRepository;
use Whity\Core\Convening\ConveningBodyRepository;
use Whity\Core\Convening\ConveningRejectedException;
use Whity\Core\Convening\DecisionRecorder;
use Whity\Core\Convening\DecisionRepository;
use Whity\Core\Convening\InvitationRepository;
use Whity\Core\Convening\InvitationStatus;
use Whity\Core\Convening\LocalizedText;
use Whity\Core\Convening\MeetingRepository;
use Whity\Core\Convening\MeetingService;
use Whity\Core\Convening\MeetingStatus;
use Whity\Core\Document\Routing\RoutingRejectedException;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\InputLimits;
use Whity\Http\JsonBody;

/**
 * Sittings: their agenda, their invitations, and the decisions taken at them.
 *
 * PERMISSIONS, AND THE ONE ROUTE THAT HAS NONE
 * --------------------------------------------
 * Reading on `convening:read`. Building an agenda, scheduling, inviting, holding
 * and cancelling on `convening:manage`. RECORDING A DECISION on
 * `convening:decide`, which is a separate gate because it is the only act on this
 * surface that can approve or reject somebody's document.
 *
 * `POST .../invitations/respond` is registered with NO permission at all, and
 * that is deliberate. Being invited IS the authorization — the same argument
 * migration 113 makes about acting on a route that reached you, and the same
 * posture `/api/me/notifications` and `/api/me/sessions` take. Gating it on
 * anything would mean a body could invite somebody who is then unable to answer,
 * and the chair would count them as silent for ever. The handler enforces the
 * question a tenant-wide slug cannot ask: that the caller is answering THEIR OWN
 * invitation, resolved from the session and never from the request body.
 *
 * THE DETAIL READ CARRIES EVERYTHING AT ONCE
 * ------------------------------------------
 * `GET /api/v1/meetings/{id}` returns the meeting, its body, its agenda, the
 * decisions taken, who was invited and who attended — six reads behind one
 * request, because nobody has ever wanted five of them. A screen that fetched
 * them separately would render an agenda before it knew which items had been
 * decided, which is the one arrangement guaranteed to look like a bug.
 *
 * ATTENDANCE READS ON `convening:read`, WRITES ON `convening:manage`
 * -----------------------------------------------------------------
 * The write is a secretarial act of exactly the kind `convening:manage` already
 * covers. The READ is deliberately the ordinary read gate and not the manage
 * one, and the reason is who holds each: migration 131 grants `convening:read`
 * to `settings:read` AND `documents:route`, and `convening:manage` only to
 * `settings:write`. Gating the attendance read on manage would mean somebody who
 * can already see this meeting's INVITATIONS and its DECISIONS — strictly more
 * sensitive material — gets a 403 on the list of who was in the room, and the
 * meeting-record screen (itself gated on `convening:read`) would render one
 * empty table among five populated ones with no explanation. One subsystem, one
 * read gate.
 */
final class MeetingsApiHandler
{
    public function __construct(
        private readonly ConveningBodyRepository $bodies,
        private readonly MeetingRepository $meetings,
        private readonly AgendaRepository $agenda,
        private readonly DecisionRepository $decisions,
        private readonly InvitationRepository $invitations,
        private readonly AttendanceRepository $attendance,
        private readonly MeetingService $service,
        private readonly DecisionRecorder $recorder,
    ) {
    }

    /**
     * GET /api/v1/meetings — sittings, narrowed by body and/or status.
     */
    public function list(Request $request): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $query = self::queryParams($request);

            $bodyId = null;
            if (isset($query['body_id']) && $query['body_id'] !== '') {
                if (preg_match('/^\d+$/', $query['body_id']) !== 1) {
                    return Response::error('body_id must be an integer', 422);
                }
                $bodyId = (int) $query['body_id'];
            }

            $statuses = [];
            if (isset($query['status']) && $query['status'] !== '') {
                foreach (explode(',', $query['status']) as $status) {
                    $status = trim($status);
                    if ($status === '') {
                        continue;
                    }
                    if (!MeetingStatus::isValid($status)) {
                        return Response::error(
                            'status must be one or more of: ' . implode(', ', MeetingStatus::all()),
                            422
                        );
                    }
                    $statuses[] = $status;
                }
            }

            return Response::json([
                'data' => $this->meetings->listForTenant($tenantId, $bodyId, $statuses),
            ]);
        } catch (\Exception $e) {
            error_log('[MeetingsApiHandler] list failed: ' . $e->getMessage());

            return Response::error('Failed to fetch meetings', 500);
        }
    }

    /**
     * GET /api/v1/meetings/{id} — the whole sitting.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $id = (int) ($params['id'] ?? 0);
            $meeting = $this->meetings->find($tenantId, $id);
            if ($meeting === null) {
                return Response::error('Meeting not found', 404);
            }

            return Response::json(['data' => $this->detail($tenantId, $meeting)]);
        } catch (\Exception $e) {
            error_log('[MeetingsApiHandler] show failed: ' . $e->getMessage());

            return Response::error('Failed to fetch meeting', 500);
        }
    }

    /**
     * POST /api/v1/meetings — open a sitting on a body, in draft.
     */
    public function create(Request $request): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $body = JsonBody::parsed($request);
            $bodyId = self::intField($body, 'body_id');
            if ($bodyId === null) {
                return Response::error('body_id is required and must be an integer', 422);
            }

            $title = LocalizedText::normalize(
                $body['title'] ?? null,
                MeetingRepository::FALLBACK_LOCALE,
                'title'
            );

            $meeting = $this->service->create($tenantId, $bodyId, $title, self::actorProfileId($request));

            return Response::json(['data' => $meeting], 201);
        } catch (ConveningRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        } catch (\Exception $e) {
            error_log('[MeetingsApiHandler] create failed: ' . $e->getMessage());

            return Response::error('Failed to create meeting', 500);
        }
    }

    /**
     * POST /api/v1/meetings/{id}/schedule — fix a date and a place.
     *
     * @param array<string, string> $params
     */
    public function schedule(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $body = JsonBody::parsed($request);
            $scheduledAt = trim((string) ($body['scheduled_at'] ?? ''));
            if ($scheduledAt === '') {
                return Response::error('scheduled_at is required', 422);
            }

            $location = isset($body['location']) && is_string($body['location'])
                ? trim($body['location'])
                : null;
            if ($location !== null && ($tooLong = InputLimits::firstViolation([
                'location' => [$location, InputLimits::NAME_MAX],
            ]))) {
                return $tooLong;
            }

            $result = $this->service->schedule(
                $tenantId,
                (int) ($params['id'] ?? 0),
                $scheduledAt,
                $location === '' ? null : $location
            );

            return Response::json([
                'data' => $result['meeting'],
                // How many people were told the date moved. Reported rather than
                // silent: a secretary who moved a meeting needs to know whether
                // anybody has been re-notified, and zero is a real answer (the
                // sitting had not been announced yet).
                'notified' => $result['notified'],
            ]);
        } catch (ConveningRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        } catch (\Exception $e) {
            error_log('[MeetingsApiHandler] schedule failed: ' . $e->getMessage());

            return Response::error('Failed to schedule meeting', 500);
        }
    }

    /**
     * POST /api/v1/meetings/{id}/invitations — invite the body's current members.
     *
     * @param array<string, string> $params
     */
    public function invite(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $result = $this->service->invite($tenantId, (int) ($params['id'] ?? 0));

            return Response::json([
                'data' => $this->invitations->listForMeeting($tenantId, (int) ($params['id'] ?? 0)),
                // Both numbers, because they answer different questions: how many
                // people were newly told, and how many already held an invitation
                // and were deliberately left alone. A single count would make a
                // no-op re-send indistinguishable from a failure.
                'invited' => count($result['invited']),
                'already_invited' => $result['already_invited'],
            ]);
        } catch (ConveningRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        } catch (\Exception $e) {
            error_log('[MeetingsApiHandler] invite failed: ' . $e->getMessage());

            return Response::error('Failed to send invitations', 500);
        }
    }

    /**
     * POST /api/v1/meetings/{id}/invitations/respond — accept, decline, tentative.
     *
     * UNPERMISSIONED at the route, and self-scoped HERE: the profile answering is
     * read from the SESSION and never from the request body. A `profile_id` in
     * the body would be an answer-on-somebody-else's-behalf endpoint wearing the
     * clothes of a self-service one.
     *
     * @param array<string, string> $params
     */
    public function respond(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $profileId = self::actorProfileId($request);
            if ($profileId === null) {
                // A service principal has no profile and therefore no invitation.
                // Refused rather than tolerated: everywhere else in this codebase
                // a null actor is a trail row with an absent actor, but here the
                // actor IS the authorization.
                return Response::error('Answering an invitation requires a signed-in person', 403);
            }

            $body = JsonBody::parsed($request);
            $status = isset($body['status']) && is_string($body['status']) ? trim($body['status']) : '';
            if (!InvitationStatus::isResponse($status)) {
                return Response::error(
                    'status must be one of: ' . implode(', ', InvitationStatus::responses()),
                    422
                );
            }

            $result = $this->service->respond(
                $tenantId,
                (int) ($params['id'] ?? 0),
                $profileId,
                $status
            );

            return Response::json(['data' => $result['invitation']]);
        } catch (ConveningRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        } catch (\Exception $e) {
            error_log('[MeetingsApiHandler] respond failed: ' . $e->getMessage());

            return Response::error('Failed to record your answer', 500);
        }
    }

    /**
     * POST /api/v1/meetings/{id}/hold — record that the sitting took place.
     *
     * @param array<string, string> $params
     */
    public function hold(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $body = JsonBody::parsed($request);
            $heldAt = isset($body['held_at']) && is_string($body['held_at']) && trim($body['held_at']) !== ''
                ? trim($body['held_at'])
                : null;

            $meeting = $this->service->hold($tenantId, (int) ($params['id'] ?? 0), $heldAt);

            return Response::json(['data' => $meeting]);
        } catch (ConveningRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        } catch (\Exception $e) {
            error_log('[MeetingsApiHandler] hold failed: ' . $e->getMessage());

            return Response::error('Failed to record the meeting as held', 500);
        }
    }

    /**
     * POST /api/v1/meetings/{id}/cancel
     *
     * @param array<string, string> $params
     */
    public function cancel(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $meeting = $this->service->cancel($tenantId, (int) ($params['id'] ?? 0));

            return Response::json(['data' => $meeting]);
        } catch (ConveningRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        } catch (\Exception $e) {
            error_log('[MeetingsApiHandler] cancel failed: ' . $e->getMessage());

            return Response::error('Failed to cancel meeting', 500);
        }
    }

    /**
     * GET /api/v1/agenda-items?meeting_id=N
     *
     * FLAT COLLECTION READS, NESTED WRITES — the shape of this surface, and it is
     * deliberate rather than inconsistent. A tabular client (including the
     * server-driven block screens this subsystem ships) can only address a
     * COLLECTION with query parameters; it cannot build `/meetings/7/agenda` out
     * of a selection. A write, by contrast, is always an act ON a meeting, and
     * nesting it is what makes the parent's state — draft, held, cancelled —
     * part of the address rather than something the body has to restate.
     *
     * `meeting_id` is REQUIRED. An unfiltered agenda-item list across a tenant
     * is not a question anybody asks, and serving one would make an accidental
     * omission of the filter look like a working request.
     */
    public function agendaItems(Request $request): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $meetingId = self::requiredMeetingIdParam($request);
            if ($meetingId instanceof Response) {
                return $meetingId;
            }
            if ($this->meetings->find($tenantId, $meetingId) === null) {
                return Response::error('Meeting not found', 404);
            }

            return Response::json(['data' => $this->agenda->listForMeeting($tenantId, $meetingId)]);
        } catch (\Exception $e) {
            error_log('[MeetingsApiHandler] agendaItems failed: ' . $e->getMessage());

            return Response::error('Failed to fetch agenda', 500);
        }
    }

    /**
     * GET /api/v1/meeting-decisions?meeting_id=N
     */
    public function decisions(Request $request): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $meetingId = self::requiredMeetingIdParam($request);
            if ($meetingId instanceof Response) {
                return $meetingId;
            }
            if ($this->meetings->find($tenantId, $meetingId) === null) {
                return Response::error('Meeting not found', 404);
            }

            return Response::json(['data' => $this->decisions->listForMeeting($tenantId, $meetingId)]);
        } catch (\Exception $e) {
            error_log('[MeetingsApiHandler] decisions failed: ' . $e->getMessage());

            return Response::error('Failed to fetch decisions', 500);
        }
    }

    /**
     * PUT /api/v1/meetings/{id}/attendance — record who was actually there.
     *
     * PUT AND NOT POST, because the act is a REPLACEMENT of the whole list and
     * the method should say so. A secretary reads a sign-in sheet and asserts
     * "these are the people who were here" — a statement about the entire set,
     * made once, corrected by making it again. POST would suggest each call
     * ADDS somebody, and a client written against that reading would double the
     * list on every retry.
     *
     * WHICH MEANS AN OMITTED PERSON IS REMOVED, and that is said out loud in
     * the screen's own text and in the OpenAPI description rather than left to
     * be discovered. It is also why {@see AttendanceEntry::parseSet()} validates
     * the whole payload before any of it is written: a list whose ninth entry
     * names nobody must leave the stored attendance exactly as it was, not
     * truncated to the eight that parsed.
     *
     * REFUSED BEFORE THE MEETING IS HELD — see
     * {@see \Whity\Core\Convening\MeetingService::recordAttendance()}, which
     * carries the reasoning and the sentence the caller gets.
     *
     * @param array<string, string> $params
     */
    public function recordAttendance(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $meetingId = (int) ($params['id'] ?? 0);
            if ($this->meetings->find($tenantId, $meetingId) === null) {
                return Response::error('Meeting not found', 404);
            }

            $body = JsonBody::parsed($request);

            // `attendees` is REQUIRED and its absence is not an empty list. A
            // client that forgot the key means something different from one
            // that sent `[]` — the second is "nobody attended", which is a real
            // and recordable fact, and treating the first as the second would
            // let a malformed request erase a minute.
            if (!array_key_exists('attendees', $body)) {
                return Response::error(
                    'attendees is required. It replaces this meeting\'s whole attendance list, so '
                    . 'send every person who was present — including anybody who was not invited. '
                    . 'Send [] to record that nobody attended.',
                    422
                );
            }

            $result = $this->service->recordAttendance(
                $tenantId,
                $meetingId,
                AttendanceEntry::parseSet($body['attendees']),
                self::actorProfileId($request)
            );

            return Response::json([
                'data' => $result['attendance'],
                'counted' => self::counted($tenantId, $meetingId, $result['attendance']),
            ]);
        } catch (ConveningRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        } catch (\Exception $e) {
            error_log('[MeetingsApiHandler] recordAttendance failed: ' . $e->getMessage());

            return Response::error('Failed to record attendance', 500);
        }
    }

    /**
     * GET /api/v1/meeting-attendees?meeting_id=N — who attended, and what each
     * of them had said beforehand.
     */
    public function attendance(Request $request): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $meetingId = self::requiredMeetingIdParam($request);
            if ($meetingId instanceof Response) {
                return $meetingId;
            }
            if ($this->meetings->find($tenantId, $meetingId) === null) {
                return Response::error('Meeting not found', 404);
            }

            $rows = $this->attendance->listForMeeting($tenantId, $meetingId);

            return Response::json([
                'data' => $rows,
                'counted' => self::counted($tenantId, $meetingId, $rows),
            ]);
        } catch (\Exception $e) {
            error_log('[MeetingsApiHandler] attendance failed: ' . $e->getMessage());

            return Response::error('Failed to fetch attendance', 500);
        }
    }

    /**
     * GET /api/v1/meeting-invitations?meeting_id=N
     */
    public function invitations(Request $request): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $meetingId = self::requiredMeetingIdParam($request);
            if ($meetingId instanceof Response) {
                return $meetingId;
            }
            if ($this->meetings->find($tenantId, $meetingId) === null) {
                return Response::error('Meeting not found', 404);
            }

            return Response::json(['data' => $this->invitations->listForMeeting($tenantId, $meetingId)]);
        } catch (\Exception $e) {
            error_log('[MeetingsApiHandler] invitations failed: ' . $e->getMessage());

            return Response::error('Failed to fetch invitations', 500);
        }
    }

    /**
     * POST /api/v1/meetings/{id}/agenda — allocate an item (often a document) to
     * a sitting.
     *
     * `allow_held` is the explicit confirmation
     * {@see AgendaRepository::add()} demands before attaching to a sitting that
     * is over. It is a flag on this request rather than a second endpoint,
     * because the two are the same act with different consequences and a second
     * endpoint would be found once by whoever hit the refusal and used from then
     * on without the question ever reaching a person again.
     *
     * @param array<string, string> $params
     */
    public function addAgendaItem(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $meetingId = (int) ($params['id'] ?? 0);
            $meeting = $this->meetings->find($tenantId, $meetingId);
            if ($meeting === null) {
                return Response::error('Meeting not found', 404);
            }

            $body = JsonBody::parsed($request);
            $title = LocalizedText::normalize(
                $body['title'] ?? null,
                AgendaRepository::FALLBACK_LOCALE,
                'title'
            );

            $documentId = self::intField($body, 'document_id');
            $notes = isset($body['notes']) && is_string($body['notes']) ? trim($body['notes']) : null;
            if ($notes !== null && ($tooLong = InputLimits::firstViolation([
                'notes' => [$notes, InputLimits::TEXT_MAX],
            ]))) {
                return $tooLong;
            }

            $itemId = $this->agenda->add(
                $tenantId,
                $meetingId,
                (string) $meeting['status'],
                $title,
                $documentId,
                $notes === '' ? null : $notes,
                (bool) ($body['allow_held'] ?? false)
            );

            return Response::json(['data' => $this->agenda->find($tenantId, $itemId)], 201);
        } catch (ConveningRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        } catch (\Exception $e) {
            error_log('[MeetingsApiHandler] addAgendaItem failed: ' . $e->getMessage());

            return Response::error('Failed to add agenda item', 500);
        }
    }

    /**
     * PUT /api/v1/meetings/{id}/agenda/order — rewrite the whole order.
     *
     * @param array<string, string> $params
     */
    public function reorderAgenda(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $meetingId = (int) ($params['id'] ?? 0);
            if ($this->meetings->find($tenantId, $meetingId) === null) {
                return Response::error('Meeting not found', 404);
            }

            $body = JsonBody::parsed($request);
            $raw = $body['item_ids'] ?? null;
            if (!is_array($raw)) {
                return Response::error(
                    'item_ids must be an array naming every agenda item on this meeting, in the '
                    . 'order you want them.',
                    422
                );
            }

            $ids = [];
            foreach ($raw as $value) {
                if (is_int($value)) {
                    $ids[] = $value;
                } elseif (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
                    $ids[] = (int) $value;
                } else {
                    return Response::error('item_ids must contain only agenda item ids', 422);
                }
            }

            $this->agenda->reorder($tenantId, $meetingId, $ids);

            return Response::json(['data' => $this->agenda->listForMeeting($tenantId, $meetingId)]);
        } catch (ConveningRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        } catch (\Exception $e) {
            error_log('[MeetingsApiHandler] reorderAgenda failed: ' . $e->getMessage());

            return Response::error('Failed to reorder agenda', 500);
        }
    }

    /**
     * DELETE /api/v1/meetings/{id}/agenda/{itemId}
     *
     * @param array<string, string> $params
     */
    public function removeAgendaItem(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $item = $this->requireItem($tenantId, $params);
            if ($item instanceof Response) {
                return $item;
            }

            $this->agenda->remove($tenantId, (int) $item['id']);

            return Response::json(['data' => ['deleted' => true]]);
        } catch (ConveningRejectedException $e) {
            // 409: the request is well-formed and the refusal is about the state
            // of the resource (a decision was taken against this item).
            return Response::error($e->clientMessage, 409);
        } catch (\Exception $e) {
            error_log('[MeetingsApiHandler] removeAgendaItem failed: ' . $e->getMessage());

            return Response::error('Failed to remove agenda item', 500);
        }
    }

    /**
     * POST /api/v1/meetings/{id}/agenda/{itemId}/decision — minute what the body
     * concluded, and let it drive the document's approval route.
     *
     * THE ONLY ROUTE IN THIS SUBSYSTEM THAT CAN MOVE SOMEBODY ELSE'S DOCUMENT,
     * which is why it carries its own permission. What happens underneath is
     * {@see DecisionRecorder}: number, routing act, decision row, all in one
     * transaction, in that order.
     *
     * A {@see RoutingRejectedException} is returned as a 422 carrying THE
     * ENGINE'S OWN WORDS, not a paraphrase. The engine's refusals name exactly
     * what was wrong ("you have no open item on this route", "this is the last
     * step") and a convening-flavoured restatement would be a second, worse
     * explanation of a decision this code did not make.
     *
     * `decision_number` AND `decided_at` ARE BOTH THE INSTITUTION'S TO SUPPLY,
     * and they are the two halves of one fact: a minute book records that
     * decision N was taken on date D, and both are assigned by hand, in the
     * institution's own format, often weeks after the sitting. `decided_at` has
     * always been accepted here and is untouched. `decision_number` is the new
     * half; omit it and one is allocated from the body's counter exactly as
     * before, so nothing already written against this endpoint changes.
     *
     * @param array<string, string> $params
     */
    public function recordDecision(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $item = $this->requireItem($tenantId, $params);
            if ($item instanceof Response) {
                return $item;
            }

            $body = JsonBody::parsed($request);
            $verdict = isset($body['verdict']) && is_string($body['verdict']) ? trim($body['verdict']) : '';
            $rationale = isset($body['rationale']) && is_string($body['rationale'])
                ? trim($body['rationale'])
                : null;
            if ($rationale !== null && ($tooLong = InputLimits::firstViolation([
                'rationale' => [$rationale, InputLimits::TEXT_MAX],
            ]))) {
                return $tooLong;
            }

            $decidedAt = isset($body['decided_at']) && is_string($body['decided_at'])
                && trim($body['decided_at']) !== ''
                ? trim($body['decided_at'])
                : date('Y-m-d H:i:s');

            // THE INSTITUTION'S OWN NUMBER, when there is one.
            //
            // An absent field and an EMPTY one both mean "allocate it", and
            // that pairing is deliberate rather than lazy. Absent is the
            // existing caller, written before this field existed, whose
            // behaviour must not change. Empty is a person who opened the form,
            // did not type in the optional number box, and submitted — every
            // HTML form on every client sends `""` for that, and refusing it
            // would turn the ordinary path through the new screen into a 422.
            //
            // Anything else is passed through UNTOUCHED. The bounds it must
            // clear (length, and characters that are not text) live in
            // DecisionNumbers::validateSupplied(); no shape is imposed here or
            // anywhere, because the shape is the institution's.
            $decisionNumber = isset($body['decision_number']) && is_string($body['decision_number'])
                && trim($body['decision_number']) !== ''
                ? $body['decision_number']
                : null;

            $result = $this->recorder->record(
                $tenantId,
                (int) $item['id'],
                $verdict,
                $rationale === '' ? null : $rationale,
                $decidedAt,
                self::actorProfileId($request),
                $decisionNumber
            );

            return Response::json([
                'data' => $result['decision'],
                // WHAT THE DECISION DID, always present. "The body approved it and
                // the document advanced" and "the body approved it and nothing
                // moved" are different facts, and a response that reported only
                // the first would make the second invisible on every screen.
                'routing' => $result['routing'],
            ], 201);
        } catch (ConveningRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        } catch (RoutingRejectedException $e) {
            // The engine's own words. See the method docblock.
            return Response::error($e->clientMessage, 422);
        } catch (\Exception $e) {
            error_log('[MeetingsApiHandler] recordDecision failed: ' . $e->getMessage());

            return Response::error('Failed to record decision', 500);
        }
    }

    /**
     * GET /api/v1/documents/{id}/convening — which bodies has this document been
     * in front of, and what did they decide?
     *
     * THE REVERSE READ. Without it this subsystem is invisible from the document
     * side: a person looking at a document that is sitting still has no way to
     * discover that it is waiting for a committee that meets on the 14th.
     *
     * @param array<string, string> $params
     */
    public function forDocument(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $documentId = (int) ($params['id'] ?? 0);
            $out = [];

            foreach ($this->agenda->listForDocument($tenantId, $documentId) as $item) {
                $meeting = $this->meetings->find($tenantId, (int) $item['meeting_id']);
                if ($meeting === null) {
                    continue;
                }

                $out[] = [
                    'agenda_item' => $item,
                    'meeting' => $meeting,
                    'body' => $this->bodies->find($tenantId, (int) $meeting['body_id']),
                    'decisions' => $this->decisions->listForAgendaItem($tenantId, (int) $item['id']),
                ];
            }

            return Response::json(['data' => $out]);
        } catch (\Exception $e) {
            error_log('[MeetingsApiHandler] forDocument failed: ' . $e->getMessage());

            return Response::error('Failed to fetch this document\'s convening history', 500);
        }
    }

    // -- helpers ------------------------------------------------------------

    /**
     * The whole sitting: the meeting, its body, its agenda, its decisions, its
     * invitations and its attendance.
     *
     * `invitations` AND `attendance` are both here and neither is derived from
     * the other. Who was asked and who came are different facts recorded at
     * different times ({@see \Whity\Core\Convening\InvitationStatus} refuses to
     * hold both on one column) and they disagree constantly — people accept and
     * do not come, people who declined turn up. A detail read that carried one
     * of them would make the disagreement invisible on the one screen that
     * shows the whole sitting.
     *
     * @param array<string, mixed> $meeting
     * @return array<string, mixed>
     */
    private function detail(int $tenantId, array $meeting): array
    {
        $meetingId = (int) $meeting['id'];

        return $meeting + [
            'body' => $this->bodies->find($tenantId, (int) $meeting['body_id']),
            'agenda' => $this->agenda->listForMeeting($tenantId, $meetingId),
            'decisions' => $this->decisions->listForMeeting($tenantId, $meetingId),
            'invitations' => $this->invitations->listForMeeting($tenantId, $meetingId),
            'attendance' => $this->attendance->listForMeeting($tenantId, $meetingId),
        ];
    }

    /**
     * The counts this endpoint is willing to stand behind, each named for
     * exactly what it counted.
     *
     * THIS IS NOT A QUORUM CHECK AND THE PAYLOAD SAYS SO IN A FIELD
     * ------------------------------------------------------------
     * The temptation on an attendance endpoint is to return `attendees: 5` and
     * let the reader draw a conclusion. They will draw the wrong one. A bare
     * count sitting on a meeting record reads, on every screen it reaches, as
     * "the body was quorate" — and this platform holds NO quorum rule for any
     * body, has no column to keep one in, and evaluates nothing. A body's
     * quorum lives in its constitution ("half the voting members plus one,
     * excluding vacancies"; "three of the five faculty representatives"; rules
     * with proxies in them) and it is not a number a platform can infer from a
     * membership table.
     *
     * So every key here is a verb phrase describing the count, `quorum` is
     * present as an explicit `false`, and `basis` is a sentence a person can
     * read. Naming the fields this way is the whole mitigation: a consumer that
     * wants to display "5 attended" has to read a key that says `attendees`,
     * and one that wants to claim quorum has to ignore a field that says nobody
     * checked.
     *
     * WHY `invited_who_did_not_attend` IS DERIVED RATHER THAN STORED. Absence
     * is the invited set minus the attended set; keeping it as rows would give
     * one fact two homes that can disagree. See
     * {@see \Database\Migrations\CreateMeetingAttendance}.
     *
     * @param list<array<string, mixed>> $rows The attendance already read.
     * @return array<string, mixed>
     */
    private function counted(int $tenantId, int $meetingId, array $rows): array
    {
        $invited = $this->invitations->listForMeeting($tenantId, $meetingId);
        $invitedProfiles = [];
        foreach ($invited as $invitation) {
            $invitedProfiles[(int) $invitation['profile_id']] = true;
        }

        $attendedInvitedProfiles = [];
        $notInvited = 0;
        foreach ($rows as $row) {
            $profileId = $row['profile_id'];
            if (is_int($profileId) && isset($invitedProfiles[$profileId])) {
                $attendedInvitedProfiles[$profileId] = true;
            } else {
                // Everybody else: a named guest with no profile, and a profile
                // that holds no invitation to this sitting. Both are people who
                // attended without being asked, which is the case this whole
                // feature exists for.
                $notInvited++;
            }
        }

        return [
            // Rows in the attendance list. Nothing more.
            'attendees' => count($rows),
            'attendees_who_held_an_invitation' => count($attendedInvitedProfiles),
            'attendees_who_did_not' => $notInvited,
            'invitations_issued' => count($invited),
            'invited_who_did_not_attend' => count($invitedProfiles) - count($attendedInvitedProfiles),
            // ALWAYS false, and always present. A field that only appeared when
            // something HAD been checked would be a field consumers learn to
            // ignore; one that is always here and always says no cannot be
            // mistaken for a result.
            'quorum_evaluated' => false,
            'basis' => 'Counted from the attendance recorded against this meeting and the '
                . 'invitations issued for it. No quorum rule was applied: this system holds no '
                . 'quorum rule for any body.',
        ];
    }

    /**
     * Resolve `{itemId}` AND check it belongs to `{id}`.
     *
     * The second half is the point. Without it, an item id from another meeting
     * — in this tenant or, once ids are guessable, anywhere — would be decided
     * on through a URL that names a meeting it has nothing to do with, and the
     * decision would be filed against the wrong sitting.
     *
     * @param array<string, string> $params
     * @return array<string, mixed>|Response
     */
    private function requireItem(int $tenantId, array $params): array|Response
    {
        $meetingId = (int) ($params['id'] ?? 0);
        if ($this->meetings->find($tenantId, $meetingId) === null) {
            return Response::error('Meeting not found', 404);
        }

        $item = $this->agenda->find($tenantId, (int) ($params['itemId'] ?? 0));
        if ($item === null || (int) $item['meeting_id'] !== $meetingId) {
            return Response::error('Agenda item not found on this meeting', 404);
        }

        return $item;
    }

    /**
     * @return array<string, string>
     */
    private static function queryParams(Request $request): array
    {
        $query = [];
        foreach ($_GET as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $query[$k] = $v;
            }
        }
        $qs = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $parsed);
            foreach ($parsed as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $query[$k] = $v;
                }
            }
        }

        return $query;
    }

    private static function tenantId(): int|Response
    {
        $tenantId = TenantContext::getTenantId();

        return $tenantId ?? Response::error('Tenant context is required', 403);
    }

    /**
     * The `meeting_id` filter every flat collection read demands.
     *
     * A 422 rather than an unfiltered answer: see {@see agendaItems()}. Returning
     * the tenant's entire agenda-item table for a request that forgot the filter
     * would make the mistake look like a feature.
     */
    private static function requiredMeetingIdParam(Request $request): int|Response
    {
        $query = self::queryParams($request);
        $raw = $query['meeting_id'] ?? '';

        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return Response::error('meeting_id is required and must be an integer', 422);
        }

        return (int) $raw;
    }

    private static function actorProfileId(Request $request): ?int
    {
        $actor = $request->user;

        return is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function intField(array $body, string $field): ?int
    {
        $raw = $body[$field] ?? null;
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/^\d+$/', $raw) === 1) {
            return (int) $raw;
        }

        return null;
    }
}
