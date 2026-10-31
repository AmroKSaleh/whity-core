/**
 * The manifest expression language, in TypeScript (WHIT-759).
 *
 * The twin of `Whity\Sdk\Expression\Expression` in the PHP SDK. Forms run it as
 * someone types, so a rule like "deposit is at least 30% of price" is checked
 * before saving with the same answer the server gives when it checks it again.
 * Both evaluators are held to one fixture file,
 * `sdk/resources/expression-fixtures.json`; change the language there first.
 *
 * The grammar and semantics are documented on the PHP class. In short: a JSON
 * tree, one operator per object, a closed operator set, no loops, and three
 * caps (nodes, depth, evaluation steps).
 */

export type ExpressionValue =
  | null
  | boolean
  | number
  | string
  | ExpressionValue[]
  | { [key: string]: ExpressionValue }

export interface ExpressionContext {
  /** Field name to value. Money in minor units; dates as `YYYY-MM-DD`. */
  fields?: Record<string, ExpressionValue>
  state?: string | null
  roles?: string[]
  /** `YYYY-MM-DD` in the workspace's time zone. */
  today?: string
  /** ISO 8601 in UTC. */
  now?: string
}

export type ExpressionErrorKind = 'type' | 'budget'

export class ExpressionError extends Error {
  readonly kind: ExpressionErrorKind

  constructor(kind: ExpressionErrorKind, message: string) {
    super(message)
    this.name = 'ExpressionError'
    this.kind = kind
  }
}

export const MAX_NODES = 256
export const MAX_DEPTH = 24
export const MAX_STRING = 1000
export const MAX_STEPS = 10_000

/** Operator to [min args, max args or -1 for unbounded]. */
export const OPERATORS: Readonly<Record<string, readonly [number, number]>> = {
  field: [1, 1], item: [0, 1], state: [0, 0], today: [0, 0], now: [0, 0], has_role: [1, 1],
  and: [1, -1], or: [1, -1], not: [1, 1], if: [3, -1],
  '==': [2, 2], '!=': [2, 2], '<': [2, 2], '<=': [2, 2], '>': [2, 2], '>=': [2, 2],
  '+': [1, -1], '-': [1, 2], '*': [1, -1], '/': [2, 2], min: [1, -1], max: [1, -1], round: [1, 2], abs: [1, 1],
  concat: [1, -1], contains: [2, 2], starts_with: [2, 2], ends_with: [2, 2], length: [1, 1], lower: [1, 1], upper: [1, 1],
  add_days: [2, 2], days_between: [2, 2],
  list: [0, -1], in: [2, 2], any: [2, 2], all: [2, 2], count: [1, 2], sum: [2, 2], is_empty: [1, 1], coalesce: [1, -1],
}

const ITEM_SCOPES = new Set(['any', 'all', 'count', 'sum'])
const NAME_PATTERN = /^[a-z][a-z0-9_]{0,63}$/
const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/
const DAY_MS = 86_400_000

type Node = { [op: string]: unknown }

function isOperation(value: unknown): value is Node {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function argsOf(raw: unknown): unknown[] {
  return Array.isArray(raw) ? raw : [raw]
}

function isWhole(value: unknown): value is number {
  return typeof value === 'number' && Number.isInteger(value)
}

/** Characters, not UTF-16 units, so Arabic and emoji count as PHP counts them. */
function charLength(text: string): number {
  return [...text].length
}

/**
 * Check an expression without evaluating it: shape, operators, arity,
 * reference names, where `item` may appear, and the size caps.
 */
export function validateExpression(expression: unknown): { ok: boolean; errors: string[] } {
  const errors: string[] = []
  const counter = { nodes: 0 }
  validateNode(expression, '$', 1, 0, counter, errors)
  if (counter.nodes > MAX_NODES) {
    errors.push(`$: more than ${MAX_NODES} nodes`)
  }
  return { ok: errors.length === 0, errors }
}

function validateNode(
  node: unknown,
  path: string,
  depth: number,
  itemScopes: number,
  counter: { nodes: number },
  errors: string[],
): void {
  counter.nodes++
  if (depth > MAX_DEPTH) {
    errors.push(`${path}: nested deeper than ${MAX_DEPTH}`)
    return
  }
  if (node === null || typeof node === 'boolean') return
  if (typeof node === 'number') {
    if (!Number.isFinite(node)) errors.push(`${path}: numbers must be finite`)
    return
  }
  if (typeof node === 'string') {
    if (charLength(node) > MAX_STRING) errors.push(`${path}: text longer than ${MAX_STRING} characters`)
    return
  }
  if (!isOperation(node)) {
    errors.push(`${path}: a bare list isn't an expression; write {"list": [...]}`)
    return
  }
  const keys = Object.keys(node)
  if (keys.length !== 1) {
    errors.push(`${path}: an operation has exactly one key, its operator`)
    return
  }
  const op = keys[0]
  const arity = OPERATORS[op]
  if (arity === undefined) {
    errors.push(`${path}: unknown operator "${op}"`)
    return
  }
  const args = argsOf(node[op])
  const [min, max] = arity
  const n = args.length
  if (n < min || (max !== -1 && n > max)) {
    const want = max === -1 ? `at least ${min}` : min === max ? String(min) : `${min} to ${max}`
    errors.push(`${path}.${op}: takes ${want} argument(s), got ${n}`)
    return
  }

  switch (op) {
    case 'field':
      if (typeof args[0] !== 'string' || !NAME_PATTERN.test(args[0])) {
        errors.push(`${path}.field: a field name in lowercase letters, digits and underscores`)
      }
      return
    case 'has_role':
      if (typeof args[0] !== 'string' || !NAME_PATTERN.test(args[0])) {
        errors.push(`${path}.has_role: a role name`)
      }
      return
    case 'item':
      if (itemScopes === 0) errors.push(`${path}.item: only inside any, all, count or sum`)
      if (n === 1 && args[0] !== null && (typeof args[0] !== 'string' || !NAME_PATTERN.test(args[0]))) {
        errors.push(`${path}.item: a key name, or nothing for the item itself`)
      }
      return
    case 'state':
    case 'today':
    case 'now':
      return
    case 'if':
      if (n % 2 === 0) {
        errors.push(`${path}.if: takes condition, value pairs and a final otherwise value (an odd count)`)
      }
      break
    case 'round':
      if (n === 2 && (!isWhole(args[1]) || args[1] < 0 || args[1] > 6)) {
        errors.push(`${path}.round: digits must be a whole number from 0 to 6`)
      }
      break
  }

  args.forEach((arg, i) => {
    const scope = ITEM_SCOPES.has(op) && i === 1 ? itemScopes + 1 : itemScopes
    validateNode(arg, `${path}.${op}[${i}]`, depth + 1, scope, counter, errors)
  })
}

/**
 * Evaluate a valid expression. Validate first; an invalid tree throws a type
 * error at the first bad node.
 *
 * @throws ExpressionError
 */
export function evaluateExpression(expression: unknown, context: ExpressionContext = {}): ExpressionValue {
  const steps = { count: 0 }
  return run(expression, context, [], steps)
}

function run(node: unknown, ctx: ExpressionContext, items: unknown[], steps: { count: number }): ExpressionValue {
  if (++steps.count > MAX_STEPS) {
    throw new ExpressionError('budget', `evaluation budget of ${MAX_STEPS} steps exceeded`)
  }
  if (!isOperation(node)) {
    if (Array.isArray(node)) throw new ExpressionError('type', 'not an expression')
    return (node ?? null) as ExpressionValue
  }
  const keys = Object.keys(node)
  if (keys.length !== 1) throw new ExpressionError('type', 'not an expression')
  const op = keys[0]
  const args = argsOf(node[op])
  const ev = (a: unknown): ExpressionValue => run(a, ctx, items, steps)

  switch (op) {
    case 'field':
      return (ctx.fields?.[String(args[0])] ?? null) as ExpressionValue
    case 'item': {
      const item = items.length === 0 ? null : items[items.length - 1]
      if (args[0] === undefined || args[0] === null) return (item ?? null) as ExpressionValue
      return isOperation(item) ? ((item[String(args[0])] ?? null) as ExpressionValue) : null
    }
    case 'state':
      return ctx.state ?? null
    case 'today':
      return ctx.today ?? '1970-01-01'
    case 'now':
      return ctx.now ?? '1970-01-01T00:00:00Z'
    case 'has_role':
      return (ctx.roles ?? []).includes(String(args[0]))

    case 'and':
      for (const a of args) if (!truth(ev(a), 'and')) return false
      return true
    case 'or':
      for (const a of args) if (truth(ev(a), 'or')) return true
      return false
    case 'not':
      return !truth(ev(args[0]), 'not')
    case 'if': {
      const last = args.length - 1
      for (let i = 0; i < last; i += 2) {
        if (truth(ev(args[i]), 'if')) return ev(args[i + 1])
      }
      return ev(args[last])
    }

    case '==':
      return equal(ev(args[0]), ev(args[1]))
    case '!=':
      return !equal(ev(args[0]), ev(args[1]))
    case '<':
    case '<=':
    case '>':
    case '>=':
      return order(op, ev(args[0]), ev(args[1]))

    case '+':
    case '*':
    case 'min':
    case 'max': {
      const values = args.map(ev)
      if (values.includes(null)) return null
      const nums = values.map((v) => number(v, op))
      if (op === '+') return nums.reduce((a, b) => a + b, 0)
      if (op === '*') return nums.reduce((a, b) => a * b, 1)
      return op === 'min' ? Math.min(...nums) : Math.max(...nums)
    }
    case '-': {
      const values = args.map(ev)
      if (values.includes(null)) return null
      const nums = values.map((v) => number(v, '-'))
      return nums.length === 1 ? -nums[0] : nums[0] - nums[1]
    }
    case '/': {
      const a = ev(args[0])
      const b = ev(args[1])
      if (a === null || b === null) return null
      const x = number(a, '/')
      const y = number(b, '/')
      return y === 0 ? null : x / y
    }
    case 'round': {
      const v = ev(args[0])
      if (v === null) return null
      const x = number(v, 'round')
      const scale = 10 ** Number(args[1] ?? 0)
      return ((x < 0 ? -1 : 1) * Math.floor(Math.abs(x) * scale + 0.5)) / scale
    }
    case 'abs': {
      const v = ev(args[0])
      return v === null ? null : Math.abs(number(v, 'abs'))
    }

    case 'concat': {
      const values = args.map(ev)
      if (values.includes(null)) return null
      return values.map((v) => text(v, 'concat')).join('')
    }
    case 'contains': {
      const haystack = ev(args[0])
      const needle = ev(args[1])
      if (haystack === null || needle === null) return null
      if (Array.isArray(haystack)) return haystack.some((h) => equal(h, needle))
      return stringOnly(haystack, 'contains').includes(stringOnly(needle, 'contains'))
    }
    case 'starts_with':
    case 'ends_with': {
      const a = ev(args[0])
      const b = ev(args[1])
      if (a === null || b === null) return null
      const s = stringOnly(a, op)
      const t = stringOnly(b, op)
      return op === 'starts_with' ? s.startsWith(t) : s.endsWith(t)
    }
    case 'length': {
      const v = ev(args[0])
      if (v === null) return null
      if (Array.isArray(v)) return v.length
      return charLength(stringOnly(v, 'length'))
    }
    case 'lower':
    case 'upper': {
      const v = ev(args[0])
      if (v === null) return null
      const s = stringOnly(v, op)
      return op === 'lower' ? s.toLowerCase() : s.toUpperCase()
    }

    case 'add_days': {
      const date = ev(args[0])
      const days = ev(args[1])
      if (date === null || days === null) return null
      if (!isWhole(days)) throw new ExpressionError('type', 'add_days: the number of days must be a whole number')
      return new Date(dayStamp(date, 'add_days') + days * DAY_MS).toISOString().slice(0, 10)
    }
    case 'days_between': {
      const a = ev(args[0])
      const b = ev(args[1])
      if (a === null || b === null) return null
      return Math.round((dayStamp(b, 'days_between') - dayStamp(a, 'days_between')) / DAY_MS)
    }

    case 'list':
      return args.map(ev)
    case 'in': {
      const needle = ev(args[0])
      const list = ev(args[1])
      if (list === null) return false
      return listOnly(list, 'in').some((h) => equal(h, needle))
    }
    case 'any':
    case 'all':
    case 'count':
    case 'sum': {
      const raw = ev(args[0])
      const list = raw === null ? [] : listOnly(raw, op)
      if (op === 'count' && args.length < 2) return list.length
      let total = 0
      for (const item of list) {
        const value = run(args[1], ctx, [...items, item], steps)
        if (op === 'sum') {
          if (value !== null) total += number(value, 'sum')
          continue
        }
        const hit = truth(value, op)
        if (op === 'any' && hit) return true
        if (op === 'all' && !hit) return false
        if (op === 'count' && hit) total++
      }
      if (op === 'any') return false
      if (op === 'all') return true
      return total
    }
    case 'is_empty': {
      const v = ev(args[0])
      return v === null || v === '' || (Array.isArray(v) && v.length === 0)
    }
    case 'coalesce':
      for (const a of args) {
        const v = ev(a)
        if (v !== null) return v
      }
      return null
  }

  throw new ExpressionError('type', `unknown operator "${op}"`)
}

function truth(value: ExpressionValue, op: string): boolean {
  if (value === null) return false
  if (typeof value !== 'boolean') throw new ExpressionError('type', `${op}: a condition must be true or false`)
  return value
}

function equal(a: unknown, b: unknown): boolean {
  if (Array.isArray(a) && Array.isArray(b)) {
    return a.length === b.length && a.every((v, i) => equal(v, b[i]))
  }
  if (isOperation(a) && isOperation(b)) {
    const ka = Object.keys(a)
    const kb = Object.keys(b)
    return ka.length === kb.length && ka.every((k) => k in b && equal(a[k], b[k]))
  }
  return a === b || (a === null && b === undefined) || (a === undefined && b === null)
}

function order(op: string, a: ExpressionValue, b: ExpressionValue): boolean {
  if (a === null || b === null) return false
  const numbers = typeof a === 'number' && typeof b === 'number'
  if (!numbers && !(typeof a === 'string' && typeof b === 'string')) {
    throw new ExpressionError('type', `${op}: compares two numbers or two texts`)
  }
  const cmp = a < b ? -1 : a > b ? 1 : 0
  if (op === '<') return cmp < 0
  if (op === '<=') return cmp <= 0
  if (op === '>') return cmp > 0
  return cmp >= 0
}

function number(value: ExpressionValue, op: string): number {
  if (typeof value !== 'number') throw new ExpressionError('type', `${op}: expects numbers`)
  return value
}

function text(value: ExpressionValue, op: string): string {
  if (typeof value === 'string') return value
  if (typeof value === 'number') return String(value)
  throw new ExpressionError('type', `${op}: expects text or numbers`)
}

function stringOnly(value: ExpressionValue, op: string): string {
  if (typeof value !== 'string') throw new ExpressionError('type', `${op}: expects text`)
  return value
}

function listOnly(value: ExpressionValue, op: string): ExpressionValue[] {
  if (!Array.isArray(value)) throw new ExpressionError('type', `${op}: expects a list`)
  return value
}

function dayStamp(value: ExpressionValue, op: string): number {
  if (typeof value !== 'string' || !DATE_PATTERN.test(value)) {
    throw new ExpressionError('type', `${op}: expects a date as YYYY-MM-DD`)
  }
  const [y, m, d] = value.split('-').map(Number)
  const stamp = Date.UTC(y, m - 1, d)
  const check = new Date(stamp)
  if (check.getUTCFullYear() !== y || check.getUTCMonth() !== m - 1 || check.getUTCDate() !== d) {
    throw new ExpressionError('type', `${op}: "${value}" isn't a real date`)
  }
  return stamp
}
