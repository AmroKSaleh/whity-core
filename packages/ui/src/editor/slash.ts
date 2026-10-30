import { Extension, type Range } from "@tiptap/core"
import { PluginKey } from "@tiptap/pm/state"
import Suggestion, { exitSuggestion, type SuggestionKeyDownProps } from "@tiptap/suggestion"

/** What the / menu shows; the React side (slash-menu.tsx) renders it. */
export interface SlashState {
  open: boolean
  query: string
  range: Range | null
  rect: DOMRect | null
}

export interface SlashOptions {
  onState: (state: SlashState) => void
  /** Returns true when the menu handled the key (arrows, Enter, Esc). */
  onKeyDown: (event: KeyboardEvent) => boolean
}

export const slashKey = new PluginKey("slash")

/**
 * Typing / at the start of a line, or after a space, opens every block
 * (EditorInsert). Esc closes it and keeps what was typed.
 */
export const SlashCommand = Extension.create<SlashOptions>({
  name: "slashCommand",
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
        pluginKey: slashKey,
        char: "/",
        allowSpaces: false,
        startOfLine: false,
        allowedPrefixes: [" "],
        // Not inside code, where / is just a character.
        allow: ({ state, range }) => state.doc.resolve(range.from).parent.type.name !== "codeBlock",
        items: () => [],
        render: () => ({
          onStart: (props) => publish(true, props),
          onUpdate: (props) => publish(true, props),
          onExit: () => publish(false),
          onKeyDown: ({ event }: SuggestionKeyDownProps) => {
            if (event.key === "Escape") {
              exitSuggestion(this.editor.view, slashKey)
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
