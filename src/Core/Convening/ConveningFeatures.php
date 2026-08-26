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

        return [
            self::bodiesFeature($bodies),
            self::meetingsFeature($meetings),
            self::meetingDetailFeature($meetings, $agendaItems, $decisions, $invitations),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function bodiesFeature(string $bodiesPath): array
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
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function meetingsFeature(string $meetingsPath): array
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
                                    'href' => '/admin/x/' . self::MEETING_DETAIL,
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
        string $invitationsPath
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
                        [
                            'type' => 'selector',
                            'name' => 'meeting',
                            'label' => 'Meeting',
                            'source' => $meetingsPath,
                            'valueField' => 'id',
                            'labelField' => 'display_title',
                            'placeholder' => 'Choose a meeting',
                        ],
                        [
                            'type' => 'dataRecord',
                            'id' => 'meetingRecord',
                            // A PATH token, which `recordPath` allows and a plain
                            // `apiPath` does not — this is the one block in the
                            // tree addressing a single resource rather than a
                            // collection.
                            'source' => $meetingsPath . '/{meeting}',
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
                    'title' => 'Agenda',
                    'children' => [
                        [
                            'type' => 'dataTable',
                            'source' => $agendaItemsPath,
                            'params' => [['param' => 'meeting_id', 'from' => 'meeting']],
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
                            'params' => [['param' => 'meeting_id', 'from' => 'meeting']],
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
                            'type' => 'dataTable',
                            'source' => $invitationsPath,
                            'params' => [['param' => 'meeting_id', 'from' => 'meeting']],
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
            ],
        ];
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
