<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\JobsTestSeed;
use Whity\Api\JobsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Queue\CoreJobs;
use Whity\Core\Queue\JobRegistry;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\JobRunner;
use Whity\Core\Queue\Jobs\EchoJob;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Response;
use Whity\Sdk\JobInterface;

/**
 * Real-engine tests for {@see JobsApiHandler} (WC-jobs-api): RBAC
 * (jobs:submit vs jobs:read vs none), the fail-closed submittable allow-list,
 * idempotency (201 new vs 200 dedupe), validation, tenant isolation (404, no
 * leak), the paginated list, and reading a completed job's result after the
 * worker runs it — all against a real {@see RoleChecker} + migration schema.
 */
final class JobsApiHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;
    private JobRepository $repo;
    private JobRegistry $registry;
    private JobsApiHandler $handler;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = JobsTestSeed::make();
        $db = JobsTestSeed::wrap($this->pdo);

        $this->repo = new JobRepository($this->pdo);
        $this->registry = new JobRegistry();
        CoreJobs::register($this->registry); // registers EchoJob as submittable
        // A registered-but-NOT-submittable job proves the allow-list gates on the
        // submittable flag, not mere registration.
        $this->registry->register('internal.only', new class implements JobInterface {
            public function handle(array $payload): array
            {
                return [];
            }
        });

        $this->handler = new JobsApiHandler($this->repo, $this->registry, new RoleChecker($db, new PermissionRegistry()));
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    public function testSubmitterCreatesJobAndGets201PendingWithEchoedPayload(): void
    {
        $res = $this->handler->create($this->post(JobsTestSeed::SUBMITTER_A, JobsTestSeed::TENANT_A, [
            'name'    => EchoJob::NAME,
            'payload' => ['hello' => 'world'],
        ]));

        self::assertSame(201, $res->getStatusCode(), $res->getBody());
        $data = $this->data($res);
        self::assertSame(EchoJob::NAME, $data['name']);
        self::assertSame('pending', $data['status']);
        self::assertSame(0, $data['progress']);
        self::assertSame(['hello' => 'world'], $data['payload']);
        self::assertNull($data['result'], 'no result until it runs');
    }

    public function testReaderCannotSubmit403(): void
    {
        $res = $this->handler->create($this->post(JobsTestSeed::READER_A, JobsTestSeed::TENANT_A, ['name' => EchoJob::NAME]));
        self::assertSame(403, $res->getStatusCode());
    }

    public function testCallerWithoutJobsReadCannotGet403(): void
    {
        $res = $this->handler->show($this->get(JobsTestSeed::NOBODY_A, JobsTestSeed::TENANT_A), ['id' => '1']);
        self::assertSame(403, $res->getStatusCode());
    }

    public function testUnknownJobTypeIsRejected422(): void
    {
        $res = $this->handler->create($this->post(JobsTestSeed::SUBMITTER_A, JobsTestSeed::TENANT_A, ['name' => 'no.such.job']));
        self::assertSame(422, $res->getStatusCode());
    }

    public function testRegisteredButNonSubmittableJobIsRejected422(): void
    {
        // 'internal.only' HAS a handler but did not opt into public submission.
        $res = $this->handler->create($this->post(JobsTestSeed::SUBMITTER_A, JobsTestSeed::TENANT_A, ['name' => 'internal.only']));
        self::assertSame(422, $res->getStatusCode(), 'submission is gated on the submittable flag, not registration');
    }

    public function testValidationErrors(): void
    {
        self::assertSame(422, $this->handler->create($this->post(JobsTestSeed::SUBMITTER_A, JobsTestSeed::TENANT_A, []))->getStatusCode(), 'missing name');
        self::assertSame(422, $this->handler->create($this->post(JobsTestSeed::SUBMITTER_A, JobsTestSeed::TENANT_A, ['name' => EchoJob::NAME, 'payload' => 'nope']))->getStatusCode(), 'payload must be an object');
        self::assertSame(422, $this->handler->create($this->post(JobsTestSeed::SUBMITTER_A, JobsTestSeed::TENANT_A, ['name' => EchoJob::NAME, 'queue' => 'Bad Queue!']))->getStatusCode(), 'bad queue charset');
    }

    public function testIdempotencyReturnsTheSameJob(): void
    {
        $first = $this->handler->create($this->post(JobsTestSeed::SUBMITTER_A, JobsTestSeed::TENANT_A, [
            'name'            => EchoJob::NAME,
            'idempotency_key' => 'once-only',
        ]));
        self::assertSame(201, $first->getStatusCode());
        $firstId = $this->data($first)['id'];

        $second = $this->handler->create($this->post(JobsTestSeed::SUBMITTER_A, JobsTestSeed::TENANT_A, [
            'name'            => EchoJob::NAME,
            'idempotency_key' => 'once-only',
        ]));
        self::assertSame(200, $second->getStatusCode(), 'a retried submit returns the existing job, not a duplicate');
        self::assertSame($firstId, $this->data($second)['id']);
    }

    public function testShowReturnsOwnJobButAnotherTenantsIs404(): void
    {
        $created = $this->handler->create($this->post(JobsTestSeed::SUBMITTER_A, JobsTestSeed::TENANT_A, ['name' => EchoJob::NAME]));
        $id = $this->data($created)['id'];

        self::assertSame(200, $this->handler->show($this->get(JobsTestSeed::SUBMITTER_A, JobsTestSeed::TENANT_A), ['id' => (string) $id])->getStatusCode());

        // Tenant B (a legitimate submitter in its own tenant) must not see tenant A's job.
        $foreign = $this->handler->show($this->get(JobsTestSeed::SUBMITTER_B, JobsTestSeed::TENANT_B), ['id' => (string) $id]);
        self::assertSame(404, $foreign->getStatusCode(), "another tenant's job is 404, never a cross-tenant leak");
    }

    public function testCompletedJobExposesItsResultAfterTheWorkerRuns(): void
    {
        $created = $this->handler->create($this->post(JobsTestSeed::SUBMITTER_A, JobsTestSeed::TENANT_A, [
            'name'    => EchoJob::NAME,
            'payload' => ['ping' => 1],
        ]));
        $id = $this->data($created)['id'];

        // Run the job through the real runner (same registry the worker uses).
        (new JobRunner($this->repo, $this->registry))->processNext();
        TenantContext::reset();

        $res = $this->handler->show($this->get(JobsTestSeed::SUBMITTER_A, JobsTestSeed::TENANT_A), ['id' => (string) $id]);
        self::assertSame(200, $res->getStatusCode());
        $data = $this->data($res);
        self::assertSame('completed', $data['status']);
        self::assertSame(100, $data['progress']);
        self::assertSame(['echoed' => ['ping' => 1]], $data['result'], 'the echo job result is readable via the status API');
    }

    public function testListReturnsTenantJobsWithPaginationEnvelope(): void
    {
        $this->handler->create($this->post(JobsTestSeed::SUBMITTER_A, JobsTestSeed::TENANT_A, ['name' => EchoJob::NAME]));
        $this->handler->create($this->post(JobsTestSeed::SUBMITTER_A, JobsTestSeed::TENANT_A, ['name' => EchoJob::NAME]));
        // A job in another tenant must never appear in tenant A's list.
        $this->handler->create($this->post(JobsTestSeed::SUBMITTER_B, JobsTestSeed::TENANT_B, ['name' => EchoJob::NAME]));

        $res = $this->handler->list($this->get(JobsTestSeed::SUBMITTER_A, JobsTestSeed::TENANT_A, '/api/jobs'));
        self::assertSame(200, $res->getStatusCode());
        $body = $this->body($res);
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('pagination', $body);
        self::assertSame(2, $body['pagination']['total'], 'only this tenant\'s jobs are counted');
        self::assertSame(1, $body['pagination']['page']);
        self::assertSame(25, $body['pagination']['perPage']);
        self::assertCount(2, $body['data']);
    }

    public function testListRejectsAnInvalidStatusFilter(): void
    {
        $res = $this->handler->list($this->get(JobsTestSeed::SUBMITTER_A, JobsTestSeed::TENANT_A, '/api/jobs?status=bogus'));
        self::assertSame(422, $res->getStatusCode());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function get(int $userId, int $tenantId, string $path = '/api/jobs'): Request
    {
        return $this->build('GET', $userId, $tenantId, $path, '');
    }

    /** @param array<string, mixed> $body */
    private function post(int $userId, int $tenantId, array $body): Request
    {
        return $this->build('POST', $userId, $tenantId, '/api/jobs', (string) json_encode($body));
    }

    private function build(string $method, int $userId, int $tenantId, string $path, string $body): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        $req = new Request($method, $path, [], $body);
        $req->user = (object) ['profile_id' => $userId, 'active_tenant_id' => $tenantId];

        return $req;
    }

    /** @return array<string, mixed> */
    private function body(Response $res): array
    {
        $decoded = json_decode($res->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function data(Response $res): array
    {
        $body = $this->body($res);
        self::assertArrayHasKey('data', $body);
        self::assertIsArray($body['data']);

        return $body['data'];
    }
}
