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
                        // THE QUESTION EDITOR — the Fields section's whole point,
                        // and a deliberate retirement of the modal that used to
                        // be here.
                        //
                        // WHY THE MODAL WENT. Authoring a form is composition:
                        // you write a question, read it back next to the one
                        // above it, move it, change your mind about the one
                        // below. A dialog answers a different question — "add
                        // one thing, in isolation, then close" — and it made the
                        // ordinary act of arranging a form into a sequence of
                        // disconnected single-field decisions with a `position`
                        // NUMBER as the only way to say what goes where. The
                        // author could not see the form they were building.
                        //
                        // So: a vertical stack of question cards, edited in
                        // place, reordered and removed on the card, saved
                        // together. One `PUT` of the whole set, which is what
                        // {@see \Whity\Api\FormFieldsApiHandler::replace()}
                        // exists for — and `position` disappears as a field
                        // somebody types, because the order IS the order of the
                        // cards.
                        //
                        // THE FIELD TABLE THAT USED TO SIT BELOW IS GONE WITH IT,
                        // and not merely as tidying. It would have shown the
                        // STORED set beside an editor showing the set about to be
                        // saved, refreshed by nothing this form does — so the
                        // moment an author saved, the table beneath would have
                        // gone on displaying the previous order. A second view
                        // that silently goes stale is worse than no second view,
                        // and its one unique affordance (Remove a field) is now
                        // what removing a card and saving does.
                        //
                        // THE SAVE IS A REPLACEMENT, AND THAT IS SAID OUT LOUD.
                        // A question removed from the stack and then saved is
                        // WITHDRAWN: answers already given to it stay recorded
                        // and stop having a label. That is the same consequence
                        // the old per-row Remove action carried, so nothing new
                        // is destructible here — but it now happens on save
                        // rather than on click, which is a change an author has
                        // to be told about rather than left to discover.
                        //
                        // The renderer will not let this array submit until it
                        // has actually loaded the stored questions for the form
                        // the selector names — see the `fieldArray` entry in
                        // {@see \Whity\Sdk\Frontend\Blocks\BlockContract}. An
                        // editor that rendered empty while loading would save
                        // "this form has no questions" over a form that has ten.
                        [
                            'type' => 'text',
                            'value' => 'Add, edit and reorder this form\'s questions below, then save '
                                . 'them together. Saving replaces the whole set: a question you '
                                . 'remove here is withdrawn when you save, and answers already '
                                . 'given to it stay recorded but stop having a label.',
                            'tone' => 'muted',
                        ],
                        [
                            'type' => 'form',
                            // `{builderForm}` resolves from the selector above —
                            // the same master-detail token the edit-form and
                            // public-link controls use to fill a PATH segment
                            // that `params` cannot reach.
                            'submit' => [
                                'method' => 'PUT',
                                'endpoint' => '/api/v1/forms/{builderForm}/fields',
                            ],
                            'requiredPermission' => CorePermissions::FORMS_MANAGE,
                            'children' => [
                                [
                                    'type' => 'fieldArray',
                                    // The payload key the endpoint reads. Named
                                    // for the wire, not for the screen — the
                                    // human noun is `label`/`itemLabel`.
                                    'name' => 'fields',
                                    'label' => 'Questions',
                                    'itemLabel' => 'Question',
                                    // The FLAT read, addressed by query param,
                                    // because `params` cannot fill a path
                                    // segment. The WRITE stays nested under the
                                    // form — see FormFieldsApiHandler for why
                                    // that asymmetry is not a hole.
                                    'source' => '/api/v1/form-fields',
                                    'params' => [
                                        ['param' => 'form_id', 'from' => 'builderForm'],
                                    ],
                                    // NO `min`. A form with no questions is a
                                    // real state an author may want, and a
                                    // minimum here would make "withdraw the last
                                    // question" impossible through the only
                                    // surface that can withdraw one.
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
                                            // Derived from the vocabulary rather
                                            // than transcribed from it: a kind
                                            // added to FieldType and not to a
                                            // hand-written list here is a kind
                                            // the builder cannot author, which is
                                            // invisible until somebody needs it.
                                            'options' => self::fieldTypeOptions(),
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
                                            'placeholder' => 'Groups questions under one heading',
                                        ],
                                        [
                                            'type' => 'select',
                                            'name' => 'prefill_source',
                                            'label' => 'Prefill from the submitter',
                                            'options' => self::prefillSourceOptions(),
                                        ],
                                    ],
                                ],
                                [
                                    'type' => 'submitButton',
                                    'label' => 'Save questions',
                                    'variant' => 'primary',
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
     * The question-kind picker's options, DERIVED from {@see FieldType::all()}
     * rather than transcribed beside it.
     *
     * The version this replaced was a hand-written list of ten `['value' =>
     * 'text', 'label' => 'Short text']` pairs, and it was correct on the day it
     * was written. That is the whole problem: the vocabulary is closed and
     * central, the picker was a copy of it, and nothing compared the two. A kind
     * added to `FieldType` would be accepted by the API, refused by no
     * validator, and simply absent from the only screen that authors one — a
     * capability that exists and cannot be reached, which is the failure this
     * codebase keeps finding in its own copies of a list.
     *
     * The `?? $type` fallback is deliberate and is the reason this is safe to
     * leave unattended: a kind with no prose here still appears, labelled by its
     * own key. Ugly, present, and fixable — rather than missing and invisible.
     *
     * @return list<array{value: string, label: string}>
     */
    private static function fieldTypeOptions(): array
    {
        $labels = [
            FieldType::TEXT => 'Short text',
            FieldType::TEXTAREA => 'Long text',
            FieldType::NUMBER => 'Number',
            FieldType::DATE => 'Date',
            FieldType::SELECT => 'Choose one',
            FieldType::MULTISELECT => 'Choose several',
            FieldType::CHECKBOX => 'Yes / no',
            FieldType::FILE => 'File',
            FieldType::PROFILE_REF => 'A person',
            FieldType::OU_REF => 'An organizational unit',
        ];

        $options = [];
        foreach (FieldType::all() as $type) {
            $options[] = ['value' => $type, 'label' => $labels[$type] ?? $type];
        }

        return $options;
    }

    /**
     * The prefill picker's options, derived from {@see PrefillSource::all()}.
     *
     * EVERY declared source is offered, INCLUDING the two that nothing in this
     * schema stores — and each of those says so in its own label. That is
     * {@see PrefillSource}'s argument carried through to the surface it was
     * written for: an author who cannot see "phone" adds a plain text question
     * and every submitter retypes a number, whereas an author who sees "Their
     * phone — nothing in this install stores it yet" makes an informed choice
     * and the seam stays visible.
     *
     * The empty-string option is "do not prefill", which is what a field carries
     * when the column is null.
     *
     * @return list<array{value: string, label: string}>
     */
    private static function prefillSourceOptions(): array
    {
        $labels = [
            PrefillSource::DISPLAY_NAME => 'Their name',
            PrefillSource::EMAIL => 'Their email',
            PrefillSource::OU => 'Their unit',
            PrefillSource::PHONE => 'Their phone',
            PrefillSource::JOB_TITLE => 'Their job title',
        ];

        $options = [['value' => '', 'label' => 'Do not prefill']];
        foreach (PrefillSource::all() as $source) {
            $label = $labels[$source] ?? $source;
            if (!PrefillSource::isBacked($source)) {
                $label .= ' — nothing in this install stores it yet';
            }
            $options[] = ['value' => $source, 'label' => $label];
        }

        return $options;
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
