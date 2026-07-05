<?php

namespace Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Whity\Auth\BackupCodesService;
use PDO;
use PDOStatement;

/**
 * Tests for BackupCodesService class
 *
 * Tests backup code generation, hashing, validation, and management
 */
class BackupCodesServiceTest extends TestCase
{
    private BackupCodesService $backupCodesService;
    private $mockDb;

    protected function setUp(): void
    {
        // Create a mock Database instance
        $this->mockDb = $this->getMockBuilder('Whity\Database\Database')
            ->disableOriginalConstructor()
            ->getMock();

        // Initialize the service with mocked database
        $this->backupCodesService = new BackupCodesService($this->mockDb);
    }

    /**
     * Test generateCodes returns correct count by default
     */
    public function testGenerateCodesReturnsCorrectCount(): void
    {
        $codes = $this->backupCodesService->generateCodes(15);

        $this->assertCount(15, $codes);
    }

    /**
     * Test generateCodes returns unique codes
     */
    public function testGenerateCodesReturnsUniqueCodes(): void
    {
        $codes = $this->backupCodesService->generateCodes(15);

        // All codes should be unique
        $this->assertCount(count(array_unique($codes)), $codes);
    }

    /**
     * Test generateCodes returns codes in correct format XXXX-XXXX-XXXX
     */
    public function testGenerateCodesReturnsCorrectFormat(): void
    {
        $codes = $this->backupCodesService->generateCodes(15);

        foreach ($codes as $code) {
            // Format should be XXXX-XXXX-XXXX (12 alphanumeric chars with hyphens)
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code);
        }
    }

    /**
     * Test generateCodes with custom count
     */
    public function testGenerateCodesWithCustomCount(): void
    {
        $codes = $this->backupCodesService->generateCodes(10);
        $this->assertCount(10, $codes);

        $codes = $this->backupCodesService->generateCodes(20);
        $this->assertCount(20, $codes);
    }

    /**
     * Test hashCode returns a valid bcrypt hash
     */
    public function testHashCodeReturnsBcryptHash(): void
    {
        $code = 'ABCD-1234-EFGH';
        $hash = $this->backupCodesService->hashCode($code);

        $this->assertNotEmpty($hash);
        // Bcrypt hashes start with $2
        $this->assertStringStartsWith('$2', $hash);
        // Should be verifiable
        $this->assertTrue(password_verify($code, $hash));
    }

    /**
     * Test hashCode produces different hashes for same input
     */
    public function testHashCodeProducesDifferentHashesForSameInput(): void
    {
        $code = 'ABCD-1234-EFGH';
        $hash1 = $this->backupCodesService->hashCode($code);
        $hash2 = $this->backupCodesService->hashCode($code);

        // Hashes should be different due to random salt in bcrypt
        $this->assertNotEquals($hash1, $hash2);
        // But both should verify against the same code
        $this->assertTrue(password_verify($code, $hash1));
        $this->assertTrue(password_verify($code, $hash2));
    }

    /**
     * Test validateCode with valid unused code returns true and marks as used
     */
    public function testValidateCodeWithValidUnusedCodeReturnsTrue(): void
    {
        // Generate a code and create mock database response
        $code = 'ABCD-1234-EFGH';
        $hash = $this->backupCodesService->hashCode($code);
        $userId = 123;
        $version = 1;

        // Create mock statement for SELECT query
        $mockSelectStatement = $this->createMock(PDOStatement::class);
        $mockSelectStatement->method('fetch')->willReturn([
            'id' => 1,
            'code' => $hash
        ]);

        // Create mock statement for UPDATE query
        $mockUpdateStatement = $this->createMock(PDOStatement::class);

        // Setup mock database to return different statements based on call count
        $this->mockDb->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls($mockSelectStatement, $mockUpdateStatement);

        $result = $this->backupCodesService->validateCode($userId, $code, $version);
        $this->assertTrue($result);
    }

    /**
     * Test validateCode with already-used code returns false
     */
    public function testValidateCodeWithAlreadyUsedCodeReturnsFalse(): void
    {
        $userId = 123;
        $version = 1;

        // Create mock statement for SELECT query that returns no results
        $mockSelectStatement = $this->createMock(PDOStatement::class);
        $mockSelectStatement->method('fetch')->willReturn(null);

        $this->mockDb->method('query')
            ->willReturn($mockSelectStatement);

        $result = $this->backupCodesService->validateCode($userId, 'XXXX-9999-YYYY', $version);
        $this->assertFalse($result);
    }

    /**
     * Test validateCode with wrong version returns false
     */
    public function testValidateCodeWithWrongVersionReturnsFalse(): void
    {
        $userId = 123;
        $version = 1;

        // Create mock statement for SELECT query that returns no results (because version doesn't match)
        $mockSelectStatement = $this->createMock(PDOStatement::class);
        $mockSelectStatement->method('fetch')->willReturn(null);

        $this->mockDb->method('query')
            ->willReturn($mockSelectStatement);

        $result = $this->backupCodesService->validateCode($userId, 'ABCD-1234-EFGH', 2);
        $this->assertFalse($result);
    }

    /**
     * Test validateCode with non-existent user returns false
     */
    public function testValidateCodeWithNonExistentUserReturnsFalse(): void
    {
        // Create mock statement that returns no results
        $mockSelectStatement = $this->createMock(PDOStatement::class);
        $mockSelectStatement->method('fetch')->willReturn(null);

        $this->mockDb->method('query')
            ->willReturn($mockSelectStatement);

        $result = $this->backupCodesService->validateCode(99999, 'ABCD-1234-EFGH', 1);
        $this->assertFalse($result);
    }

    /**
     * Test validateCode with invalid code returns false
     */
    public function testValidateCodeWithInvalidCodeReturnsFalse(): void
    {
        $code = 'ABCD-1234-EFGH';
        $wrongCode = 'XXXX-9999-YYYY';
        $hash = $this->backupCodesService->hashCode($code);
        $userId = 123;
        $version = 1;

        // Create mock statement that returns a code (but with wrong hash)
        $mockSelectStatement = $this->createMock(PDOStatement::class);
        $mockSelectStatement->method('fetch')->willReturn([
            'id' => 1,
            'code' => $hash // This is the correct hash, but we're checking with wrong code
        ]);

        $this->mockDb->method('query')
            ->willReturn($mockSelectStatement);

        // validateCode will verify the code against the hash and fail
        $result = $this->backupCodesService->validateCode($userId, $wrongCode, $version);
        $this->assertFalse($result);
    }

    /**
     * Test invalidateOldCodes marks all old-version codes as used
     */
    public function testInvalidateOldCodesMarksOldVersionAsUsed(): void
    {
        $userId = 123;
        $oldVersion = 1;

        // Create mock statement for UPDATE query
        $mockUpdateStatement = $this->createMock(PDOStatement::class);

        $this->mockDb->expects($this->once())
            ->method('query')
            ->willReturn($mockUpdateStatement);

        // Should not throw any exceptions
        $this->backupCodesService->invalidateOldCodes($userId, $oldVersion);
    }

    /**
     * Test getAvailableCodeCount returns correct count of unused codes
     */
    public function testGetAvailableCodeCountReturnsCorrectCount(): void
    {
        $profileId = 123;

        // Create mock statement for COUNT query
        $mockCountStatement = $this->createMock(PDOStatement::class);
        $mockCountStatement->method('fetch')->willReturn([
            'count' => 3
        ]);

        // Return the count statement for any query — this test only asserts
        // that the service correctly reads the count value from the result row.
        $this->mockDb->method('query')
            ->willReturn($mockCountStatement);

        $count = $this->backupCodesService->getAvailableCodeCount($profileId);
        $this->assertEquals(3, $count);
    }

    /**
     * Test getAvailableCodeCount returns 0 when all codes are used
     */
    public function testGetAvailableCodeCountReturnsZeroWhenAllUsed(): void
    {
        $userId = 123;

        // Create mock statement for COUNT query
        $mockCountStatement = $this->createMock(PDOStatement::class);
        $mockCountStatement->method('fetch')->willReturn([
            'count' => 0
        ]);

        $this->mockDb->method('query')
            ->willReturn($mockCountStatement);

        $count = $this->backupCodesService->getAvailableCodeCount($userId);
        $this->assertEquals(0, $count);
    }

    /**
     * Test getAvailableCodeCount returns 0 when user has no codes
     */
    public function testGetAvailableCodeCountReturnsZeroWhenNoCodesExist(): void
    {
        $userId = 999;

        // Create mock statement for COUNT query
        $mockCountStatement = $this->createMock(PDOStatement::class);
        $mockCountStatement->method('fetch')->willReturn([
            'count' => 0
        ]);

        $this->mockDb->method('query')
            ->willReturn($mockCountStatement);

        $count = $this->backupCodesService->getAvailableCodeCount($userId);
        $this->assertEquals(0, $count);
    }

    /**
     * Test validateCode handles database errors gracefully
     */
    public function testValidateCodeHandlesDatabaseException(): void
    {
        $this->mockDb->method('query')
            ->willThrowException(new \Exception('Database error'));

        $result = $this->backupCodesService->validateCode(123, 'ABCD-1234-EFGH', 1);
        $this->assertFalse($result);
    }

    /**
     * Test getAvailableCodeCount handles database errors gracefully
     */
    public function testGetAvailableCodeCountHandlesDatabaseException(): void
    {
        $this->mockDb->method('query')
            ->willThrowException(new \Exception('Database error'));

        $count = $this->backupCodesService->getAvailableCodeCount(123);
        $this->assertEquals(0, $count);
    }
}
