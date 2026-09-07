---
"@amroksaleh/features": minor
---

The document designer gains **Save as a copy** (File menu and `/` palette):
save the open document as a new personal template, leaving the original
untouched.

This closes a hole that was a safety problem rather than a missing convenience.
`Save` updates in place whenever the editor has a loaded template, and an update
deliberately leaves the stored scope alone — so opening a **tenant-wide**
template, changing something, and pressing Save rewrote the template the whole
tenant uses, with nothing on screen saying that was what Save meant here. There
was no way to keep an experiment that did not also change everyone else's
document.

The copy creates rather than updates, is filed as **personal** whatever the
original was, and the editor then follows the copy — that last part matters,
because leaving the editor pointed at the original would put the next Ctrl+S
straight back into the bug this exists to prevent.

**`EditorCommandContext` gains a required `onSaveAsCopy`.** Anyone building the
menus themselves (`buildEditorMenus`) must supply it. That is a required field
on an exported interface, so an external consumer constructing this context
breaks at compile time; it is marked `minor` because both consumers are in this
repository and the package is pre-1.0, and it is called out here rather than
buried because the type error is the only warning anybody else would get.
