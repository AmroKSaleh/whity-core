---
"@whity-org/features": minor
---

Add `@whity-org/features/expressions`: `validateExpression()` and `evaluateExpression()` for the manifest expression language (WHIT-759), the TypeScript twin of `Whity\Sdk\Expression\Expression` in the plugin SDK. Forms can check a manifest rule as someone types and get the same answer the server gives on save. Both evaluators pass the same fixtures, `sdk/resources/expression-fixtures.json`.
