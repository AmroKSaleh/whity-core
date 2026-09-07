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
 *
 * THE ENGLISH THESE SCREENS ARE WRITTEN IN (#1044).
 *
 * Every string in the declarations below reaches the browser already worded,
 * so no screen's own `t()` ever sees it. This block is how the catalogue does:
 * the extractor reads text, not method bodies, so the wording is stated once
 * here for it to seed and once in the declaration for a reader to see beside
 * the field it belongs to.
 *
 * THE DUPLICATION IS GUARDED. `FormFeatureLabelsTest` walks the real
 * declarations, reads this block back through the real extractor, and fails on
 * any disagreement in either direction — including a key here that no node uses
 * any more, and a node carrying text with no key at all.
 *
 * The two option lists are keyed from their own VALUES, so a new field type or
 * prefill source arrives carrying its key. Their English is computed (a label
 * map, plus an "unavailable" suffix), which is exactly the case `@i18n-keys`
 * exists for.
 *
 * @i18n-keys admin
 *   forms.builder.action.archive.confirm = Archiving stops new submissions. Everything already submitted is kept. Continue?
 *   forms.builder.action.archive.label = Archive
 *   forms.builder.action.closeLink.confirm = The public address stops working immediately and cannot be brought back — re-opening the form mints a different one. Submissions already received are kept. Close it?
 *   forms.builder.action.closeLink.label = Close public link
 *   forms.builder.action.create.label = Create form
 *   forms.builder.action.fill.label = Fill in
 *   forms.builder.action.openLink.label = Open public link
 *   forms.builder.action.publicLink.label = Public link
 *   forms.builder.action.publish.label = Publish
 *   forms.builder.action.save.label = Save changes
 *   forms.builder.action.saveQuestions.label = Save questions
 *   forms.builder.column.key.label = Key
 *   forms.builder.column.public.label = Public
 *   forms.builder.column.status.label = Status
 *   forms.builder.column.version.label = Version
 *   forms.builder.create.title = New form
 *   forms.builder.create.trigger = New form
 *   forms.builder.detail.accepting.label = Accepting submissions
 *   forms.builder.detail.description.label = Description
 *   forms.builder.detail.empty.emptyText = Pick a form above to see and edit it.
 *   forms.builder.detail.key.label = Key
 *   forms.builder.detail.public.label = Open to the public
 *   forms.builder.detail.publicCloses.label = Public link closes
 *   forms.builder.detail.publicLink.label = Public link
 *   forms.builder.detail.status.label = Status
 *   forms.builder.detail.version.label = Version
 *   forms.builder.edit.title = Edit this form
 *   forms.builder.edit.trigger = Edit form
 *   forms.builder.field.closes.label = Closes (optional)
 *   forms.builder.field.description.label = Description
 *   forms.builder.field.form.label = Form
 *   forms.builder.field.form.placeholder = Pick a form to edit its fields
 *   forms.builder.field.help.label = Help text
 *   forms.builder.field.key.label = Key
 *   forms.builder.field.key.placeholder = equipment-request
 *   forms.builder.field.kind.label = Kind
 *   forms.builder.field.label.arLabel = Arabic
 *   forms.builder.field.label.enLabel = English
 *   forms.builder.field.label.label = Label
 *   forms.builder.field.name.arLabel = Arabic
 *   forms.builder.field.name.enLabel = English
 *   forms.builder.field.name.label = Name
 *   forms.builder.field.opens.label = Opens (optional)
 *   forms.builder.field.prefill.label = Prefill from the submitter
 *   forms.builder.field.questionKey.label = Key
 *   forms.builder.field.questionKey.placeholder = snake_case, unique within this form
 *   forms.builder.field.questions.itemLabel = Question
 *   forms.builder.field.questions.label = Questions
 *   forms.builder.field.required.label = Required
 *   forms.builder.field.route.label = Route submissions through
 *   forms.builder.field.route.placeholder = Collect only — do not circulate
 *   forms.builder.field.routeEdit.label = Route submissions through
 *   forms.builder.field.routeEdit.placeholder = Do not route submissions
 *   forms.builder.field.section.label = Section
 *   forms.builder.field.section.placeholder = Groups questions under one heading
 *   forms.builder.fieldType.checkbox.label = Yes / no
 *   forms.builder.fieldType.date.label = Date
 *   forms.builder.fieldType.file.label = File
 *   forms.builder.fieldType.multiselect.label = Choose several
 *   forms.builder.fieldType.number.label = Number
 *   forms.builder.fieldType.ou_ref.label = An organizational unit
 *   forms.builder.fieldType.profile_ref.label = A person
 *   forms.builder.fieldType.select.label = Choose one
 *   forms.builder.fieldType.text.label = Short text
 *   forms.builder.fieldType.textarea.label = Long text
 *   forms.builder.intro.value = Author a form, add its fields, then publish it. A published form accepts submissions; archiving one stops new submissions without touching what has already been submitted.
 *   forms.builder.nav.label = Form Builder
 *   forms.builder.prefill.display_name.label = Their name
 *   forms.builder.prefill.email.label = Their email
 *   forms.builder.prefill.job_title.label = Their job title — nothing in this install stores it yet
 *   forms.builder.prefill.none.label = Do not prefill
 *   forms.builder.prefill.note.body = A field can start filled in from the submitter's own saved details, so they do not retype what the organisation already knows. Sources that nothing in this install stores yet are shown in the field editor as unavailable, and simply leave the field empty.
 *   forms.builder.prefill.note.title = Prefilled fields
 *   forms.builder.prefill.ou.label = Their unit
 *   forms.builder.prefill.ou_id.label = Their unit, for a unit field
 *   forms.builder.prefill.phone.label = Their phone — nothing in this install stores it yet
 *   forms.builder.public.note.body = A published form can be given a public web link that people with no account can fill in. Such a form may only ask for values a stranger can actually give, so person, unit and file fields are refused on it: a person or unit field would ask somebody outside to name one of your records, and a file field needs an upload they cannot perform. Public submissions carry no submitter name, and nothing is ever pre-filled for them.
 *   forms.builder.public.note.title = Forms opened to the public
 *   forms.builder.public.title = Open this form to the public
 *   forms.builder.public.trigger = Open public link
 *   forms.builder.public.warning.body = This creates a long, random, unguessable web address. Anybody who has it can fill this form in without signing in, and every submission is recorded and circulated exactly as one from a colleague would be — with no name attached, because there is no account behind it. Publish the address only where you mean to. Closing the link stops it immediately; re-opening mints a different one.
 *   forms.builder.public.warning.title = Anyone with the link can submit
 *   forms.builder.questions.intro.value = Add, edit and reorder this form's questions below, then save them together. Saving replaces the whole set: a question you remove here is withdrawn when you save, and answers already given to it stay recorded but stop having a label.
 *   forms.builder.section.fields.title = Fields
 *   forms.builder.section.forms.title = Forms
 *   forms.builder.table.empty.emptyText = No forms yet. Create one to get started.
 *   forms.catalog.action.fill.label = Fill in
 *   forms.catalog.action.publicLink.label = Public link
 *   forms.catalog.column.created.label = Created
 *   forms.catalog.column.key.label = Key
 *   forms.catalog.column.public.label = Open to the public
 *   forms.catalog.column.publicLink.label = Public link
 *   forms.catalog.column.version.label = Version
 *   forms.catalog.field.form.label = Form
 *   forms.catalog.field.form.placeholder = All forms
 *   forms.catalog.nav.label = Forms
 *   forms.catalog.published.empty.emptyText = No published forms yet.
 *   forms.catalog.section.forms.title = Forms
 *   forms.catalog.section.submissions.title = Submissions
 *   forms.catalog.submissions.column.form.label = Form
 *   forms.catalog.submissions.column.nowWith.label = Now with
 *   forms.catalog.submissions.column.state.label = State
 *   forms.catalog.submissions.column.submitted.label = Submitted
 *   forms.catalog.submissions.column.version.label = Answered version
 *   forms.catalog.submissions.empty.emptyText = Nothing has been submitted yet.
 *   forms.catalog.submissions.note.value = A submission that was routed also exists as a document, so it carries the same approvals, trail and verification as anything else the organisation circulates.
 *   forms.mine.column.form.label = Form
 *   forms.mine.column.nowWith.label = Now with
 *   forms.mine.column.state.label = State
 *   forms.mine.column.submitted.label = Submitted
 *   forms.mine.empty.emptyText = You have not submitted anything yet.
 *   forms.mine.nav.label = My Submissions
 *   forms.mine.note.value = A submission cannot be edited once it is made — people may already have acted on it. If something was wrong, submit again; both submissions are kept, with their times.
 *   forms.mine.section.title = My submissions
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
            'i18nKey' => 'forms.builder.nav',
            'icon' => 'forms',
            'group' => 'records',
            'order' => 4,
            'screen' => 'blocks',
            'requiredPermission' => CorePermissions::FORMS_MANAGE,
            'blocks' => [
                [
                    'type' => 'section',
                    'title' => 'Forms',
                    'i18nKey' => 'forms.builder.section.forms',
                    'children' => [
                        [
                            'type' => 'text',
                            'value' => 'Author a form, add its fields, then publish it. '
                                . 'A published form accepts submissions; archiving one stops new '
                                . 'submissions without touching what has already been submitted.',
                            'tone' => 'muted',
                            'i18nKey' => 'forms.builder.intro',
                        ],
                        [
                            'type' => 'dataTable',
                            'source' => '/api/v1/forms',
                            'emptyText' => 'No forms yet. Create one to get started.',
                            'i18nKey' => 'forms.builder.table.empty',
                            'pageSize' => 20,
                            'columns' => [
                                [
                                    'key' => 'form_key',
                                    'label' => 'Key',
                                    'sortable' => true,
                                    'filterable' => true,
                                    'i18nKey' => 'forms.builder.column.key',
                                ],
                                ['key' => 'status', 'label' => 'Status', 'sortable' => true, 'i18nKey' => 'forms.builder.column.status'],
                                ['key' => 'version', 'label' => 'Version', 'i18nKey' => 'forms.builder.column.version'],
                                // Which forms this organisation has opened to
                                // people with no account. In the LIST rather than
                                // only on the detail pane, because "what have we
                                // exposed?" is a question asked about the whole
                                // catalogue at once, and an answer somebody has
                                // to click through twelve forms to assemble is an
                                // answer nobody assembles.
                                ['key' => 'public_enabled', 'label' => 'Public', 'sortable' => true, 'i18nKey' => 'forms.builder.column.public'],
                            ],
                            'rowActions' => [
                                // Publish and archive are POSTs with an empty
                                // body, templated with the row's own id. They are
                                // row actions rather than buttons on a detail page
                                // because publishing is the last thing an author
                                // does and making them navigate for it is how a
                                // form stays in draft by accident.
                                ['label' => 'Fill in', 'href' => '/forms/{id}', 'i18nKey' => 'forms.builder.action.fill'],
                                ['label' => 'Public link', 'href' => '/f/{public_slug}', 'i18nKey' => 'forms.builder.action.publicLink'],
                                [
                                    'label' => 'Publish',
                                    'endpoint' => '/api/v1/forms/{id}/publish',
                                    'method' => 'POST',
                                    'i18nKey' => 'forms.builder.action.publish',
                                ],
                                [
                                    'label' => 'Archive',
                                    'endpoint' => '/api/v1/forms/{id}/archive',
                                    'method' => 'POST',
                                    'confirm' => 'Archiving stops new submissions. '
                                        . 'Everything already submitted is kept. Continue?',
                                    'i18nKey' => 'forms.builder.action.archive',
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
                                    'i18nKey' => 'forms.builder.action.closeLink',
                                ],
                            ],
                        ],
                        [
                            'type' => 'modal',
                            'id' => 'newForm',
                            'title' => 'New form',
                            'trigger' => 'New form',
                            'i18nKey' => 'forms.builder.create',
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
                                            'i18nKey' => 'forms.builder.field.key',
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
                                            'i18nKey' => 'forms.builder.field.name',
                                        ],
                                        [
                                            'type' => 'textArea',
                                            'name' => 'description',
                                            'label' => 'Description',
                                            'rows' => 3,
                                            'i18nKey' => 'forms.builder.field.description',
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
                                            'i18nKey' => 'forms.builder.field.route',
                                        ],
                                        [
                                            'type' => 'submitButton',
                                            'label' => 'Create form',
                                            'variant' => 'primary',
                                            'i18nKey' => 'forms.builder.action.create',
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
                    'i18nKey' => 'forms.builder.section.fields',
                    'children' => [
                        [
                            'type' => 'selector',
                            'name' => 'builderForm',
                            'label' => 'Form',
                            'source' => '/api/v1/forms',
                            'valueField' => 'id',
                            'labelField' => 'form_key',
                            'placeholder' => 'Pick a form to edit its fields',
                            'i18nKey' => 'forms.builder.field.form',
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
                            'i18nKey' => 'forms.builder.detail.empty',
                            'fields' => [
                                ['field' => 'form_key', 'label' => 'Key', 'i18nKey' => 'forms.builder.detail.key'],
                                ['field' => 'status', 'label' => 'Status', 'i18nKey' => 'forms.builder.detail.status'],
                                ['field' => 'version', 'label' => 'Version', 'i18nKey' => 'forms.builder.detail.version'],
                                ['field' => 'description', 'label' => 'Description', 'i18nKey' => 'forms.builder.detail.description'],
                                ['field' => 'accepts_submissions', 'label' => 'Accepting submissions', 'i18nKey' => 'forms.builder.detail.accepting'],
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
                                ['field' => 'public_enabled', 'label' => 'Open to the public', 'i18nKey' => 'forms.builder.detail.public'],
                                ['field' => 'public_url', 'label' => 'Public link', 'i18nKey' => 'forms.builder.detail.publicLink'],
                                ['field' => 'public_closes_at', 'label' => 'Public link closes', 'i18nKey' => 'forms.builder.detail.publicCloses'],
                            ],
                        ],
                        [
                            'type' => 'modal',
                            'id' => 'editFormModal',
                            'title' => 'Edit this form',
                            'trigger' => 'Edit form',
                            'i18nKey' => 'forms.builder.edit',
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
                                            'i18nKey' => 'forms.builder.field.name',
                                        ],
                                        [
                                            'type' => 'textArea',
                                            'name' => 'description',
                                            'label' => 'Description',
                                            'rows' => 3,
                                            'i18nKey' => 'forms.builder.field.description',
                                        ],
                                        [
                                            'type' => 'referenceSelect',
                                            'name' => 'route_template_id',
                                            'label' => 'Route submissions through',
                                            'source' => '/api/v1/document-route-templates',
                                            'valueField' => 'id',
                                            'labelField' => 'name',
                                            'placeholder' => 'Do not route submissions',
                                            'i18nKey' => 'forms.builder.field.routeEdit',
                                        ],
                                        [
                                            'type' => 'submitButton',
                                            'label' => 'Save changes',
                                            'i18nKey' => 'forms.builder.action.save',
                                        ],
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
                            'i18nKey' => 'forms.builder.public',
                            'variant' => 'secondary',
                            'children' => [
                                [
                                    'type' => 'alert',
                                    'variant' => 'warning',
                                    'title' => 'Anyone with the link can submit',
                                    'i18nKey' => 'forms.builder.public.warning',
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
                                            'i18nKey' => 'forms.builder.field.opens',
                                        ],
                                        [
                                            'type' => 'dateInput',
                                            'name' => 'closes_at',
                                            'label' => 'Closes (optional)',
                                            'i18nKey' => 'forms.builder.field.closes',
                                        ],
                                        [
                                            'type' => 'submitButton',
                                            'label' => 'Open public link',
                                            'i18nKey' => 'forms.builder.action.openLink',
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
                            'i18nKey' => 'forms.builder.questions.intro',
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
                                    'i18nKey' => 'forms.builder.field.questions',
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
                                            'i18nKey' => 'forms.builder.field.questionKey',
                                            'required' => true,
                                        ],
                                        [
                                            'type' => 'select',
                                            'name' => 'field_type',
                                            'label' => 'Kind',
                                            'i18nKey' => 'forms.builder.field.kind',
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
                                            'i18nKey' => 'forms.builder.field.label',
                                            'required' => true,
                                            'arLabel' => 'Arabic',
                                            'enLabel' => 'English',
                                        ],
                                        [
                                            'type' => 'textArea',
                                            'name' => 'help_text',
                                            'label' => 'Help text',
                                            'i18nKey' => 'forms.builder.field.help',
                                            'rows' => 2,
                                        ],
                                        [
                                            'type' => 'checkbox',
                                            'name' => 'is_required',
                                            'label' => 'Required',
                                            'i18nKey' => 'forms.builder.field.required',
                                        ],
                                        [
                                            'type' => 'textInput',
                                            'name' => 'section_key',
                                            'label' => 'Section',
                                            'placeholder' => 'Groups questions under one heading',
                                            'i18nKey' => 'forms.builder.field.section',
                                        ],
                                        [
                                            'type' => 'select',
                                            'name' => 'prefill_source',
                                            'label' => 'Prefill from the submitter',
                                            'i18nKey' => 'forms.builder.field.prefill',
                                            'options' => self::prefillSourceOptions(),
                                        ],
                                    ],
                                ],
                                [
                                    'type' => 'submitButton',
                                    'label' => 'Save questions',
                                    'i18nKey' => 'forms.builder.action.saveQuestions',
                                    'variant' => 'primary',
                                ],
                            ],
                        ],
                        [
                            'type' => 'alert',
                            'variant' => 'info',
                            'title' => 'Prefilled fields',
                            'i18nKey' => 'forms.builder.prefill.note',
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
                            'i18nKey' => 'forms.builder.public.note',
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
            $options[] = [
                'value' => $type,
                'label' => $labels[$type] ?? $type,
                // The VALUE is the stable slug, so a new field type arrives
                // carrying its own key (#1044).
                'i18nKey' => 'forms.builder.fieldType.' . $type,
            ];
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
            // OU_ID was declared in PrefillSource and missing here, and the
            // `?? $source` fallback below meant the picker offered a literal
            // `profile.ou_id` as a choice rather than words. Same fact as
            // `OU`, but the id rather than the name — see PrefillSource.
            PrefillSource::OU_ID => 'Their unit, for a unit field',
            // OU_ID was declared in PrefillSource and missing here, and the
            // `?? $source` fallback below meant the picker offered a literal
            // `profile.ou_id` as a choice rather than words. Same fact as
            // `OU`, but the id rather than the name — see PrefillSource.
            PrefillSource::PHONE => 'Their phone',
            PrefillSource::JOB_TITLE => 'Their job title',
        ];

        $options = [[
            'value' => '',
            'label' => 'Do not prefill',
            'i18nKey' => 'forms.builder.prefill.none',
        ]];
        foreach (PrefillSource::all() as $source) {
            $label = $labels[$source] ?? $source;
            if (!PrefillSource::isBacked($source)) {
                $label .= ' — nothing in this install stores it yet';
            }
            $options[] = [
                'value' => $source,
                'label' => $label,
                // `profile.` is stripped so each key stays one segment: a
                // dot in the value would otherwise nest the key silently.
                'i18nKey' => 'forms.builder.prefill.' . str_replace('profile.', '', $source),
            ];
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
            'i18nKey' => 'forms.catalog.nav',
            'icon' => 'clipboard-list',
            'group' => 'records',
            'order' => 5,
            'screen' => 'blocks',
            'requiredPermission' => CorePermissions::FORMS_READ,
            'blocks' => [
                [
                    'type' => 'section',
                    'title' => 'Forms',
                    'i18nKey' => 'forms.catalog.section.forms',
                    'children' => [
                        [
                            'type' => 'dataTable',
                            'source' => '/api/v1/forms?status=published',
                            'emptyText' => 'No published forms yet.',
                            'i18nKey' => 'forms.catalog.published.empty',
                            'pageSize' => 20,
                            'columns' => [
                                [
                                    'key' => 'form_key',
                                    'label' => 'Key',
                                    'sortable' => true,
                                    'filterable' => true,
                                    'i18nKey' => 'forms.catalog.column.key',
                                ],
                                ['key' => 'version', 'label' => 'Version', 'i18nKey' => 'forms.catalog.column.version'],
                                ['key' => 'public_enabled', 'label' => 'Open to the public', 'i18nKey' => 'forms.catalog.column.public'],
                                ['key' => 'public_url', 'label' => 'Public link', 'i18nKey' => 'forms.catalog.column.publicLink'],
                                ['key' => 'created_at', 'label' => 'Created', 'sortable' => true, 'i18nKey' => 'forms.catalog.column.created'],
                            ],
                            // WHERE THE LINKS LIVE. A form had two addresses and
                            // neither was reachable from a screen: the public URL
                            // was printed as text on a pane you only saw after
                            // picking the form in a dropdown, and the signed-in
                            // fill page had no link at all. Somebody who wanted
                            // to send a colleague a form had to be told the URL
                            // scheme by hand.
                            //
                            // `{id}` and `{public_slug}` are substituted from the
                            // ROW. The link is the INTERNAL path, not the stored
                            // `public_url`: an href must start with '/' — the
                            // contract rejects absolute URLs, which is what stops
                            // a descriptor becoming an open redirect. It also
                            // keeps the reader on whichever host they are already
                            // browsing, rather than on whatever APP_URL happens
                            // to say. On a form nobody has opened to the public
                            // the slug is blank, and the `Open to the public`
                            // column beside it says why.
                            'rowActions' => [
                                ['label' => 'Fill in', 'href' => '/forms/{id}', 'i18nKey' => 'forms.catalog.action.fill'],
                                ['label' => 'Public link', 'href' => '/f/{public_slug}', 'i18nKey' => 'forms.catalog.action.publicLink'],
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'section',
                    'title' => 'Submissions',
                    'i18nKey' => 'forms.catalog.section.submissions',
                    'children' => [
                        [
                            'type' => 'selector',
                            'name' => 'catalogForm',
                            'label' => 'Form',
                            'source' => '/api/v1/forms',
                            'valueField' => 'id',
                            'labelField' => 'form_key',
                            'placeholder' => 'All forms',
                            'i18nKey' => 'forms.catalog.field.form',
                        ],
                        [
                            'type' => 'dataTable',
                            'source' => '/api/v1/form-submissions',
                            'emptyText' => 'Nothing has been submitted yet.',
                            'i18nKey' => 'forms.catalog.submissions.empty',
                            'pageSize' => 25,
                            'columns' => [
                                [
                                    'key' => 'form_key',
                                    'label' => 'Form',
                                    'sortable' => true,
                                    'filterable' => true,
                                    'i18nKey' => 'forms.catalog.submissions.column.form',
                                ],
                                ['key' => 'submitted_at', 'label' => 'Submitted', 'sortable' => true, 'i18nKey' => 'forms.catalog.submissions.column.submitted'],
                                // WHERE IT IS NOW. Somebody who submitted a
                                // request does not want to know that it was
                                // received; they want to know whose desk it is
                                // on. Both come from the routing trail rather
                                // than a stored status, so neither can go stale.
                                [
                                    'key' => 'state',
                                    'label' => 'State',
                                    'sortable' => true,
                                    'filterable' => true,
                                    'i18nKey' => 'forms.catalog.submissions.column.state',
                                ],
                                ['key' => 'current_step', 'label' => 'Now with', 'i18nKey' => 'forms.catalog.submissions.column.nowWith'],
                                ['key' => 'form_version', 'label' => 'Answered version', 'i18nKey' => 'forms.catalog.submissions.column.version'],
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
                            'i18nKey' => 'forms.catalog.submissions.note',
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
            'i18nKey' => 'forms.mine.nav',
            'icon' => 'file-check',
            'group' => 'overview',
            'order' => 3,
            'screen' => 'blocks',
            'requiredPermission' => CorePermissions::FORMS_SUBMIT,
            'blocks' => [
                [
                    'type' => 'section',
                    'title' => 'My submissions',
                    'i18nKey' => 'forms.mine.section',
                    'children' => [
                        [
                            'type' => 'dataTable',
                            // The `/me/…` endpoint, never the tenant-wide one with
                            // a filter. The route decides whose rows these are, so
                            // no query param a client could omit or change is
                            // standing between a person and somebody else's data.
                            'source' => '/api/v1/me/form-submissions',
                            'emptyText' => 'You have not submitted anything yet.',
                            'i18nKey' => 'forms.mine.empty',
                            'pageSize' => 25,
                            'columns' => [
                                [
                                    'key' => 'form_key',
                                    'label' => 'Form',
                                    'sortable' => true,
                                    'filterable' => true,
                                    'i18nKey' => 'forms.mine.column.form',
                                ],
                                ['key' => 'submitted_at', 'label' => 'Submitted', 'sortable' => true, 'i18nKey' => 'forms.mine.column.submitted'],
                                // WHERE IT IS NOW. Somebody who submitted a request does not
                                // want to be told it was received; they want to know whose desk
                                // it is on. Derived from the routing trail, so it cannot go stale.
                                [
                                    'key' => 'state',
                                    'label' => 'State',
                                    'sortable' => true,
                                    'filterable' => true,
                                    'i18nKey' => 'forms.mine.column.state',
                                ],
                                ['key' => 'current_step', 'label' => 'Now with', 'i18nKey' => 'forms.mine.column.nowWith'],
                            ],
                        ],
                        [
                            'type' => 'text',
                            'value' => 'A submission cannot be edited once it is made — people may '
                                . 'already have acted on it. If something was wrong, submit again; '
                                . 'both submissions are kept, with their times.',
                            'tone' => 'muted',
                            'i18nKey' => 'forms.mine.note',
                        ],
                    ],
                ],
            ],
        ];
    }
}
