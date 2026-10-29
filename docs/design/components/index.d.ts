import type * as React from 'react';
type IconName = string; // one of window.Whity.iconNames (116 curated Tabler outline icons)
type Tone = 'auto' | 'onbrand' | 'mono';
export declare function Mark(props: { size?: number; cut?: 'main' | 'small'; tone?: Tone; label?: string; className?: string }): React.ReactElement;
export declare function Lockup(props: { height?: number; layout?: 'horizontal' | 'stacked'; tone?: Tone; className?: string }): React.ReactElement;
export declare function BuiltOnWhity(props: { href?: string; label?: string; className?: string }): React.ReactElement;
export declare function Icon(props: { name: IconName; size?: number; stroke?: number; label?: string; mirror?: boolean; className?: string }): React.ReactElement;
export declare function Blueprint(props: { label?: string; title?: React.ReactNode; children?: React.ReactNode; className?: string }): React.ReactElement;
export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> { variant?: 'default' | 'outline' | 'secondary' | 'ghost' | 'destructive' | 'link'; size?: 'sm' | 'default' | 'lg'; icon?: IconName; iconEnd?: IconName; loading?: boolean }
export declare function Button(props: ButtonProps): React.ReactElement;
export declare function IconButton(props: React.ButtonHTMLAttributes<HTMLButtonElement> & { icon: IconName; label: string; variant?: 'ghost' | 'outline' | 'secondary'; size?: 'sm' | 'default'; tooltip?: boolean | 'bottom'; shortcut?: string }): React.ReactElement;
export declare function Badge(props: { variant?: 'default' | 'secondary' | 'outline' | 'success' | 'info' | 'warning' | 'destructive'; icon?: IconName; children?: React.ReactNode; className?: string }): React.ReactElement;
/** Decision 2.8: one tenant colour in (hex or oklch()), the derived CSS variables out: label white or near-black by 4.5:1, tint, colour on dark, plane. */
export declare function tenantTheme(color: string, mode?: 'light' | 'dark'): Record<string, string>;
/** WCAG contrast ratio of two hex colours. */
export declare function contrast(a: string, b: string): number;
/** JOD defaults to 3 decimals, other currencies to 2. */
export declare function Money(props: { amount: number; currency?: string; decimals?: number; className?: string }): React.ReactElement;
export declare function Skeleton(props: { width?: number | string; height?: number | string; className?: string }): React.ReactElement;
export declare function Card(props: React.HTMLAttributes<HTMLElement> & { title?: React.ReactNode; description?: React.ReactNode; action?: React.ReactNode; footer?: React.ReactNode; variant?: 'default' | 'outline'; interactive?: boolean; selected?: boolean }): React.ReactElement;
export declare function Dialog(props: { title: React.ReactNode; description?: React.ReactNode; children?: React.ReactNode; footer?: React.ReactNode; onClose?: () => void; inline?: boolean }): React.ReactElement;
export declare function EmptyState(props: { icon?: IconName; title: React.ReactNode; description?: React.ReactNode; action?: React.ReactNode; className?: string }): React.ReactElement;
interface FieldProps { label?: React.ReactNode; helperText?: React.ReactNode; errorText?: React.ReactNode; required?: boolean; className?: string }
export declare function Input(props: React.InputHTMLAttributes<HTMLInputElement> & FieldProps): React.ReactElement;
export declare function Select(props: React.SelectHTMLAttributes<HTMLSelectElement> & FieldProps & { options: { value: string; label: string }[] }): React.ReactElement;
export declare function Switch(props: { label?: React.ReactNode; checked?: boolean; defaultChecked?: boolean; onChange?: (on: boolean) => void }): React.ReactElement;
export declare function Checkbox(props: React.InputHTMLAttributes<HTMLInputElement> & { label?: React.ReactNode; indeterminate?: boolean }): React.ReactElement;
export declare function Tabs(props: { items: { value: string; label: React.ReactNode; count?: number }[]; defaultValue?: string; onChange?: (v: string) => void }): React.ReactElement;
export declare function Breadcrumb(props: { items: { label: React.ReactNode; href?: string }[] }): React.ReactElement;
export declare function Pagination(props: { from?: number; to?: number; total?: number; label?: string }): React.ReactElement;
export declare function PageHeader(props: { title: React.ReactNode; description?: React.ReactNode; breadcrumb?: { label: React.ReactNode; href?: string }[]; actions?: React.ReactNode }): React.ReactElement;
export interface NavItem { icon: IconName; label: string; active?: boolean; count?: number }
export declare function AppShell(props: { nav: { label?: string; items: NavItem[] }[]; brand?: React.ReactNode; tenant?: string; user?: string; role?: string; topActions?: React.ReactNode; searchLabel?: string; builtOn?: boolean; dir?: 'ltr' | 'rtl'; children?: React.ReactNode }): React.ReactElement;
export declare function DataTable<R = Record<string, unknown>>(props: { columns: { key: string; label: React.ReactNode; align?: 'start' | 'end'; width?: number | string; render?: (row: R) => React.ReactNode; sortable?: boolean; sortValue?: (row: R) => string | number }[]; rows: R[]; footer?: React.ReactNode; label?: string; selectable?: boolean; defaultSelected?: (string | number)[]; onSelect?: (ids: (string | number)[]) => void; bulkActions?: React.ReactNode | ((rows: R[]) => React.ReactNode); selectionSum?: (rows: R[]) => React.ReactNode; defaultSort?: { key: string; dir: 'asc' | 'desc' }; rowMenu?: boolean | ((row: R) => React.ReactNode); onRowClick?: (row: R) => void; totals?: Record<string, React.ReactNode>; totalsLabel?: React.ReactNode; state?: 'ready' | 'loading' | 'error'; errorText?: React.ReactNode; onRetry?: () => void; empty?: React.ReactNode; sticky?: boolean; maxHeight?: number | string; labels?: Record<string, string>; className?: string }): React.ReactElement;
export declare function PluginCard(props: { icon?: IconName; name: string; by?: string; description?: string; state?: 'discovered' | 'loaded' | 'active' | 'failed' | 'disabled' | 'available'; stateLabel?: string; price?: string }): React.ReactElement;
export declare function Alert(props: { variant?: 'default' | 'info' | 'success' | 'warning' | 'destructive'; title?: React.ReactNode; action?: React.ReactNode; children?: React.ReactNode; className?: string }): React.ReactElement;
export declare function ActorChip(props: { kind?: 'person' | 'agent' | 'system'; name: string; onBehalfOf?: string; forLabel?: string; className?: string }): React.ReactElement;
export interface ProposalChange { label: React.ReactNode; before?: React.ReactNode; after: React.ReactNode }
export declare function ActionProposal(props: { agent: string; onBehalfOf?: string; title: string; summary?: React.ReactNode; changes?: ProposalChange[]; scope?: string[]; reversible?: boolean; undoWindow?: string; status?: 'proposed' | 'approved' | 'rejected' | 'undone' | 'expired'; undoneBy?: string; onAskAgain?: () => void; decidedAt?: string; onApprove?: () => void; onReject?: () => void; onEdit?: () => void; onUndo?: () => void; labels?: Record<string, string>; dir?: 'ltr' | 'rtl'; className?: string }): React.ReactElement;
export declare function AgentActivity(props: { items: { kind: 'read' | 'propose' | 'approve' | 'act' | 'undo' | 'error'; actor: string; actorKind?: 'person' | 'agent'; text: React.ReactNode; time: string; tool?: string }[]; label?: string; className?: string }): React.ReactElement;
export declare function ScopeGrant(props: { agent: string; onBehalfOf?: string; grants: { key: string; label: string; actions: { key: string; label: string; mode?: 'off' | 'ask' | 'allow' }[] }[]; onChange?: (permission: string, mode: 'off' | 'ask' | 'allow') => void; labels?: Record<string, string>; className?: string }): React.ReactElement;
/* Documents (Whity Render) */
export type DocLang = 'en' | 'ar';
export type Bilingual = { en: string; ar?: string };
export type LabelLike = string | Bilingual;
export type Localised<T> = T | { en: T; ar?: T };
export declare const docLabels: Record<string, Bilingual>;
export declare function amountInWords(amount: number, lang: DocLang): string;
export declare function DocLabel(props: { l: LabelLike; lead?: DocLang; single?: boolean; vars?: Record<string, string | number>; className?: string }): React.ReactElement;
export interface DocColumn { key: string; label: LabelLike; type?: 'money'; align?: 'start' | 'end'; mono?: boolean; width?: string }
export declare function Doc(props: {
  lead?: DocLang; size?: 'A4' | 'A5' | 'Letter'; title: LabelLike; number: string; date?: Localised<string>;
  tenant: { name: Localised<string>; initial?: string; logo?: React.ReactNode; lines?: Localised<string[]> };
  parties?: { label: LabelLike; value: Localised<string>; lines?: Localised<string[]>; mono?: boolean }[];
  columns?: DocColumn[]; rows?: Record<string, unknown>[]; carry?: string; rowsFirst?: number; rowsNext?: number; tailRows?: number;
  totals?: { label: LabelLike; value: number }[]; grand?: { label?: LabelLike; value: number }; words?: boolean;
  stamp?: boolean | LabelLike; reference?: string; qr?: { src: string; label?: LabelLike }; footNote?: Localised<string>;
  intro?: React.ReactNode; aside?: React.ReactNode; children?: React.ReactNode; className?: string }): React.ReactElement;
export declare function DocNote(props: { lead?: DocLang; label?: LabelLike; children?: React.ReactNode }): React.ReactElement;
export declare function DocSign(props: { lead?: DocLang; title?: LabelLike; fields: (LabelLike | { l: LabelLike; value?: string })[] }): React.ReactElement;
export declare function DocAmount(props: { lead?: DocLang; value: number; currency?: string; label?: LabelLike }): React.ReactElement;
export declare function DocTable(props: { lead?: DocLang; title?: LabelLike; columns: (DocColumn | (Omit<DocColumn, 'type'> & { type?: 'money' | 'percent' }))[]; rows: Record<string, unknown>[]; compact?: boolean; before?: React.ReactNode }): React.ReactElement;
export declare function DocPages(props: { lead?: DocLang; size?: 'A4' | 'A5' | 'Letter'; title?: LabelLike; number?: string; date?: Localised<string>; tenant: { name: Localised<string>; initial?: string; logo?: React.ReactNode; lines?: Localised<string[]> }; parties?: { label: LabelLike; value: Localised<string>; lines?: Localised<string[]>; mono?: boolean }[]; pages?: React.ReactNode[]; children?: React.ReactNode; stamp?: boolean | LabelLike; reference?: string; qr?: { src: string; label?: LabelLike }; footNote?: Localised<string> }): React.ReactElement;
export declare function DocKpis(props: { lead?: DocLang; items: { label: LabelLike; value: string; note?: Localised<string> }[] }): React.ReactElement;
export declare function DocBars(props: { lead?: DocLang; title?: LabelLike; alt?: Localised<string>; data: { label: Localised<string>; value: number; display?: string }[]; highlight?: number; ticks?: number[]; tickFormat?: (t: number) => string; height?: number }): React.ReactElement;
/* Stage 8 components */
export declare function TenantLogo(props: { name: string; src?: string; color?: string; fg?: string; initials?: string; size?: number; showName?: boolean; className?: string }): React.ReactElement;
export declare function Tooltip(props: { label: React.ReactNode; shortcut?: string; side?: 'top' | 'bottom'; open?: boolean; decorative?: boolean; children: React.ReactElement }): React.ReactElement;
export declare function Textarea(props: React.TextareaHTMLAttributes<HTMLTextAreaElement> & FieldProps & { counter?: boolean }): React.ReactElement;
export interface Option { value: string; label: string; hint?: string; icon?: IconName; disabled?: boolean }
export declare function Combobox(props: FieldProps & { options: Option[]; defaultValue?: string; defaultOpen?: boolean; defaultQuery?: string; loading?: boolean; footer?: React.ReactNode; onChange?: (v: string) => void; onQuery?: (q: string) => void; labels?: Record<string, string> }): React.ReactElement;
export declare function RadioGroup(props: { label?: React.ReactNode; options: Option[]; defaultValue?: string; value?: string; onChange?: (v: string) => void; orientation?: 'vertical' | 'horizontal'; variant?: 'list' | 'cards'; errorText?: React.ReactNode; className?: string }): React.ReactElement;
export declare function DatePicker(props: FieldProps & { lang?: 'en' | 'ar'; range?: boolean; defaultValue?: string | [string, string]; today?: string; weekStart?: number; weekend?: number[]; presets?: boolean; defaultOpen?: boolean; onChange?: (v: Date | [Date, Date | null]) => void; labels?: Record<string, string> }): React.ReactElement;
export declare function MoneyInput(props: FieldProps & { currency?: string; decimals?: number; defaultValue?: number | string; showWords?: boolean; wordsLang?: 'en' | 'ar'; readOnly?: boolean; disabled?: boolean; onChange?: (v: number | null) => void; labels?: Record<string, string> }): React.ReactElement;
export declare function OtpInput(props: { label?: React.ReactNode; length?: number; defaultValue?: string; helperText?: React.ReactNode; errorText?: React.ReactNode; disabled?: boolean; onComplete?: (code: string) => void; labels?: Record<string, string>; className?: string }): React.ReactElement;
export declare function SearchField(props: { placeholder?: string; defaultValue?: string; shortcut?: string | false; onChange?: (v: string) => void; onSearch?: (v: string) => void; labels?: Record<string, string>; className?: string }): React.ReactElement;
export declare function TenantSwitcher(props: { tenants: { name: string; role?: string; plan?: string; logo?: string; color?: string; fg?: string }[]; current?: string; defaultOpen?: boolean; onChange?: (name: string) => void; onCreate?: (() => void) | false; labels?: Record<string, string>; className?: string }): React.ReactElement;
export declare function Segmented(props: { label: string; options: { value: string; label: string; icon?: IconName; iconOnly?: boolean; lang?: string }[]; defaultValue?: string; value?: string; onChange?: (v: string) => void; className?: string }): React.ReactElement;
export declare function LanguageSwitch(props: { value?: 'en' | 'ar'; onChange?: (v: string) => void; label?: string; className?: string }): React.ReactElement;
export declare function ThemeSwitch(props: { value?: 'light' | 'dark' | 'system'; compact?: boolean; onChange?: (v: string) => void; labels?: Record<string, string>; className?: string }): React.ReactElement;
export declare function Avatar(props: { name: string; kind?: 'person' | 'agent' | 'system'; src?: string; size?: number; status?: 'online' | 'busy' | 'offline'; className?: string }): React.ReactElement;
export declare function DescriptionList(props: { items: { label: React.ReactNode; value: React.ReactNode; mono?: boolean; copy?: boolean | string }[]; columns?: 1 | 2; none?: string; labels?: Record<string, string>; className?: string }): React.ReactElement;
export interface UndoBarProps { text?: React.ReactNode; state?: 'open' | 'undone' | 'expired'; remainingLabel?: string; remaining?: number; total?: number; undoneBy?: string; onUndo?: () => void; labels?: Record<string, string>; className?: string }
export declare function UndoBar(props: UndoBarProps): React.ReactElement;
export declare function Timeline(props: { items: ({ day: string } | { kind?: 'create' | 'edit' | 'send' | 'approve' | 'comment' | 'pay' | 'undo' | 'error' | 'document' | 'read' | 'propose'; icon?: IconName; actor: string; actorKind?: 'person' | 'agent' | 'system'; onBehalfOf?: string; text: React.ReactNode; time: string; changes?: { label: React.ReactNode; before?: React.ReactNode; after: React.ReactNode }[]; note?: React.ReactNode; undo?: UndoBarProps; pending?: boolean })[]; label?: string; forLabel?: string; className?: string }): React.ReactElement;
export declare function Toast(props: { title: React.ReactNode; description?: React.ReactNode; variant?: 'default' | 'success' | 'error' | 'warning' | 'info'; action?: { label: string; icon?: IconName; onClick?: () => void }; duration?: number; onClose?: () => void; closeLabel?: string; className?: string }): React.ReactElement;
export declare function ToastRegion(props: { inline?: boolean; children?: React.ReactNode; className?: string }): React.ReactElement;
export declare function ConfirmDialog(props: { title: React.ReactNode; description?: React.ReactNode; confirmText?: string; confirmLabel?: string; icon?: IconName; destructive?: boolean; inline?: boolean; onConfirm?: () => void; onCancel?: () => void; labels?: Record<string, string>; children?: React.ReactNode }): React.ReactElement;
export declare function Drawer(props: { title: React.ReactNode; eyebrow?: React.ReactNode; subtitle?: React.ReactNode; actions?: React.ReactNode; tabs?: React.ReactNode; footer?: React.ReactNode; width?: number | string; inline?: boolean; behind?: React.ReactNode; dir?: 'ltr' | 'rtl'; onClose?: () => void; labels?: Record<string, string>; children?: React.ReactNode; className?: string }): React.ReactElement;
export declare function Menu(props: { items: ({ label: React.ReactNode; icon?: IconName; hint?: React.ReactNode; shortcut?: string; danger?: boolean; disabled?: boolean; onClick?: () => void } | { divider: true } | { heading: React.ReactNode })[]; trigger?: React.ReactElement; label?: string; align?: 'start' | 'end'; defaultOpen?: boolean; className?: string }): React.ReactElement;
export declare function Popover(props: { trigger: React.ReactElement; title?: React.ReactNode; label?: string; width?: number | string; align?: 'start' | 'end'; defaultOpen?: boolean; children?: React.ReactNode; className?: string }): React.ReactElement;
export declare function ApprovalQueue(props: { items: Parameters<typeof ActionProposal>[0][]; onDecide?: (index: number, decision: 'approved' | 'declined') => void; label?: string; labels?: Record<string, string>; className?: string }): React.ReactElement;
export declare function DocPreview(props: { title: React.ReactNode; meta?: React.ReactNode; status?: React.ReactNode; icon?: IconName; zoom?: number; height?: number | string; onPrint?: () => void; onDownload?: () => void; onSend?: (() => void) | false; labels?: Record<string, string>; children?: React.ReactNode; className?: string }): React.ReactElement;
export declare function westernDigits(s: string): string;
export declare function formatDate(d: Date, lang?: 'en' | 'ar'): string;

/* ---------- 2.1: P2 components ---------- */
type MenuItems = Parameters<typeof Menu>[0]['items'];
export declare function ButtonGroup(props: { label?: string; size?: 'sm' | 'default'; className?: string; children?: React.ReactNode }): React.ReactElement;
/** `variant: 'default'` only for the view's main action: one plane under the pair (7.1). */
export declare function SplitButton(props: { variant?: 'outline' | 'default'; size?: 'sm' | 'default' | 'lg'; icon?: IconName; items: MenuItems; onClick?: () => void; label?: string; align?: 'start' | 'end'; defaultOpen?: boolean; disabled?: boolean; labels?: { more?: string }; className?: string; children?: React.ReactNode }): React.ReactElement;
export interface ToggleOption { value: string; label: string; icon?: IconName; iconOnly?: boolean; shortcut?: string; disabled?: boolean }
export declare function ToggleGroup(props: { options: ToggleOption[]; type?: 'single' | 'multiple'; defaultValue?: string | string[] | null; value?: string | string[] | null; required?: boolean; size?: 'sm' | 'default'; label?: string; onChange?: (value: string | string[] | null) => void; className?: string }): React.ReactElement;
export interface PhoneCountry { code: string; dial: string; name: string; ar: string; len: number[]; mobile: RegExp; group: number[] | ((digits: string) => number[]); sample: string }
export declare const phoneCountries: PhoneCountry[];
/** Jordan (+962) by default; `onChange` gives E.164 when the field is left. */
export declare function PhoneInput(props: { label?: React.ReactNode; defaultCountry?: string; defaultValue?: string; mobileOnly?: boolean; lang?: 'en' | 'ar'; helperText?: React.ReactNode; errorText?: React.ReactNode; disabled?: boolean; readOnly?: boolean; defaultTouched?: boolean; labels?: Partial<Record<'country' | 'short' | 'long' | 'mobile', string>>; onChange?: (e164: string | null) => void; className?: string }): React.ReactElement;
export interface UploadFile { name: string; size?: number; status?: 'uploading' | 'done' | 'error'; progress?: number; error?: string }
export declare function FileUpload(props: { label?: React.ReactNode; accept?: string; multiple?: boolean; typesLabel?: string; maxSizeLabel?: string; files?: UploadFile[]; onFiles?: (files: File[]) => void; onRemove?: (f: UploadFile) => void; onRetry?: (f: UploadFile) => void; disabled?: boolean; labels?: Record<string, string>; className?: string }): React.ReactElement;
export interface CommandItem { label: string; icon?: IconName; hint?: string; shortcut?: string; keywords?: string; agent?: boolean; onSelect?: (query: string) => void }
/** ⌘K. A group with `always` stays visible; an `agent` item's label takes {q} (decision 2.1c). */
export declare function CommandPalette(props: { groups: { heading: string; items: CommandItem[]; always?: boolean }[]; defaultQuery?: string; onClose?: () => void; inline?: boolean; dir?: 'ltr' | 'rtl'; labels?: Record<string, string> }): React.ReactElement;
export declare function Stepper(props: { steps: { label: string; hint?: string }[]; current: number; orientation?: 'horizontal' | 'vertical'; label?: string; labels?: { step?: string; done?: string }; className?: string }): React.ReactElement;
export declare function AgentRunStatus(props: { status: 'running' | 'waiting' | 'done' | 'failed'; agent: string; onBehalfOf?: string; title: React.ReactNode; progress?: { done: number; total: number }; detail?: React.ReactNode; next?: React.ReactNode; time?: React.ReactNode; compact?: boolean; onStop?: () => void; onReview?: () => void; onRetry?: () => void; actions?: React.ReactNode; labels?: Record<string, string>; className?: string }): React.ReactElement;
/** Decision 2.1d: greyed feature behind a card. Plan names and prices stay placeholders. */
export declare function PlanGate(props: { feature: React.ReactNode; plan?: string; description?: React.ReactNode; includes?: React.ReactNode[]; price?: React.ReactNode; canUpgrade?: boolean; variant?: 'overlay' | 'inline'; onUpgrade?: () => void; onCompare?: () => void; onAsk?: () => void; labels?: Record<string, string>; className?: string; children?: React.ReactNode }): React.ReactElement;
export interface ChartSeries { key: string; label: string }
type ChartRow = { label: string } & Record<string, number | string>;
interface ChartBase { data: ChartRow[]; series?: ChartSeries[]; format?: (v: number) => string; title?: string; subtitle?: React.ReactNode; height?: number; width?: number; valueLabel?: string; categoryLabel?: string; labels?: { table?: string; chart?: string } }
/** Palette 2.7 in fixed order (series 1 = the tenant's colour); one value labelled; tooltip and table view on every chart. */
export declare function BarChart(props: ChartBase & { highlight?: number; label?: boolean }): React.ReactElement;
export declare function LineChart(props: ChartBase & { area?: boolean; endLabels?: boolean }): React.ReactElement;
export declare function DonutChart(props: { data: { label: string; value: number }[]; format?: (v: number) => string; title?: string; subtitle?: React.ReactNode; totalLabel?: string; size?: number; sort?: boolean; valueLabel?: string; categoryLabel?: string; labels?: { other?: string; total?: string; table?: string; chart?: string } }): React.ReactElement;
