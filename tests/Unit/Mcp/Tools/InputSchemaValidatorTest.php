<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Tools;

use PHPUnit\Framework\TestCase;
use Whity\Mcp\JsonRpc\ErrorCode;
use Whity\Mcp\JsonRpc\McpException;
use Whity\Mcp\Tools\InputSchemaValidator;

/**
 * TDD tests for InputSchemaValidator (WC-b570dccd).
 *
 * Validates MCP tool arguments against the JSON-Schema inputSchema derived
 * by ToolDeriver, coercing safe type conversions and rejecting malformed input
 * with INVALID_PARAMS errors before the Request is synthesized.
 */
final class InputSchemaValidatorTest extends TestCase
{
    private InputSchemaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new InputSchemaValidator();
    }

    // ── Required fields ───────────────────────────────────────────────────────

    public function testValidate_passes_whenNoPropertiesDeclared(): void
    {
        $args = [];
        $this->validator->validate(['type' => 'object'], $args);
        self::assertSame([], $args); // no change, no throw
    }

    public function testValidate_throwsInvalidParams_whenRequiredFieldMissing(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::INVALID_PARAMS);

        $args = ['other' => 'value'];
        $this->validator->validate([
            'type'       => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required'   => ['name'],
        ], $args);
    }

    public function testValidate_passes_whenRequiredFieldPresent(): void
    {
        $args = ['name' => 'Widget'];
        $this->validator->validate([
            'type'       => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required'   => ['name'],
        ], $args);
        self::assertSame(['name' => 'Widget'], $args);
    }

    public function testValidate_passes_whenOptionalFieldAbsent(): void
    {
        $args = [];
        $this->validator->validate([
            'type'       => 'object',
            'properties' => ['search' => ['type' => 'string']],
            // 'required' intentionally omitted
        ], $args);
        self::assertSame([], $args);
    }

    // ── Integer coercion ──────────────────────────────────────────────────────

    public function testValidate_passesThrough_nativeInteger(): void
    {
        $args = ['id' => 42];
        $this->validator->validate([
            'type'       => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required'   => ['id'],
        ], $args);
        self::assertSame(42, $args['id']);
    }

    public function testValidate_coercesDigitString_toInteger(): void
    {
        $args = ['id' => '7'];
        $this->validator->validate([
            'type'       => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required'   => ['id'],
        ], $args);
        self::assertSame(7, $args['id']);
    }

    public function testValidate_coercesNegativeDigitString_toInteger(): void
    {
        $args = ['offset' => '-3'];
        $this->validator->validate([
            'type'       => 'object',
            'properties' => ['offset' => ['type' => 'integer']],
            'required'   => ['offset'],
        ], $args);
        self::assertSame(-3, $args['offset']);
    }

    public function testValidate_throwsInvalidParams_forNonNumericStringAsInteger(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::INVALID_PARAMS);

        $args = ['id' => 'abc'];
        $this->validator->validate([
            'type'       => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required'   => ['id'],
        ], $args);
    }

    public function testValidate_throwsInvalidParams_forFloatStringAsInteger(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::INVALID_PARAMS);

        $args = ['id' => '3.14'];
        $this->validator->validate([
            'type'       => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required'   => ['id'],
        ], $args);
    }

    // ── Number coercion ───────────────────────────────────────────────────────

    public function testValidate_passesThrough_nativeFloat(): void
    {
        $args = ['price' => 9.99];
        $this->validator->validate([
            'type'       => 'object',
            'properties' => ['price' => ['type' => 'number']],
            'required'   => ['price'],
        ], $args);
        self::assertSame(9.99, $args['price']);
    }

    public function testValidate_coercesNumericString_toNumber(): void
    {
        $args = ['price' => '9.99'];
        $this->validator->validate([
            'type'       => 'object',
            'properties' => ['price' => ['type' => 'number']],
            'required'   => ['price'],
        ], $args);
        self::assertSame(9.99, $args['price']);
    }

    public function testValidate_throwsInvalidParams_forNonNumericStringAsNumber(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::INVALID_PARAMS);

        $args = ['price' => 'cheap'];
        $this->validator->validate([
            'type'       => 'object',
            'properties' => ['price' => ['type' => 'number']],
            'required'   => ['price'],
        ], $args);
    }

    // ── Boolean coercion ──────────────────────────────────────────────────────

    public function testValidate_passesThrough_nativeBoolean(): void
    {
        $args = ['active' => true];
        $this->validator->validate([
            'type'       => 'object',
            'properties' => ['active' => ['type' => 'boolean']],
            'required'   => ['active'],
        ], $args);
        self::assertTrue($args['active']);
    }

    public function testValidate_coercesStringTrue_toBoolean(): void
    {
        $args = ['active' => 'true'];
        $this->validator->validate([
            'type'       => 'object',
            'properties' => ['active' => ['type' => 'boolean']],
            'required'   => ['active'],
        ], $args);
        self::assertTrue($args['active']);
    }

    public function testValidate_coercesStringFalse_toBoolean(): void
    {
        $args = ['active' => 'false'];
        $this->validator->validate([
            'type'       => 'object',
            'properties' => ['active' => ['type' => 'boolean']],
            'required'   => ['active'],
        ], $args);
        self::assertFalse($args['active']);
    }

    public function testValidate_throwsInvalidParams_forAmbiguousStringAsBoolean(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::INVALID_PARAMS);

        $args = ['active' => 'yes'];
        $this->validator->validate([
            'type'       => 'object',
            'properties' => ['active' => ['type' => 'boolean']],
            'required'   => ['active'],
        ], $args);
    }

    // ── String validation ─────────────────────────────────────────────────────

    public function testValidate_passes_forValidString(): void
    {
        $args = ['name' => 'hello world'];
        $this->validator->validate([
            'type'       => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required'   => ['name'],
        ], $args);
        self::assertSame('hello world', $args['name']);
    }

    public function testValidate_throwsInvalidParams_forNullByteInString(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::INVALID_PARAMS);

        $args = ['name' => "hello\x00world"];
        $this->validator->validate([
            'type'       => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required'   => ['name'],
        ], $args);
    }

    public function testValidate_throwsInvalidParams_whenArrayPassedForStringField(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::INVALID_PARAMS);

        $args = ['name' => ['evil', 'array']];
        $this->validator->validate([
            'type'       => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required'   => ['name'],
        ], $args);
    }

    // ── Scalar rejection for array values ────────────────────────────────────

    public function testValidate_throwsInvalidParams_whenArrayPassedForIntegerField(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::INVALID_PARAMS);

        $args = ['id' => [1, 2, 3]];
        $this->validator->validate([
            'type'       => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required'   => ['id'],
        ], $args);
    }

    // ── Unknown / undeclared fields ───────────────────────────────────────────

    public function testValidate_ignoresFieldsNotDeclaredInSchema(): void
    {
        $args = ['unknown_field' => 'anything'];
        $this->validator->validate(['type' => 'object'], $args);
        self::assertSame(['unknown_field' => 'anything'], $args);
    }

    public function testValidate_passesThrough_whenTypeNotDeclared(): void
    {
        $args = ['data' => ['anything' => 'goes']];
        $this->validator->validate([
            'type'       => 'object',
            'properties' => ['data' => []], // no 'type' key
        ], $args);
        // Should not throw — unknown type means no type check
        self::assertIsArray($args['data']);
    }

    // ── Multiple fields ───────────────────────────────────────────────────────

    public function testValidate_coercesAllTypedFields_inSingleCall(): void
    {
        $args = ['id' => '5', 'active' => 'true', 'name' => 'Widget'];
        $this->validator->validate([
            'type'       => 'object',
            'properties' => [
                'id'     => ['type' => 'integer'],
                'active' => ['type' => 'boolean'],
                'name'   => ['type' => 'string'],
            ],
            'required'   => ['id', 'active', 'name'],
        ], $args);

        self::assertSame(5, $args['id']);
        self::assertTrue($args['active']);
        self::assertSame('Widget', $args['name']);
    }
}
