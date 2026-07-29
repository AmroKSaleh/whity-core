<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Queue;

use PHPUnit\Framework\TestCase;
use Whity\Core\Queue\JobRegistry;
use Whity\Sdk\JobInterface;

/**
 * Unit tests for {@see JobRegistry} — handler lookup and the FAIL-CLOSED
 * API-submittable allow-list (a handler is submittable only if it explicitly
 * opts in).
 */
final class JobRegistryTest extends TestCase
{
    public function testRegisteredHandlerIsResolvableButNotSubmittableByDefault(): void
    {
        $registry = new JobRegistry();
        $handler = $this->noopHandler();
        $registry->register('internal.job', $handler);

        self::assertTrue($registry->has('internal.job'));
        self::assertSame($handler, $registry->get('internal.job'));
        self::assertFalse($registry->isSubmittable('internal.job'), 'not submittable unless it opts in');
        self::assertSame([], $registry->submittableNames());
    }

    public function testHandlerCanOptIntoPublicSubmission(): void
    {
        $registry = new JobRegistry();
        $registry->register('public.job', $this->noopHandler(), true);

        self::assertTrue($registry->isSubmittable('public.job'));
        self::assertSame(['public.job'], $registry->submittableNames());
    }

    public function testUnknownNameIsNeitherKnownNorSubmittable(): void
    {
        $registry = new JobRegistry();

        self::assertFalse($registry->has('ghost'));
        self::assertNull($registry->get('ghost'));
        self::assertFalse($registry->isSubmittable('ghost'));
    }

    private function noopHandler(): JobInterface
    {
        return new class implements JobInterface {
            public function handle(array $payload): array
            {
                return [];
            }
        };
    }
}
