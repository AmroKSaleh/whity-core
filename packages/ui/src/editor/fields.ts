import { Extension, type Range } from "@tiptap/core"
import { PluginKey } from "@tiptap/pm/state"
import Suggestion, { exitSuggestion, type SuggestionKeyDownProps } from "@tiptap/suggestion"

/** One merge field a template can use (E.26), with a sample value for the picker. */
export interface FieldDef {
  /** "customer.name" */
  path: string
  /** "Customer name" */
  label: string
  /** "Example Clinic": what the picker shows beside it. */
  sample?: string
  /** E.27: required fields block issuing; fiscal fields are always required. */
  required?: boolean
}

/** Fields grouped by the record they come from: Customer, Invoice, Company, Today (contract DocEditorProps.fields). */
export interface FieldGroup {
  /** The group's name as shown: "Customer". */
  group: string
  items: FieldDef[]
}

/** What the {{ picker shows; the React side (field-picker.tsx) renders it. */
export interface FieldTriggerState {
  open: boolean
  query: string
  range: Range | null
  rect: DOMRect | null
}

export interface FieldTriggerOptions {
  onState: (state: FieldTriggerState) => void
  /** Returns true when the picker handled the key (arrows, Enter). */
  onKeyDown: (event: KeyboardEvent) => boolean
}

export const fieldTriggerKey = new PluginKey("fieldTrigger")

/** The groups narrowed to fields whose name or path matches what was typed. */
export function filterFields(groups: FieldGroup[], query: string): FieldGroup[] {
  const q = query.trim().toLowerCase()
  if (!q) return groups.filter((g) => g.items.length > 0)
  return groups
    .map((g) => ({
      ...g,
      items: g.items.filter((f) => f.label.toLowerCase().includes(q) || f.path.toLowerCase().includes(q) || g.group.toLowerCase().includes(q)),
    }))
    .filter((g) => g.items.length > 0)
}

/** Every path a record type offers, for the publish check (E.37). */
export function fieldPaths(groups: FieldGroup[]): Set<string> {
  return new Set(groups.flatMap((g) => g.items.map((f) => f.path)))
}

/**
 * Typing {{ opens the field picker (EditorInline). Spaces are allowed so a
 * field can be found by its plain name; Esc closes it and keeps what was typed.
 */
export const FieldTrigger = Extension.create<FieldTriggerOptions>({
  name: "fieldTrigger",
  addOptions: () => ({ onState: () => {}, onKeyDown: () => false }),
  addProseMirrorPlugins() {
    const { onState, onKeyDown } = this.options
    const publish = (open: boolean, props?: { query: string; range: Range; clientRect?: (() => DOMRect | null) | null }) =>
      onState({
        open,
        query: props?.query ?? "",
        range: props?.range ?? null,
        rect: props?.clientRect?.() ?? null,
      })
    return [
      Suggestion({
        editor: this.editor,
        pluginKey: fieldTriggerKey,
        char: "{{",
        allowSpaces: true,
        startOfLine: false,
        allowedPrefixes: null,
        // Not inside code, where {{ is just text.
        allow: ({ state, range }) => state.doc.resolve(range.from).parent.type.name !== "codeBlock",
        items: () => [],
        render: () => ({
          onStart: (props) => publish(true, props),
          onUpdate: (props) => publish(true, props),
          onExit: () => publish(false),
          onKeyDown: ({ event }: SuggestionKeyDownProps) => {
            if (event.key === "Escape") {
              exitSuggestion(this.editor.view, fieldTriggerKey)
              publish(false)
              return true
            }
            return onKeyDown(event)
          },
        }),
      }),
    ]
  },
})
