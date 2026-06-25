<?php

declare(strict_types=1);

namespace Whity\Mcp\Tools;

use Whity\Mcp\JsonRpc\ErrorCode;
use Whity\Mcp\JsonRpc\McpException;

/**
 * Validates and coerces MCP tool arguments against the JSON-Schema inputSchema
 * derived by ToolDeriver (WC-b570dccd).
 *
 * Enforces:
 *   - All required properties are present.
 *   - Every declared property's type matches, or the value can be safely coerced
 *     (e.g. "5" → 5 for type:integer, "true" → true for type:boolean).
 *   - No array/object is passed where a scalar type is declared (prevents
 *     JSON-array injection into path params and request bodies).
 *   - No null bytes in string values (prevents null-byte injection).
 *
 * Arguments are modified in-place when coercion occurs, so the caller's array
 * reflects the coerced types before path substitution and Request synthesis.
 *
 * Malformed arguments throw McpException(INVALID_PARAMS) so the Dispatcher can
 * return the correct JSON-RPC error code (-32602) to the AI client.
 */
final class InputSchemaValidator
{
    /**
     * Validate $arguments against $schema and coerce types in-place.
     *
     * @param array<string, mixed>  $schema    JSON-Schema inputSchema (from ToolDeriver).
     * @param array<string, mixed> &$arguments MCP tool arguments, modified in-place.
     * @throws McpException On any validation failure.
     */
    public function validate(array $schema, array &$arguments): void
    {
        /** @var array<string, array<string, mixed>> $properties */
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        /** @var list<string> $required */
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        // Check all required fields are present.
        foreach ($required as $field) {
            if (!is_string($field)) {
                continue;
            }
            if (!array_key_exists($field, $arguments)) {
                throw new McpException(
                    ErrorCode::INVALID_PARAMS,
                    "Missing required argument: {$field}",
                );
            }
        }

        // Validate and coerce each declared property that was provided.
        foreach ($properties as $field => $propSchema) {
            if (!is_string($field) || !array_key_exists($field, $arguments)) {
                continue;
            }

            $value        = $arguments[$field];
            $declaredType = is_string($propSchema['type'] ?? null) ? $propSchema['type'] : null;

            // Arrays/objects are never valid for scalar types — reject first, before
            // type-specific checks so the error message is unambiguous.
            if (is_array($value) && in_array($declaredType, ['string', 'integer', 'number', 'boolean'], true)) {
                throw new McpException(
                    ErrorCode::INVALID_PARAMS,
                    "Argument '{$field}' must be a {$declaredType}, got array",
                );
            }

            $arguments[$field] = match ($declaredType) {
                'integer' => $this->coerceInteger($field, $value),
                'number'  => $this->coerceNumber($field, $value),
                'boolean' => $this->coerceBoolean($field, $value),
                'string'  => $this->validateString($field, $value),
                default   => $value, // Unknown or absent type: pass through unchanged.
            };
        }
    }

    // ── Type coercers / validators ────────────────────────────────────────────

    private function coerceInteger(string $field, mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        // Accept digit-only strings (optionally negative) — mirrors PaginationParams.
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        throw new McpException(
            ErrorCode::INVALID_PARAMS,
            "Argument '{$field}' must be an integer",
        );
    }

    private function coerceNumber(string $field, mixed $value): float|int
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }
        throw new McpException(
            ErrorCode::INVALID_PARAMS,
            "Argument '{$field}' must be a number",
        );
    }

    private function coerceBoolean(string $field, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false') {
            return false;
        }
        throw new McpException(
            ErrorCode::INVALID_PARAMS,
            "Argument '{$field}' must be a boolean",
        );
    }

    private function validateString(string $field, mixed $value): string
    {
        if (!is_string($value)) {
            throw new McpException(
                ErrorCode::INVALID_PARAMS,
                "Argument '{$field}' must be a string",
            );
        }
        // Null bytes are an injection vector; reject unconditionally.
        if (str_contains($value, "\x00")) {
            throw new McpException(
                ErrorCode::INVALID_PARAMS,
                "Argument '{$field}' contains invalid characters",
            );
        }
        return $value;
    }
}
