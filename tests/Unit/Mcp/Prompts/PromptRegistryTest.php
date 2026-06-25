<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Prompts;

use PHPUnit\Framework\TestCase;
use Whity\Mcp\Prompts\Prompt;
use Whity\Mcp\Prompts\PromptRegistry;

/**
 * TDD tests for PromptRegistry (WC-7755fc38).
 */
final class PromptRegistryTest extends TestCase
{
    private PromptRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new PromptRegistry();
    }

    public function testAll_returnsEmpty_whenNothingRegistered(): void
    {
        self::assertSame([], $this->registry->all());
    }

    public function testRegister_addsPromptToList(): void
    {
        $prompt = new Prompt('test-prompt', 'A test prompt');
        $this->registry->register($prompt);

        self::assertCount(1, $this->registry->all());
        self::assertSame($prompt, $this->registry->all()[0]);
    }

    public function testAll_returnsAllRegisteredPrompts(): void
    {
        $a = new Prompt('prompt-a', 'A');
        $b = new Prompt('prompt-b', 'B');
        $this->registry->register($a);
        $this->registry->register($b);

        self::assertCount(2, $this->registry->all());
    }

    public function testFind_returnsPrompt_whenNameMatches(): void
    {
        $prompt = new Prompt('my-prompt', 'My prompt');
        $this->registry->register($prompt);

        self::assertSame($prompt, $this->registry->find('my-prompt'));
    }

    public function testFind_returnsNull_whenNameNotFound(): void
    {
        $this->registry->register(new Prompt('real-prompt', 'Real'));

        self::assertNull($this->registry->find('nonexistent'));
    }

    public function testFind_returnsFirstMatch_whenDuplicateNamesRegistered(): void
    {
        $first  = new Prompt('dup', 'First');
        $second = new Prompt('dup', 'Second');
        $this->registry->register($first);
        $this->registry->register($second);

        self::assertSame($first, $this->registry->find('dup'));
    }
}
