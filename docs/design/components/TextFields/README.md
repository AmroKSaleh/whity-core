# Text fields

`Input` and `Textarea` share one look (7.2): outlined, a 1px #929292 border on white, 36px tall (textarea grows), 9px corners. States: helper text under the field, an error that replaces the helper in the error text colour, a required mark, read-only (grey ground, still selectable and copyable) and disabled (faded, says why in the helper). `Textarea` counts characters when it has `maxLength`; the counter turns amber when full.
