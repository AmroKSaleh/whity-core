<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use Whity\Core\RBAC\CorePermissions;

/**
 * The three screens the forms engine exposes to the schema-driven UI, as
 * `screen: 'blocks'` feature descriptors.
 *
 * WHY DESCRIPTORS AND NOT THREE HAND-WRITTEN PAGES
 * -------------------------------------------------
 * The web app already hosts any feature descriptor at `/admin/x/{featureId}` and
 * renders a `screen: 'blocks'` tree through `BlockRenderer`; the desktop and
 * mobile clients consume the same descriptors. Writing three React pages instead
 * would produce three surfaces that only the web has, and every other client
 * would need its own copy — which is the situation the block contract exists to
 * end.
 *
 * So these are trees of EXISTING block types. Not one new type is introduced,
 * and that restraint is structural rather than tidy: a new type is a change to
 * {@see \Whity\Sdk\Frontend\Blocks\BlockContract} plus a renderer on every
 * platform plus a live instance in the UI-kit showcase, whose every-type coverage
 * test fails without one. Everything below is `section`, `card`, `heading`,
 * `text`, `alert`, `dataTable`, `selector`, `modal`, `form` and the input leaves.
 *
 * THE TREES ARE STATIC, AND THE DATA IS NOT
 * ------------------------------------------
 * Every list is a `dataTable` bound to a real API path, so nothing here embeds a
 * form, a field or a submission. The trees are constants; what a viewer sees is
 * whatever the permission-filtered endpoints return for them. That is also why
 * these are safe to validate once and cache: they cannot vary per caller.
 *
 * PATHS ARE WRITTEN `/api/v1/...` AND THAT IS DELIBERATE
 * -------------------------------------------------------
 * {@see \Whity\Core\Router::register()} prepends `/v1` to a DECLARED path, so
 * `public/index.php` registers `/api/forms` and the live URL is `/api/v1/forms`.
 * A block's `source` is not a declaration — it is a URL a client will fetch — so
 * it carries the version itself. The plugin loader's version-rewrite exists for
 * exactly this reason and only applies to plugin descriptors; core writes the
 * emitted form directly.
 *
 * PERMISSIONS MIRROR THE ENDPOINTS BEHIND EACH SCREEN
 * ----------------------------------------------------
 * A descriptor's `requiredPermission` is UI metadata and grants nothing —
 * {@see \Whity\Api\FrontendFeaturesApiHandler} filters per caller and the data
 * APIs enforce their own route-level RBAC regardless of what any client renders.
 * They are set to match anyway, so a screen a person can see is a screen whose
 * primary fetch will succeed.
 *
 * Stateless — worker-safe.
 */
final class FormFrontendFeatures
{
    /** The builder: author forms and their fields. */
    public const BUILDER_ID = 'forms-builder';

    /** The catalogue: which forms exist, and what has been submitted to them. */
    public const CATALOG_ID = 'forms-catalog';

    /** One person's own submissions. */
    public const MY_SUBMISSIONS_ID = 'my-form-submissions';

    /**
     * Static declaration only — never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Every core forms descriptor, in the shape
     * {@see \Whity\Api\FrontendFeaturesApiHandler} consumes.
     *
     * `plugin` is `'core'` rather than empty: the handler emits the key on every
     * feature and a client rendering "provided by" needs something true to show.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [self::builder(), self::catalog(), self::mySubmissions()];
    }

    /**
     * The BUILDER — author forms, then author their fields.
     *
     * MASTER-DETAIL RATHER THAN TWO SCREENS. A `selector` publishes the chosen
     * form's id, and the field table below it reads that id through its `params`
     * facet, so picking a form re-fetches its fields without a page change. That
     * is what the selector/params pair is for, and it means the two things an
     * author moves between constantly — the form and its fields — are never more
     * than a dropdown apart.
     *
     * THE PUBLIC LINK (migration 132) IS DECLARED IN TWO PLACES, DELIBERATELY.
     * OPENING one is a modal on the detail pane — a considered act, with the
     * optional window beside it and a warning above it. CLOSING one is a row
     * action on the catalogue table, because closing is what somebody does the
     * moment they discover a form is collecting something it should not be, and
     * an emergency control behind a dropdown and a modal is a control that
     * arrives late. The resulting address is a FACT the server composed and the
     * detail pane displays; nothing here builds a URL.
     *
     * @return array<string, mixed>
     */
    private static function builder(): array
    {
        return [
            'id' => self::BUILDER_ID,
            'plugin' => 'core',
            'label' => 'Form Builder',
            'icon' => 'forms',
            'group' => 'records',
            'order' => 4,
            'screen' => 'blocks',
            'requiredPermission' => CorePermissions::FORMS_MANAGE,
            'blocks' => [
                [
                    'type' => 'section',
                    'title' => 'Forms',
                    'children' => [
                        [
                            'type' => 'text',
                            'value' => 'Author a form, add its fields, then publish it. '
                                . 'A published form accepts submissions; archiving one stops new '
                                . 'submissions without touching what has already been submitted.',
                            'tone' => 'muted',
                        ],
                        [
                            'type' => 'dataTable',
                            'source' => '/api/v1/forms',
                            'emptyText' => 'No forms yet. Create one to get started.',
                            'pageSize' => 20,
                            'columns' => [
                                ['key' => 'form_key', 'label' => 'Key', 'sortable' => true, 'filterable' => true],
                                ['key' => 'status', 'label' => 'Status', 'sortable' => true],
                                ['key' => 'version', 'label' => 'Version'],
                                // Which forms this organisation has opened to
                                // people with no account. In the LIST rather than
                                // only on the detail pane, because "what have we
                                // exposed?" is a question asked about the whole
                                // catalogue at once, and an answer somebody has
                                // to click through twelve forms to assemble is an
                                // answer nobody assembles.
                                ['key' => 'public_enabled', 'label' => 'Public', 'sortable' => true],
                            ],
                            'rowActions' => [
                                // Publish and archive are POSTs with an empty
                                // body, templated with the row's own id. They are
                                // row actions rather than buttons on a detail page
                                // because publishing is the last thing an author
                                // does and making them navigate for it is how a
                                // form stays in draft by accident.
                                [
                                    'label' => 'Publish',
                                    'endpoint' => '/api/v1/forms/{id}/publish',
                                    'method' => 'POST',
                                ],
                                [
                                    'label' => 'Archive',
                                    'endpoint' => '/api/v1/forms/{id}/archive',
                                    'method' => 'POST',
                                    'confirm' => 'Archiving stops new submissions. '
                                        . 'Everything already submitted is kept. Continue?',
                                ],
                                // THE SHUT-OFF, and it is here rather than only
                                // in the modal below because of WHEN it gets
                                // used. Opening a public link is a considered act
                                // that deserves a form with a date picker;
                                // closing one is what somebody does the moment
                                // they realise a form is collecting something it
                                // should not be, and that must be one click from
                                // the list. A DELETE row action rather than an
                                // `actionButton` because `submitSpec` accepts
                                // POST/PUT/PATCH only — `rowActionList` is the
                                // one facet in the contract that carries DELETE.
                                [
                                    'label' => 'Close public link',
                                    'endpoint' => '/api/v1/forms/{id}/public-link',
                                    'method' => 'DELETE',
                                    'confirm' => 'The public address stops working immediately and '
                                        . 'cannot be brought back — re-opening the form mints a '
                                        . 'different one. Submissions already received are kept. '
                                        . 'Close it?',
                                ],
                            ],
                        ],
                        [
                            'type' => 'modal',
                            'id' => 'newForm',
                            'title' => 'New form',
                            'trigger' => 'New form',
                            'variant' => 'primary',
                            'children' => [
                                [
                                    'type' => 'form',
                                    'submit' => ['method' => 'POST', 'endpoint' => '/api/v1/forms'],
                                    'requiredPermission' => CorePermissions::FORMS_MANAGE,
                                    'children' => [
                                        [
                                            'type' => 'textInput',
                                            'name' => 'form_key',
                                            'label' => 'Key',
                                            'placeholder' => 'equipment-request',
                                            'required' => true,
                                        ],
                                        [
                                            // The bilingual pair rather than a
                                            // plain text input: Arabic and English
                                            // are both first-class here, and a form
                                            // named in one language only is the
                                            // ordinary case the `{ar?, en?}` shape
                                            // already handles.
                                            'type' => 'bilingualText',
                                            'name' => 'name',
                                            'label' => 'Name',
                                            'required' => true,
                                        ],
                                        [
                                            'type' => 'textArea',
                                            'name' => 'description',
                                            'label' => 'Description',
                                            'rows' => 3,
                                        ],
                                        [
                                            // Populated from the tenant's own route
                                            // templates rather than a typed id, so
                                            // an author picks a flow by its name and
                                            // cannot wire a form to an integer that
                                            // means nothing.
                                            'type' => 'referenceSelect',
                                            'name' => 'route_template_id',
                                            'label' => 'Route submissions through',
                                            'source' => '/api/v1/document-route-templates',
                                            'valueField' => 'id',
                                            'labelField' => 'name',
                                            'placeholder' => 'Collect only — do not circulate',
                                        ],
                                        [
                                            'type' => 'submitButton',
                                            'label' => 'Create form',
                                            'variant' => 'primary',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'section',
                    'title' => 'Fields',
                    'children' => [
                        [
                            'type' => 'selector',
                            'name' => 'builderForm',
                            'label' => 'Form',
                            'source' => '/api/v1/forms',
                            'valueField' => 'id',
                            'labelField' => 'form_key',
                            'placeholder' => 'Pick a form to edit its fields',
                        ],
                        // WHAT THE BUILDER COULD NOT DO. Creating a form worked
                        // and removing a field worked, and nothing in between:
                        // no way to change the form once made, no way to add a
                        // field to it, nothing that showed the thing being
                        // built. The API supported all three already, so this is
                        // the declaration catching up with it rather than new
                        // capability.
                        [
                            'type' => 'dataRecord',
                            'id' => 'builderFormDetail',
                            'source' => '/api/v1/forms/{builderForm}',
                            'emptyText' => 'Pick a form above to see and edit it.',
                            'fields' => [
                                ['field' => 'form_key', 'label' => 'Key'],
                                ['field' => 'status', 'label' => 'Status'],
                                ['field' => 'version', 'label' => 'Version'],
                                ['field' => 'description', 'label' => 'Description'],
                                ['field' => 'accepts_submissions', 'label' => 'Accepting submissions'],
                                // THE LINK ITSELF. `public_url` is the whole
                                // point of the control below — an address an
                                // author copies onto a poster or into an email —
                                // and it is a FACT the server composed from the
                                // slug and this instance's own APP_URL, not
                                // something a client assembles. A client that
                                // built the URL itself would be a second place
                                // the address is spelled, and the day the
                                // deployment moves it would be the wrong one.
                                //
                                // It is empty until the link is opened, and it is
                                // ALSO empty when the instance has never been told
                                // its own address (APP_URL unset) — which is a
                                // real state and shows as a blank field beside
                                // "Open to the public: yes". `public_slug` is
                                // deliberately NOT shown beside it: an author
                                // needs the address, and a bare slug is a
                                // credential in a place people copy from
                                // carelessly.
                                ['field' => 'public_enabled', 'label' => 'Open to the public'],
                                ['field' => 'public_url', 'label' => 'Public link'],
                                ['field' => 'public_closes_at', 'label' => 'Public link closes'],
                            ],
                        ],
                        [
                            'type' => 'modal',
                            'id' => 'editFormModal',
                            'title' => 'Edit this form',
                            'trigger' => 'Edit form',
                            'variant' => 'secondary',
                            'children' => [
                                [
                                    'type' => 'form',
                                    // `{builderForm}` resolves from the selector
                                    // above: a submit endpoint interpolates its
                                    // tokens from the same master-detail context
                                    // a source does, so the chosen form fills the
                                    // PATH segment that `params` cannot reach.
                                    'submit' => ['method' => 'PATCH', 'endpoint' => '/api/v1/forms/{builderForm}'],
                                    'requiredPermission' => CorePermissions::FORMS_MANAGE,
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
                                            'rows' => 3,
                                        ],
                                        [
                                            'type' => 'referenceSelect',
                                            'name' => 'route_template_id',
                                            'label' => 'Route submissions through',
                                            'source' => '/api/v1/document-route-templates',
                                            'valueField' => 'id',
                                            'labelField' => 'name',
                                            'placeholder' => 'Do not route submissions',
                                        ],
                                        ['type' => 'submitButton', 'label' => 'Save changes'],
                                    ],
                                ],
                            ],
                        ],
                        // THE PUBLIC LINK. A modal rather than a bare button
                        // because opening a form to everybody with an internet
                        // connection deserves a moment of deliberation and a
                        // place to put the deadline — and because the window is
                        // the difference between a link that closes itself on
                        // the 30th and one that stays open until somebody
                        // remembers. A control that could only be closed by hand
                        // is a control that stays open.
                        //
                        // Both dates are OPTIONAL. The ordinary case is a form
                        // that collects until it is closed, and requiring a
                        // deadline to open a link would make the ordinary case
                        // the awkward one.
                        [
                            'type' => 'modal',
                            'id' => 'publicLinkModal',
                            'title' => 'Open this form to the public',
                            'trigger' => 'Open public link',
                            'variant' => 'secondary',
                            'children' => [
                                [
                                    'type' => 'alert',
                                    'variant' => 'warning',
                                    'title' => 'Anyone with the link can submit',
                                    'body' => 'This creates a long, random, unguessable web address. '
                                        . 'Anybody who has it can fill this form in without signing '
                                        . 'in, and every submission is recorded and circulated '
                                        . 'exactly as one from a colleague would be — with no name '
                                        . 'attached, because there is no account behind it. Publish '
                                        . 'the address only where you mean to. Closing the link '
                                        . 'stops it immediately; re-opening mints a different one.',
                                ],
                                [
                                    'type' => 'form',
                                    // `{builderForm}` resolves from the selector
                                    // above — a submit endpoint interpolates its
                                    // tokens from the same master-detail context
                                    // a source does, so the chosen form fills the
                                    // PATH segment `params` cannot reach. Same
                                    // mechanism the edit-form and add-field
                                    // modals beside this one already use.
                                    'submit' => [
                                        'method' => 'POST',
                                        'endpoint' => '/api/v1/forms/{builderForm}/public-link',
                                    ],
                                    'requiredPermission' => CorePermissions::FORMS_MANAGE,
                                    'children' => [
                                        [
                                            'type' => 'dateInput',
                                            'name' => 'opens_at',
                                            'label' => 'Opens (optional)',
                                        ],
                                        [
                                            'type' => 'dateInput',
                                            'name' => 'closes_at',
                                            'label' => 'Closes (optional)',
                                        ],
                                        [
                                            'type' => 'submitButton',
                                            'label' => 'Open public link',
                                            'variant' => 'primary',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'type' => 'modal',
                            'id' => 'addFieldModal',
                            'title' => 'Add a field',
                            'trigger' => 'Add field',
                            'size' => 'lg',
                            'children' => [
                                [
                                    'type' => 'form',
                                    'submit' => ['method' => 'POST', 'endpoint' => '/api/v1/forms/{builderForm}/fields'],
                                    'requiredPermission' => CorePermissions::FORMS_MANAGE,
                                    'children' => [
                                        [
                                            'type' => 'textInput',
                                            'name' => 'field_key',
                                            'label' => 'Key',
                                            'placeholder' => 'snake_case, unique within this form',
                                            'required' => true,
                                        ],
                                        [
                                            'type' => 'select',
                                            'name' => 'field_type',
                                            'label' => 'Kind',
                                            'required' => true,
                                            // The vocabulary FieldType accepts, not a
                                            // longer list a person could pick from and
                                            // then be refused on submit.
                                            'options' => [
                                                ['value' => 'text', 'label' => 'Short text'],
                                                ['value' => 'textarea', 'label' => 'Long text'],
                                                ['value' => 'number', 'label' => 'Number'],
                                                ['value' => 'date', 'label' => 'Date'],
                                                ['value' => 'select', 'label' => 'Choose one'],
                                                ['value' => 'multiselect', 'label' => 'Choose several'],
                                                ['value' => 'checkbox', 'label' => 'Yes / no'],
                                                ['value' => 'file', 'label' => 'File'],
                                                ['value' => 'profile_ref', 'label' => 'A person'],
                                                ['value' => 'ou_ref', 'label' => 'An organizational unit'],
                                            ],
                                        ],
                                        [
                                            'type' => 'bilingualText',
                                            'name' => 'label',
                                            'label' => 'Label',
                                            'required' => true,
                                            'arLabel' => 'Arabic',
                                            'enLabel' => 'English',
                                        ],
                                        [
                                            'type' => 'textArea',
                                            'name' => 'help_text',
                                            'label' => 'Help text',
                                            'rows' => 2,
                                        ],
                                        [
                                            'type' => 'checkbox',
                                            'name' => 'is_required',
                                            'label' => 'Required',
                                        ],
                                        [
                                            'type' => 'textInput',
                                            'name' => 'section_key',
                                            'label' => 'Section',
                                            'placeholder' => 'Groups fields under one heading',
                                        ],
                                        [
                                            'type' => 'numberInput',
                                            'name' => 'position',
                                            'label' => 'Position',
                                            'min' => 1,
                                        ],
                                        [
                                            'type' => 'select',
                                            'name' => 'prefill_source',
                                            'label' => 'Prefill from the submitter',
                                            // `profile.phone` and `profile.job_title` are
                                            // declared but have no backing column yet;
                                            // the render response reports them under
                                            // `unresolved_prefill` rather than quietly
                                            // returning an empty string.
                                            'options' => [
                                                ['value' => '', 'label' => 'Do not prefill'],
                                                ['value' => 'profile.display_name', 'label' => 'Their name'],
                                                ['value' => 'profile.email', 'label' => 'Their email'],
                                                ['value' => 'profile.ou', 'label' => 'Their unit'],
                                            ],
                                        ],
                                        ['type' => 'submitButton', 'label' => 'Add field'],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'type' => 'dataTable',
                            // The FLAT read, not the nested one: `params` append
                            // query params to a fixed source and cannot fill a
                            // path segment, so a selector-driven field table has
                            // to address the form by query. Writes stay nested —
                            // see FormFieldsApiHandler for why the asymmetry is
                            // not a hole.
                            'source' => '/api/v1/form-fields',
                            'emptyText' => 'Pick a form above, then add its first field.',
                            'columns' => [
                                ['key' => 'position', 'label' => '#'],
                                ['key' => 'field_key', 'label' => 'Key', 'filterable' => true],
                                ['key' => 'field_type', 'label' => 'Kind', 'sortable' => true],
                                ['key' => 'section_key', 'label' => 'Section'],
                                ['key' => 'prefill_source', 'label' => 'Prefills from'],
                            ],
                            // The selected form's id is appended as a query param.
                            // The base `source` stays a plain path — only
                            // whitelisted params interpolate — so nothing here
                            // widens what a client may fetch.
                            'params' => [
                                ['param' => 'form_id', 'from' => 'builderForm'],
                            ],
                            'rowActions' => [
                                [
                                    'label' => 'Remove',
                                    'endpoint' => '/api/v1/forms/{form_id}/fields/{id}',
                                    'method' => 'DELETE',
                                    'confirm' => 'Answers already given to this field stay recorded, '
                                        . 'but stop having a label. Remove it?',
                                ],
                            ],
                        ],
                        [
                            'type' => 'alert',
                            'variant' => 'info',
                            'title' => 'Prefilled fields',
                            'body' => 'A field can start filled in from the submitter\'s own saved '
                                . 'details, so they do not retype what the organisation already knows. '
                                . 'Sources that nothing in this install stores yet are shown in the '
                                . 'field editor as unavailable, and simply leave the field empty.',
                        ],
                        // Said HERE, beside the field table, rather than only in
                        // the 422 the server returns. An author who learns which
                        // fields a public form cannot carry at the moment they
                        // try to open the link has already built the form around
                        // them; an author who reads it while adding fields
                        // builds a different form.
                        [
                            'type' => 'alert',
                            'variant' => 'info',
                            'title' => 'Forms opened to the public',
                            'body' => 'A published form can be given a public web link that people '
                                . 'with no account can fill in. Such a form may only ask for values '
                                . 'a stranger can actually give, so person, unit and file fields are '
                                . 'refused on it: a person or unit field would ask somebody outside '
                                . 'to name one of your records, and a file field needs an upload '
                                . 'they cannot perform. Public submissions carry no submitter name, '
                                . 'and nothing is ever pre-filled for them.',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The CATALOGUE — which forms exist, and what has come back.
     *
     * Gated on `forms:read`, which is the approver's permission: this is the
     * screen for the person who reads submissions, not the one who authors forms.
     *
     * @return array<string, mixed>
     */
    private static function catalog(): array
    {
        return [
            'id' => self::CATALOG_ID,
            'plugin' => 'core',
            'label' => 'Forms',
            'icon' => 'clipboard-list',
            'group' => 'records',
            'order' => 5,
            'screen' => 'blocks',
            'requiredPermission' => CorePermissions::FORMS_READ,
            'blocks' => [
                [
                    'type' => 'section',
                    'title' => 'Forms',
                    'children' => [
                        [
                            'type' => 'dataTable',
                            'source' => '/api/v1/forms?status=published',
                            'emptyText' => 'No published forms yet.',
                            'pageSize' => 20,
                            'columns' => [
                                ['key' => 'form_key', 'label' => 'Key', 'sortable' => true, 'filterable' => true],
                                ['key' => 'version', 'label' => 'Version'],
                                ['key' => 'created_at', 'label' => 'Created', 'sortable' => true],
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'section',
                    'title' => 'Submissions',
                    'children' => [
                        [
                            'type' => 'selector',
                            'name' => 'catalogForm',
                            'label' => 'Form',
                            'source' => '/api/v1/forms',
                            'valueField' => 'id',
                            'labelField' => 'form_key',
                            'placeholder' => 'All forms',
                        ],
                        [
                            'type' => 'dataTable',
                            'source' => '/api/v1/form-submissions',
                            'emptyText' => 'Nothing has been submitted yet.',
                            'pageSize' => 25,
                            'columns' => [
                                ['key' => 'form_key', 'label' => 'Form', 'sortable' => true, 'filterable' => true],
                                ['key' => 'submitted_at', 'label' => 'Submitted', 'sortable' => true],
                                ['key' => 'form_version', 'label' => 'Answered version'],
                            ],
                            'params' => [
                                ['param' => 'form_id', 'from' => 'catalogForm'],
                            ],
                        ],
                        [
                            'type' => 'text',
                            'value' => 'A submission that was routed also exists as a document, '
                                . 'so it carries the same approvals, trail and verification as '
                                . 'anything else the organisation circulates.',
                            'tone' => 'muted',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * MY SUBMISSIONS — what one person has filed.
     *
     * Gated on `forms:submit`, not `forms:read`: the rows already name exactly one
     * person, so a tenant-wide read permission has nothing left to decide, and
     * requiring it would hide this screen from the very people whose submissions
     * are in it.
     *
     * @return array<string, mixed>
     */
    private static function mySubmissions(): array
    {
        return [
            'id' => self::MY_SUBMISSIONS_ID,
            'plugin' => 'core',
            'label' => 'My Submissions',
            'icon' => 'file-check',
            'group' => 'overview',
            'order' => 3,
            'screen' => 'blocks',
            'requiredPermission' => CorePermissions::FORMS_SUBMIT,
            'blocks' => [
                [
                    'type' => 'section',
                    'title' => 'My submissions',
                    'children' => [
                        [
                            'type' => 'dataTable',
                            // The `/me/…` endpoint, never the tenant-wide one with
                            // a filter. The route decides whose rows these are, so
                            // no query param a client could omit or change is
                            // standing between a person and somebody else's data.
                            'source' => '/api/v1/me/form-submissions',
                            'emptyText' => 'You have not submitted anything yet.',
                            'pageSize' => 25,
                            'columns' => [
                                ['key' => 'form_key', 'label' => 'Form', 'sortable' => true, 'filterable' => true],
                                ['key' => 'submitted_at', 'label' => 'Submitted', 'sortable' => true],
                            ],
                        ],
                        [
                            'type' => 'text',
                            'value' => 'A submission cannot be edited once it is made — people may '
                                . 'already have acted on it. If something was wrong, submit again; '
                                . 'both submissions are kept, with their times.',
                            'tone' => 'muted',
                        ],
                    ],
                ],
            ],
        ];
    }
}
