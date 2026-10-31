# Manifest expressions

The language plugin manifests use for rules, computed fields, conditions and visibility (SDK 1.48, WHIT-759). It's part of Whity Builder's core runtime (`docs/design/WHITY-BUILDER.md` section 2.4), and any plugin can use it.

- PHP: `Whity\Sdk\Expression\Expression` (`validate`, `evaluate`), `ExpressionContext`, `ExpressionError`, `ExpressionDescriber`.
- TypeScript: `@whity-org/features/expressions` (`validateExpression`, `evaluateExpression`, `ExpressionError`).
- Shared fixtures: [`sdk/resources/expression-fixtures.json`](../../sdk/resources/expression-fixtures.json). Both evaluators must pass every case, so a rule checked in a form gives the same answer when the server checks it on save. To change the language, change the fixtures first.

## Shape

An expression is JSON. A literal is `null`, `true`/`false`, a finite number, or text of at most 1,000 characters. An operation is an object with exactly one key, the operator, whose value is the list of arguments:

```json
{ ">=": [{ "field": "deposit" }, { "*": [{ "field": "price" }, 0.3] }] }
```

"Deposit is at least 30% of price." A single argument may skip the list: `{ "field": "price" }`. A bare JSON array isn't an expression; a literal list is `{ "list": [...] }`.

No string is ever parsed or evaluated as code.

## Operators

| Group | Operators |
| --- | --- |
| References | `field` (a field of the record), `item` (inside `any`, `all`, `count`, `sum`: the current item, or one of its keys), `state`, `today`, `now`, `has_role` |
| Logic | `and`, `or`, `not`, `if` (condition, value pairs, then an otherwise value) |
| Comparison | `==`, `!=`, `<`, `<=`, `>`, `>=` |
| Arithmetic | `+`, `-`, `*`, `/`, `min`, `max`, `round` (with 0 to 6 digits), `abs` |
| Text | `concat`, `contains`, `starts_with`, `ends_with`, `length`, `lower`, `upper` |
| Dates | `add_days`, `days_between` (dates are `YYYY-MM-DD`) |
| Lists | `list`, `in`, `any`, `all`, `count`, `sum`, `is_empty`, `coalesce` |

## Rules both evaluators follow

- A missing field reads as `null`. Arithmetic and text with a `null` argument give `null`. `<`, `<=`, `>` and `>=` with a `null` side give `false`.
- Conditions take `true` or `false`; `null` counts as false, and anything else is a type error.
- `==` compares deeply with no conversion: `1 == 1.0` is true and `1 == "1"` is false. Ordering works on two numbers or two texts; dates order correctly as text.
- Division by zero gives `null`. `round` rounds half away from zero.
- Money is an integer of minor units (JOD has 3 decimals, so 12.500 JOD is `12500`). After multiplying by a fraction, `round` brings it back to whole units.
- Lengths count characters, so Arabic text is measured correctly.
- A type mismatch throws `ExpressionError` with kind `type`.

## Limits

An expression can read only what its context holds: the record's fields and state, the acting person's roles, and the date and time. It has no loops, recursion, user-defined functions, or references to other records, settings or the network.

| Limit | Value | When |
| --- | --- | --- |
| Nodes | 256 | validation |
| Depth | 24 | validation |
| Text literal | 1,000 characters | validation |
| Evaluation steps | 10,000 | while evaluating; throws kind `budget` |

A manifest is validated when it is published, so a rule over a size limit is refused then, not when someone saves a record.

## In words

`ExpressionDescriber::describe($expression, 'en' | 'ar', $labels)` renders an expression for the person approving it, using field labels from the manifest:

- English: `Deposit is at least (Price × 0.3)`
- Arabic: `العربون لا يقل عن (السعر × 0.3)`

Arithmetic keeps its symbols in both languages, and numbers stay in Western digits.

## Not `visibleWhen`

Blocks keep their own `visibleWhen`, a deliberately small, fail-open vocabulary for showing UI. Expressions are for record logic, and they fail closed: a type error refuses, it doesn't hide. The two answer different questions, and neither replaces the other.
