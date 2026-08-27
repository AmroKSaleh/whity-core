<?php

declare(strict_types=1);

namespace Whity\Core\Convening;

use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Router;

/**
 * The three screens this subsystem puts in the admin navigation, declared as
 * server-driven BLOCK TREES.
 *
 * WHY BLOCKS AND NOT THREE REACT PAGES
 * ------------------------------------
 * Because the platform already has a renderer for exactly this, on more than one
 * client. A `screen: 'blocks'` feature is a platform-NEUTRAL tree
 * ({@see \Whity\Sdk\Frontend\Blocks\BlockContract}) that the web admin, and any
 * other shell that speaks the contract, turns into native widgets. Three bespoke
 * pages would be three pages the desktop client does not have.
 *
 * NO NEW BLOCK TYPE IS INTRODUCED. Everything below is composed from types that
 * already exist and already have live instances — `section`, `text`,
 * `dataTable`, `selector`, `dataRecord`, `recordFields`. That is not modesty: a
 * new type in `BlockContract` requires a live instance in the UI-kit showcase or
 * its every-type coverage test fails, and a convening screen is not a reason to
 * widen a whitelist every renderer on every platform then has to implement.
 *
 * THE PATHS ARE EMITTED VERSIONED, THROUGH THE ROUTER
 * ---------------------------------------------------
 * Route paths are REGISTERED unversioned (`/api/meetings`) and the router
 * prepends `/v1` as it stores them. A block's `source` is not a registration —
 * it is a URL a browser will fetch — so it has to carry the prefix, and it gets
 * it from {@see Router::versionedPath()} rather than from a hard-coded string.
 * This is the exact class of bug that once shipped `content_url` pointing at
 * `/api/documents/{id}/content` while the router served `/api/v1/...`, and every
 * document in the viewer rendered an error box.
 *
 * `resource.basePath` is versioned for a second reason: the host derives the
 * screen's Create / Edit / Delete capabilities by matching that path against the
 * router's REGISTERED paths, which are versioned. An unversioned base path would
 * match nothing and every control would be disabled with no explanation.
 *
 * EVERY DESCRIPTOR IS PERMISSION-GATED, AND THE GATE IS NOT THE ENFORCEMENT
 * ------------------------------------------------------------------------
 * `requiredPermission` decides only whether the screen APPEARS
 * ({@see \Whity\Api\FrontendFeaturesApiHandler} filters per caller against the
 * authoritative RoleChecker). What the screen can actually read and write is
 * enforced by the RBAC on the routes behind it, exactly as it is for every other
 * caller. A descriptor grants nothing.
 */
final class ConveningFeatures
{
    /** The bodies list. */
    public const BODIES = 'convening-bodies';

    /** The meetings list. */
    public const MEETINGS = 'convening-meetings';

    /** One meeting: its agenda, decisions and invitations. */
    public const MEETING_DETAIL = 'convening-meeting';

    /**
     * The nav group these screens sit in.
     *
     * `documents` rather than a group of its own: a convening body exists here to
     * decide about documents, and a person looking for "where did my document get
     * to" should find the committee that has it beside the document library
     * rather than in a section they have to know exists. A subsystem that gives
     * itself a nav group before it has three screens people use daily is a
     * subsystem asserting its own importance in the sidebar.
     */
    public const NAV_GROUP = 'documents';

    /**
     * Every descriptor, with API paths resolved through the live router.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(Router $router): array
    {
        $bodies = $router->versionedPath('/api/convening-bodies');
        $meetings = $router->versionedPath('/api/meetings');
        $agendaItems = $router->versionedPath('/api/agenda-items');
        $decisions = $router->versionedPath('/api/meeting-decisions');
        $invitations = $router->versionedPath('/api/meeting-invitations');
        $attendees = $router->versionedPath('/api/meeting-attendees');
        // Cross-subsystem reads a declaration points at. Emitted through
        // versionedPath() like every other path here: a literal '/api/v1/...'
        // happens to be right today and silently wrong the moment the prefix
        // moves, which is what ConveningFeaturesTest pins.
        $ous = $router->versionedPath('/api/ous');
        $documents = $router->versionedPath('/api/documents');

        return [
            self::bodiesFeature($bodies, $ous),
            self::meetingsFeature($meetings, $bodies),
            self::meetingDetailFeature(
                $meetings,
                $agendaItems,
                $decisions,
                $invitations,
                $attendees,
                $documents
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function bodiesFeature(string $bodiesPath, string $ousPath): array
    {
        return [
            'id' => self::BODIES,
            // The owner shown beside the screen. `core` rather than a plugin
            // name, because that is what it is — and a screen claiming to come
            // from a plugin nobody installed is the sort of thing an operator
            // opens a support ticket about.
            'plugin' => 'core',
            'label' => 'Convening Bodies',
            'icon' => 'users-group',
            'group' => self::NAV_GROUP,
            'order' => 6,
            'screen' => 'blocks',
            'requiredPermission' => CorePermissions::CONVENING_READ,
            // Declared so the host can derive Create / Edit / Delete from the
            // routes actually registered at this base path, and disable the
            // controls a given caller may not use rather than showing controls
            // that 403 on submit.
            'resource' => ['basePath' => $bodiesPath, 'titleField' => 'display_name'],
            'blocks' => [
                [
                    'type' => 'section',
                    'title' => 'Convening bodies',
                    'children' => [
                        [
                            'type' => 'text',
                            'tone' => 'muted',
                            'value' => 'Standing bodies that meet, minute numbered decisions, and can '
                                . 'approve or reject the documents put before them.',
                        ],
                        // The `resource` declaration above does NOT produce
                        // Create/Edit controls on a `screen: 'blocks'` feature —
                        // the host renders the schema-driven CRUD screen only
                        // when `screen === 'crud'`. So a body could be listed and
                        // never created, which is what these declare instead.
                        [
                            'type' => 'modal',
                            'id' => 'newBodyModal',
                            'title' => 'New convening body',
                            'trigger' => 'New body',
                            'children' => [
                                [
                                    'type' => 'form',
                                    'submit' => ['method' => 'POST', 'endpoint' => $bodiesPath],
                                    'requiredPermission' => CorePermissions::CONVENING_MANAGE,
                                    'children' => [
                                        [
                                            'type' => 'textInput',
                                            'name' => 'body_key',
                                            'label' => 'Key',
                                            'placeholder' => 'kebab-case, unique in this tenant',
                                            'required' => true,
                                        ],
                                        [
                                            'type' => 'bilingualText',
                                            'name' => 'name',
                                            'label' => 'Name',
                                            'required' => true,
                                            'arLabel' => 'Arabic',
                                            'enLabel' => 'English',
                                        ],
                                        [
                                            'type' => 'referenceSelect',
                                            'name' => 'ou_id',
                                            'label' => 'Belongs to',
                                            'source' => $ousPath,
                                            'valueField' => 'id',
                                            'labelField' => 'name',
                                            'placeholder' => 'No particular unit',
                                        ],
                                        [
                                            'type' => 'textArea',
                                            'name' => 'description',
                                            'label' => 'Description',
                                            'rows' => 2,
                                        ],
                                        ['type' => 'submitButton', 'label' => 'Create body'],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'type' => 'dataTable',
                            'source' => $bodiesPath,
                            'emptyText' => 'No convening bodies yet. Create one to start recording '
                                . 'meetings and decisions.',
                            'pageSize' => 25,
                            'columns' => [
                                ['key' => 'body_key', 'label' => 'Key', 'sortable' => true, 'filterable' => true],
                                // `display_name` and not `name`: `name` is a
                                // locale MAP, and a table cell can only render a
                                // string. Both are on the wire — a localizing
                                // client reads the map. See LocalizedText.
                                ['key' => 'display_name', 'label' => 'Name', 'sortable' => true, 'filterable' => true],
                                ['key' => 'description', 'label' => 'Description'],
                            ],
                            // `open` publishes the row under the modal's id, so
                            // the edit form addresses it as {editBodyModal.…}
                            // without needing a second selector the API could
                            // not filter anyway.
                            'rowActions' => [
                                ['label' => 'Edit', 'open' => 'editBodyModal'],
                                [
                                    'label' => 'Delete',
                                    'endpoint' => $bodiesPath . '/{id}',
                                    'method' => 'DELETE',
                                    'confirm' => 'Meetings and decisions already minuted by this body stay '
                                        . 'on the record. Delete the body?',
                                ],
                            ],
                        ],
                        [
                            'type' => 'modal',
                            'id' => 'editBodyModal',
                            'title' => 'Edit this body',
                            'children' => [
                                [
                                    'type' => 'form',
                                    'submit' => [
                                        'method' => 'PATCH',
                                        'endpoint' => $bodiesPath . '/{editBodyModal.id}',
                                    ],
                                    'requiredPermission' => CorePermissions::CONVENING_MANAGE,
                                    'children' => [
                                        [
                                            'type' => 'bilingualText',
                                            'name' => 'name',
                                            'label' => 'Name',
                                            'arLabel' => 'Arabic',
                                            'enLabel' => 'English',
                                        ],
                                        [
                                            'type' => 'textArea',
                                            'name' => 'description',
                                            'label' => 'Description',
                                            'rows' => 2,
                                            'defaultFrom' => 'editBodyModal.description',
                                        ],
                                        ['type' => 'submitButton', 'label' => 'Save changes'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function meetingsFeature(string $meetingsPath, string $bodiesPath): array
    {
        return [
            'id' => self::MEETINGS,
            'plugin' => 'core',
            'label' => 'Meetings',
            'icon' => 'calendar',
            'group' => self::NAV_GROUP,
            'order' => 7,
            'screen' => 'blocks',
            'requiredPermission' => CorePermissions::CONVENING_READ,
            'resource' => ['basePath' => $meetingsPath, 'titleField' => 'display_title'],
            'blocks' => [
                [
                    'type' => 'section',
                    'title' => 'Meetings',
                    'children' => [
                        [
                            'type' => 'text',
                            'tone' => 'muted',
                            'value' => 'Sittings of the tenant\'s convening bodies. A meeting collects '
                                . 'an agenda while it is a draft or scheduled; once it is held, the '
                                . 'decisions taken at it can approve or reject the documents on that '
                                . 'agenda.',
                        ],
                        [
                            'type' => 'modal',
                            'id' => 'newMeetingModal',
                            'title' => 'Convene a meeting',
                            'trigger' => 'New meeting',
                            'children' => [
                                [
                                    'type' => 'form',
                                    'submit' => ['method' => 'POST', 'endpoint' => $meetingsPath],
                                    'requiredPermission' => CorePermissions::CONVENING_MANAGE,
                                    'children' => [
                                        [
                                            'type' => 'referenceSelect',
                                            'name' => 'body_id',
                                            'label' => 'Body',
                                            'source' => $bodiesPath,
                                            'valueField' => 'id',
                                            'labelField' => 'display_name',
                                            'required' => true,
                                            'placeholder' => 'Which body is meeting',
                                        ],
                                        [
                                            'type' => 'bilingualText',
                                            'name' => 'title',
                                            'label' => 'Title',
                                            'arLabel' => 'Arabic',
                                            'enLabel' => 'English',
                                        ],
                                        ['type' => 'submitButton', 'label' => 'Create meeting'],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'type' => 'dataTable',
                            'source' => $meetingsPath,
                            'emptyText' => 'No meetings yet.',
                            'pageSize' => 25,
                            'columns' => [
                                ['key' => 'meeting_number', 'label' => 'No.', 'sortable' => true],
                                ['key' => 'display_title', 'label' => 'Title', 'sortable' => true, 'filterable' => true],
                                ['key' => 'status', 'label' => 'Status', 'sortable' => true, 'filterable' => true],
                                ['key' => 'scheduled_at', 'label' => 'Scheduled', 'sortable' => true],
                                ['key' => 'held_at', 'label' => 'Held', 'sortable' => true],
                                ['key' => 'location', 'label' => 'Location'],
                            ],
                            'rowActions' => [
                                [
                                    'label' => 'Open',
                                    // Internal navigation to the detail screen,
                                    // which is a feature of its own rather than
                                    // a modal: an agenda, its decisions and its
                                    // invitations are three tables, and three
                                    // tables in a drawer is a drawer nobody can
                                    // read.
                                    'href' => '/admin/x/' . self::MEETING_DETAIL . '/{id}',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The detail screen: pick a meeting, then see everything about it.
     *
     * A `selector` rather than a URL parameter, because `/admin/x/{featureId}`
     * carries no id and a block tree has no way to read one. The selector
     * publishes the chosen meeting into the master-detail context, the
     * `dataRecord` addresses it as a PATH token, and the three tables address it
     * as a QUERY parameter — which is exactly why the collection reads are flat
     * and filtered rather than nested under the meeting (see
     * {@see \Whity\Api\MeetingsApiHandler::agendaItems()}).
     *
     * @return array<string, mixed>
     */
    private static function meetingDetailFeature(
        string $meetingsPath,
        string $agendaItemsPath,
        string $decisionsPath,
        string $invitationsPath,
        string $attendeesPath,
        string $documentsPath
    ): array {
        return [
            'id' => self::MEETING_DETAIL,
            'plugin' => 'core',
            'label' => 'Meeting Record',
            'icon' => 'clipboard-text',
            'group' => self::NAV_GROUP,
            'order' => 8,
            'screen' => 'blocks',
            'requiredPermission' => CorePermissions::CONVENING_READ,
            'blocks' => [
                [
                    'type' => 'section',
                    'title' => 'Meeting record',
                    'children' => [
                        // THE RECORD IS IN THE URL, not in a dropdown. This
                        // page used to pick its subject with a selector, which
                        // meant the address said nothing about what was on
                        // screen: a refresh, a shared link, the back button and
                        // any redirect after an action all landed on an empty
                        // page asking the reader to choose again. `{record}` is
                        // the reserved binding the record ROUTE
                        // (/admin/x/{featureId}/{recordId}) seeds from its own
                        // segment, so the meeting being read IS the address.
                        //
                        // Reached from the sidebar there is no segment to seed,
                        // and every source below simply stays empty — hence the
                        // note rather than a control that would half-work.
                        [
                            'type' => 'text',
                            'tone' => 'muted',
                            'value' => 'Open a meeting from the Meetings list to see its record. '
                                . 'The address carries the meeting, so this page can be refreshed, '
                                . 'bookmarked and shared.',
                        ],
                        [
                            'type' => 'dataRecord',
                            'id' => 'meetingRecord',
                            // A PATH token, which `recordPath` allows and a plain
                            // `apiPath` does not — this is the one block in the
                            // tree addressing a single resource rather than a
                            // collection.
                            'source' => $meetingsPath . '/{record}',
                            'emptyText' => 'Choose a meeting to see its record.',
                            'fields' => [
                                ['field' => 'display_title', 'label' => 'Title'],
                                ['field' => 'meeting_number', 'label' => 'Meeting number'],
                                ['field' => 'status', 'label' => 'Status'],
                                ['field' => 'scheduled_at', 'label' => 'Scheduled for'],
                                ['field' => 'held_at', 'label' => 'Held on'],
                                ['field' => 'location', 'label' => 'Location'],
                            ],
                            'children' => [
                                ['type' => 'recordFields', 'from' => 'meetingRecord'],
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'section',
                    'title' => 'Running the meeting',
                    'children' => [
                        [
                            'type' => 'text',
                            'tone' => 'muted',
                            'value' => 'Set a date, invite the members, then hold it. A decision can only '
                                . 'be minuted once the meeting has been held.',
                        ],
                        [
                            'type' => 'modal',
                            'id' => 'scheduleModal',
                            'title' => 'Set a date',
                            'trigger' => 'Schedule',
                            'children' => [
                                [
                                    'type' => 'form',
                                    // A submit endpoint interpolates its tokens
                                    // from the same context a source does, so the
                                    // selected meeting fills the path segment.
                                    'submit' => [
                                        'method' => 'POST',
                                        'endpoint' => $meetingsPath . '/{record}/schedule',
                                    ],
                                    'requiredPermission' => CorePermissions::CONVENING_MANAGE,
                                    'children' => [
                                        [
                                            'type' => 'dateInput',
                                            'name' => 'scheduled_at',
                                            'label' => 'Date and time',
                                            'required' => true,
                                        ],
                                        [
                                            'type' => 'textInput',
                                            'name' => 'location',
                                            'label' => 'Location',
                                        ],
                                        ['type' => 'submitButton', 'label' => 'Schedule'],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'type' => 'actionButton',
                            'label' => 'Send invitations',
                            'requiredPermission' => CorePermissions::CONVENING_MANAGE,
                            'action' => [
                                'method' => 'POST',
                                'endpoint' => $meetingsPath . '/{record}/invitations',
                            ],
                            'confirm' => 'Invite every current member of this body?',
                        ],
                        [
                            'type' => 'actionButton',
                            'label' => 'Hold the meeting',
                            'variant' => 'primary',
                            'requiredPermission' => CorePermissions::CONVENING_MANAGE,
                            'action' => [
                                'method' => 'POST',
                                'endpoint' => $meetingsPath . '/{record}/hold',
                            ],
                            'confirm' => 'Mark this meeting as held? Decisions can be minuted afterwards.',
                        ],
                        [
                            'type' => 'modal',
                            'id' => 'addAgendaModal',
                            'title' => 'Put something on the agenda',
                            'trigger' => 'Add agenda item',
                            'children' => [
                                [
                                    'type' => 'form',
                                    'submit' => [
                                        'method' => 'POST',
                                        'endpoint' => $meetingsPath . '/{record}/agenda',
                                    ],
                                    'requiredPermission' => CorePermissions::CONVENING_MANAGE,
                                    'children' => [
                                        [
                                            'type' => 'bilingualText',
                                            'name' => 'title',
                                            'label' => 'Item',
                                            'required' => true,
                                            'arLabel' => 'Arabic',
                                            'enLabel' => 'English',
                                        ],
                                        [
                                            // Carrying a document is what lets the
                                            // minuted decision drive that
                                            // document's route.
                                            'type' => 'referenceSelect',
                                            'name' => 'document_id',
                                            'label' => 'Document under consideration',
                                            'source' => $documentsPath,
                                            'valueField' => 'id',
                                            'labelField' => 'title',
                                            'placeholder' => 'No document',
                                        ],
                                        [
                                            'type' => 'textArea',
                                            'name' => 'notes',
                                            'label' => 'Notes',
                                            'rows' => 2,
                                        ],
                                        [
                                            // The API refuses to add an item to a
                                            // meeting that is already OVER unless
                                            // the caller says they mean it —
                                            // right for a paper tabled on the day
                                            // and minuted afterwards, wrong if
                                            // somebody meant the next meeting.
                                            // Without a way to answer that here,
                                            // the refusal was a dead end: the
                                            // screen asked a question it gave no
                                            // means of answering.
                                            'type' => 'checkbox',
                                            'name' => 'allow_held',
                                            'label' => 'This meeting has already been held — add it to that sitting anyway',
                                        ],
                                        ['type' => 'submitButton', 'label' => 'Add to agenda'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'section',
                    'title' => 'Agenda',
                    'children' => [
                        [
                            'type' => 'dataTable',
                            'source' => $agendaItemsPath,
                            'params' => [['param' => 'meeting_id', 'from' => 'record']],
                            'emptyText' => 'Nothing on this agenda yet.',
                            'columns' => [
                                ['key' => 'position', 'label' => '#'],
                                ['key' => 'display_title', 'label' => 'Item'],
                                // The join to the rest of the platform: an item
                                // with a document id is an item a decision can
                                // move.
                                ['key' => 'document_id', 'label' => 'Document'],
                                ['key' => 'notes', 'label' => 'Notes'],
                            ],
                            'rowActions' => [
                                // `open` still PUBLISHES the row under this id —
                                // that is what makes {minutedItem.meeting_id}
                                // and {minutedItem.id} resolve below. What it no
                                // longer opens is a dialog: the target is a
                                // `card`, and the composing form lives on the
                                // page. See the card's own note.
                                ['label' => 'Minute a decision', 'open' => 'minutedItem'],
                            ],
                        ],
                        [
                            // COMPOSING A MINUTE IS NOT A CONFIRMATION, SO IT IS
                            // NOT A DIALOG.
                            //
                            // This was a modal. Minuting a decision is five
                            // fields — a verdict, a rationale in the body's own
                            // words, the number the institution assigned and the
                            // date it assigned it against — and it is typed
                            // while READING the agenda item it is about. A dialog
                            // covers exactly that: the person composing the
                            // minute loses sight of the item, the document
                            // attached to it, and the decisions already recorded
                            // at this sitting, which is the context that tells
                            // them what number comes next.
                            //
                            // It is also typed from a piece of paper. Somebody
                            // transcribing a minute book looks down at the book
                            // and back at the screen, and a dialog that swallows
                            // an accidental click on the backdrop takes four
                            // fields with it.
                            //
                            // A `card` inside the section, addressed by the same
                            // published row token. The one dialog-shaped thing
                            // left in this subsystem is a `confirm` on an action
                            // button, which is a yes/no about an act already
                            // decided — the case a dialog is actually for.
                            'type' => 'card',
                            'title' => 'Minute a decision',
                            'description' => 'Choose an agenda item above, then record what the body '
                                . 'concluded about it. A decision can only be minuted once the meeting '
                                . 'has been held, and it cannot be edited afterwards — a body that '
                                . 'changes its mind takes a new decision at a later sitting.',
                            'children' => [
                                [
                                    'type' => 'text',
                                    'tone' => 'muted',
                                    'value' => 'Nothing is minuted until you press Record decision. '
                                        . 'If the item carries a document and this body is the one its '
                                        . 'approval route is waiting for, an approval here advances '
                                        . 'that document and a rejection stops it.',
                                ],
                                [
                                    'type' => 'form',
                                    // Both path segments come from the row the
                                    // "Minute a decision" action published, so
                                    // the decision lands on the item somebody
                                    // actually chose.
                                    'submit' => [
                                        'method' => 'POST',
                                        'endpoint' => $meetingsPath
                                            . '/{minutedItem.meeting_id}/agenda/{minutedItem.id}/decision',
                                    ],
                                    'requiredPermission' => CorePermissions::CONVENING_DECIDE,
                                    'children' => [
                                        [
                                            // WHICH ITEM THIS MINUTE IS ABOUT,
                                            // named on the form itself. The
                                            // submit addresses the item by a
                                            // token, so without this line the
                                            // form looks identical whichever row
                                            // was chosen — and identical again
                                            // when NO row has been chosen, which
                                            // is the state in which submitting
                                            // does nothing useful.
                                            //
                                            // `value` is the FALLBACK, not a
                                            // prefix: `useBoundText` replaces the
                                            // literal outright once `valueFrom`
                                            // resolves. So the literal has to
                                            // read as a sentence on its own —
                                            // one carrying a {token} would render
                                            // the token to a person.
                                            'type' => 'text',
                                            'value' => 'No agenda item chosen yet — use "Minute a '
                                                . 'decision" on a row above.',
                                            'valueFrom' => 'minutedItem.display_title',
                                        ],
                                        [
                                            'type' => 'select',
                                            'name' => 'verdict',
                                            'label' => 'Verdict',
                                            'required' => true,
                                            // Derived from the vocabulary rather
                                            // than transcribed, so a fourth
                                            // verdict cannot exist in
                                            // DecisionVerdict and be unreachable
                                            // from the only screen that records
                                            // one.
                                            'options' => self::verdictOptions(),
                                        ],
                                        [
                                            // THE NUMBER THE INSTITUTION
                                            // ASSIGNED, and the reason this
                                            // screen changed at all.
                                            //
                                            // A decision number is what a
                                            // reviewer quotes back to the
                                            // institution to check that a
                                            // decision was real, and they check
                                            // it against a minute book kept by
                                            // hand. A number this platform
                                            // invented appears in no minute book.
                                            //
                                            // OPTIONAL, and the placeholder says
                                            // what happens when it is left
                                            // blank — a required field here
                                            // would block every deployment that
                                            // keeps no separate minute book and
                                            // is happy with an allocated number.
                                            //
                                            // A PLAIN TEXT INPUT, not a masked
                                            // or patterned one: these are
                                            // strings like CE-CM-2026-014 and
                                            // ق.ع/٢٠٢٦/١٤, and any shape this
                                            // screen imposed would be a shape
                                            // some institution does not use.
                                            'type' => 'textInput',
                                            'name' => 'decision_number',
                                            'label' => 'Decision number (from the minute book)',
                                            'placeholder' => 'Leave blank to allocate one automatically',
                                        ],
                                        [
                                            // THE DATE THE INSTITUTION MINUTED
                                            // IT AGAINST, which is not today. A
                                            // body routinely types up a sitting
                                            // weeks later, and the year in this
                                            // date is the year an allocated
                                            // number is minted under.
                                            'type' => 'dateInput',
                                            'name' => 'decided_at',
                                            'label' => 'Date of the decision',
                                        ],
                                        [
                                            'type' => 'textArea',
                                            'name' => 'rationale',
                                            'label' => 'Rationale',
                                            'rows' => 3,
                                        ],
                                        ['type' => 'submitButton', 'label' => 'Record decision'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'section',
                    'title' => 'Decisions',
                    'children' => [
                        [
                            'type' => 'dataTable',
                            'source' => $decisionsPath,
                            'params' => [['param' => 'meeting_id', 'from' => 'record']],
                            'emptyText' => 'No decisions recorded for this meeting.',
                            'columns' => [
                                ['key' => 'decision_number', 'label' => 'Number', 'sortable' => true],
                                ['key' => 'verdict', 'label' => 'Verdict', 'filterable' => true],
                                ['key' => 'rationale', 'label' => 'Rationale'],
                                ['key' => 'decided_at', 'label' => 'Decided', 'sortable' => true],
                                // WHETHER THE DECISION MOVED ANYTHING. A
                                // decision with a route id advanced a document
                                // through its approval chain; one without did
                                // not, and without this column the two are
                                // indistinguishable on screen.
                                ['key' => 'route_id', 'label' => 'Drove route'],
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'section',
                    'title' => 'Invitations',
                    'children' => [
                        [
                            'type' => 'text',
                            'tone' => 'muted',
                            'value' => 'What people SAID before the sitting. An acceptance is a '
                                . 'prediction; who actually came is recorded separately, below.',
                        ],
                        [
                            'type' => 'dataTable',
                            'source' => $invitationsPath,
                            'params' => [['param' => 'meeting_id', 'from' => 'record']],
                            'emptyText' => 'Nobody has been invited to this meeting yet.',
                            'columns' => [
                                ['key' => 'profile_id', 'label' => 'Person'],
                                ['key' => 'status', 'label' => 'Answer', 'filterable' => true],
                                ['key' => 'sent_at', 'label' => 'Invited'],
                                ['key' => 'responded_at', 'label' => 'Answered'],
                            ],
                        ],
                    ],
                ],
                self::attendanceSection($meetingsPath, $attendeesPath),
            ],
        ];
    }

    /**
     * WHO WAS ACTUALLY THERE — the section, its editor, and the sentence that
     * stops the count being read as a quorum.
     *
     * THE EDITOR IS INLINE, AND IT IS A SOURCED `fieldArray`
     * ------------------------------------------------------
     * Recording attendance is transcription: somebody has a sign-in sheet, or a
     * page of a notebook, and works down it. That is composition — write a line,
     * read it back against the one above, correct the one below — and it is
     * exactly the shape a dialog is worst at. It is also long: an attendance
     * list is as many rows as there were people in the room, and a dialog that
     * scrolls internally beside a page that also scrolls is a page nobody can
     * work in.
     *
     * So the same instrument the form builder uses for its questions: a stack of
     * cards, edited in place, reordered and removed on the card, SAVED TOGETHER
     * as one `PUT` of the whole set. It is the block type that already exists
     * for "edit a stored set in place", and reaching for a second one would mean
     * two answers to one question.
     *
     * THE SAVE IS A REPLACEMENT, AND THAT IS SAID OUT LOUD rather than left to
     * be discovered — a person removed from the stack and then saved is removed
     * from the record of who attended. The renderer will not let the array
     * submit until it has actually LOADED what is stored for the meeting the
     * address names, which is what stops a form that had not finished loading
     * from saving "nobody attended" over a minuted list.
     *
     * ONLY ON A HELD MEETING. `visibleWhen` reads the status off the record this
     * page is already showing, so the editor is simply not there before the
     * sitting. That is presentation and not enforcement — the server refuses it
     * regardless, in
     * {@see MeetingService::recordAttendance()} — but a control that appears and
     * then 422s is a control that teaches people the screen is unreliable.
     *
     * @return array<string, mixed>
     */
    private static function attendanceSection(string $meetingsPath, string $attendeesPath): array
    {
        return [
            'type' => 'section',
            'title' => 'Attendance',
            'children' => [
                [
                    'type' => 'text',
                    'tone' => 'muted',
                    'value' => 'Who was actually in the room. This is a separate record from the '
                        . 'invitations above and the two disagree often: people accept and do not '
                        . 'come, and people who declined turn up. Anybody may be recorded here, '
                        . 'including somebody who was never invited — a substitute, a co-opted '
                        . 'member, a guest.',
                ],
                [
                    // THE HONEST SENTENCE ABOUT COUNTING. A list of names on a
                    // meeting record invites exactly one inference — "so the body
                    // was quorate" — and this platform holds no quorum rule for
                    // any body and evaluates none. Said here, on the screen,
                    // rather than only in the API payload, because the person who
                    // would draw the inference is looking at the screen.
                    'type' => 'alert',
                    'variant' => 'info',
                    'title' => 'This is a record, not a quorum check',
                    'body' => 'Whity does not hold any body\'s quorum rule and does not check one. '
                        . 'What is below is the list of people recorded as present, and nothing '
                        . 'more.',
                ],
                [
                    'type' => 'dataTable',
                    'source' => $attendeesPath,
                    'params' => [['param' => 'meeting_id', 'from' => 'record']],
                    'emptyText' => 'No attendance recorded for this meeting yet.',
                    'columns' => [
                        ['key' => 'profile_id', 'label' => 'Person'],
                        // The name is what carries somebody with no account, and
                        // it is the column that makes a guest legible at all.
                        ['key' => 'attendee_name', 'label' => 'Name'],
                        ['key' => 'capacity', 'label' => 'In what capacity', 'filterable' => true],
                        // THE DISAGREEMENT, made visible. `was_invited` false is
                        // somebody who came without being asked; an
                        // `invitation_status` of `declined` beside a row that
                        // EXISTS is somebody who said no and came anyway. Neither
                        // is visible at all without these two columns.
                        ['key' => 'was_invited', 'label' => 'Was invited'],
                        ['key' => 'invitation_status', 'label' => 'Had answered'],
                        ['key' => 'note', 'label' => 'Note'],
                    ],
                ],
                [
                    'type' => 'card',
                    'title' => 'Record who attended',
                    'description' => 'Saving replaces the whole list: anybody you remove here stops '
                        . 'being on the record of who attended this sitting. Somebody who was never '
                        . 'invited can be added — give a name instead of a profile if they have no '
                        . 'account.',
                    // Only once the sitting has happened. Attendance taken
                    // beforehand is a guess, and the platform already holds
                    // guesses under a name that says so (an invitation somebody
                    // accepted).
                    'visibleWhen' => ['from' => 'meetingRecord.status', 'equals' => MeetingStatus::HELD],
                    'children' => [
                        [
                            'type' => 'form',
                            'submit' => [
                                // PUT, because the act replaces the set. See
                                // MeetingsApiHandler::recordAttendance().
                                'method' => 'PUT',
                                'endpoint' => $meetingsPath . '/{record}/attendance',
                            ],
                            'requiredPermission' => CorePermissions::CONVENING_MANAGE,
                            'children' => [
                                [
                                    'type' => 'fieldArray',
                                    // The payload key the endpoint reads. Named
                                    // for the wire; the human noun is the label.
                                    'name' => 'attendees',
                                    'label' => 'People present',
                                    'itemLabel' => 'Attendee',
                                    // The FLAT read, addressed by query param,
                                    // because `params` cannot fill a path
                                    // segment. The WRITE stays nested under the
                                    // meeting, whose STATUS is what decides
                                    // whether it is allowed at all.
                                    'source' => $attendeesPath,
                                    'params' => [['param' => 'meeting_id', 'from' => 'record']],
                                    // NO `min`. A sitting abandoned for want of
                                    // attendance is a real thing to record, and a
                                    // minimum here would make it unrecordable
                                    // through the only surface that can record
                                    // it.
                                    'children' => [
                                        [
                                            // A NUMBER, not a picker, and that is
                                            // the honest choice rather than a
                                            // missing feature: there is no
                                            // tenant-wide profile collection to
                                            // point a `referenceSelect` at, and
                                            // the members endpoint cannot be
                                            // narrowed to this meeting's body
                                            // (that type carries no `params`).
                                            // The invitations table above shows
                                            // profile ids for the same reason.
                                            'type' => 'numberInput',
                                            'name' => 'profile_id',
                                            'label' => 'Profile',
                                            'min' => 1,
                                        ],
                                        [
                                            'type' => 'textInput',
                                            'name' => 'attendee_name',
                                            'label' => 'Name (for somebody with no account)',
                                            'placeholder' => 'A guest, or an external member',
                                        ],
                                        [
                                            'type' => 'select',
                                            'name' => 'capacity',
                                            'label' => 'In what capacity',
                                            // Derived from the vocabulary, so a
                                            // capacity added to
                                            // AttendanceCapacity and not to a
                                            // hand-written list here cannot
                                            // become one nobody can choose.
                                            'options' => self::capacityOptions(),
                                            'default' => AttendanceCapacity::DEFAULT,
                                        ],
                                        [
                                            'type' => 'textInput',
                                            'name' => 'note',
                                            'label' => 'Note',
                                            'placeholder' => 'e.g. standing in for a member, or left after item 3',
                                        ],
                                    ],
                                ],
                                ['type' => 'submitButton', 'label' => 'Save attendance'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The verdicts, derived from the vocabulary rather than transcribed.
     *
     * A fourth value added to {@see DecisionVerdict} and not to a hand-written
     * list here would be a verdict the only screen that minutes one cannot
     * choose — invisible until somebody needs it. `ucfirst` rather than a label
     * map: the three values are already the words a person would read, and a map
     * would be a second place for them to drift.
     *
     * @return list<array{value: string, label: string}>
     */
    private static function verdictOptions(): array
    {
        return array_map(
            static fn (string $v): array => ['value' => $v, 'label' => ucfirst($v)],
            DecisionVerdict::all()
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private static function capacityOptions(): array
    {
        return array_map(
            static fn (string $c): array => ['value' => $c, 'label' => ucfirst($c)],
            AttendanceCapacity::all()
        );
    }

    /**
     * The navigation items these screens appear as.
     *
     * Derived from the descriptors rather than written twice, so a renamed screen
     * cannot end up with one label in the menu and another on the page — and so
     * that a screen added here appears in the sidebar without a second edit
     * somewhere else.
     *
     * The href is `/admin/x/{featureId}`, the host's dynamic screen route, which
     * is exactly what {@see \Whity\Core\PluginNavigationBridge} emits for a
     * plugin's descriptors. The nav id is NOT prefixed `plugin-`: these are core
     * screens, and borrowing the prefix would make an operator reading the
     * navigation think a plugin was providing them.
     *
     * @return list<array<string, mixed>>
     */
    public static function navigationItems(Router $router): array
    {
        $items = [];

        foreach (self::all($router) as $feature) {
            $items[] = [
                'id' => (string) $feature['id'],
                'label' => (string) $feature['label'],
                'href' => '/admin/x/' . (string) $feature['id'],
                'icon' => (string) $feature['icon'],
                'group' => (string) $feature['group'],
                'order' => (int) $feature['order'],
                'requiredPermission' => (string) $feature['requiredPermission'],
            ];
        }

        return $items;
    }
}
