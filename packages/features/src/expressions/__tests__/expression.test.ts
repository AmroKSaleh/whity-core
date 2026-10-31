/**
 * WHIT-759: the TypeScript evaluator against the shared fixtures, the same file
 * tests/Sdk/Expression/ExpressionTest.php runs the PHP evaluator against. A
 * rule checked in a form and the same rule checked on save must agree.
 */

import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import {
  evaluateExpression,
  ExpressionError,
  validateExpression,
  type ExpressionContext,
} from '../index'

interface EvaluateCase {
  name: string
  expr: unknown
  context?: ExpressionContext
  expect?: unknown
  error?: string
}

interface InvalidCase {
  name: string
  expr: unknown
  error: string
}

const fixtures = JSON.parse(
  readFileSync(join(__dirname, '../../../../../sdk/resources/expression-fixtures.json'), 'utf8'),
) as { context: ExpressionContext; evaluate: EvaluateCase[]; invalid: InvalidCase[] }

/** Expands the placeholders the fixture file uses for inputs too big to write out. */
function expand(value: unknown): unknown {
  if (value === 'BIG_LIST_4000') return Array.from({ length: 4000 }, (_, i) => i + 1)
  if (value === 'DEEP_25') {
    let node: unknown = true
    for (let i = 0; i < 25; i++) node = { not: [node] }
    return node
  }
  if (value === 'WIDE_300') return { '+': Array.from({ length: 300 }, () => 1) }
  if (value === 'LONG_TEXT_1001') return 'a'.repeat(1001)
  if (Array.isArray(value)) return value.map(expand)
  if (value !== null && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, expand(v)]))
  }
  return value
}

describe('manifest expressions (shared fixtures)', () => {
  it.each(fixtures.evaluate.map((c) => [c.name, c] as const))('evaluates: %s', (_name, c) => {
    const expression = expand(c.expr)
    const validation = validateExpression(expression)
    expect(validation.errors).toEqual([])

    const context = expand(c.context ?? fixtures.context) as ExpressionContext
    if (c.error !== undefined) {
      let thrown: unknown
      try {
        evaluateExpression(expression, context)
      } catch (e) {
        thrown = e
      }
      expect(thrown).toBeInstanceOf(ExpressionError)
      expect((thrown as ExpressionError).kind).toBe(c.error)
      return
    }
    expect(evaluateExpression(expression, context)).toEqual(c.expect)
  })

  it.each(fixtures.invalid.map((c) => [c.name, c] as const))('refuses: %s', (_name, c) => {
    const result = validateExpression(expand(c.expr))
    expect(result.ok).toBe(false)
    expect(result.errors.some((e) => e.includes(c.error))).toBe(true)
  })
})
