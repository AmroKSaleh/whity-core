<?php

declare(strict_types=1);

namespace Whity\Core\RateLimit;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;

/**
 * Kernel rate-limit middleware (WC-c0fb3700).
 *
 * Enforces one or more {@see RateLimitRule} dimensions against the shared
 * {@see RateLimitStoreInterface} (fixed-window, worker-safe). The class is
 * position-agnostic: which dimensions it carries decides whether it acts pre-auth
 * or post-auth in the HTTP pipeline.
 *
 *   - Pre-auth (registered BEFORE EnforceTenantIsolation): a per-IP rule sheds
 *     flood load before any auth / DB work, protecting the public auth surface.
 *   - Post-auth (registered AFTER EnforceTenantIsolation): per-tenant and
 *     per-principal rules cap an authenticated caller's throughput. Their
 *     resolvers read TenantContext / AuditContext, which the isolation middleware
 *     has populated by then, and return null (skip) on public/unauthenticated
 *     requests.
 *
 * On the first dimension that exceeds its budget the request is refused with
 * HTTP 429 and the standard headers (`Retry-After`, `X-RateLimit-Limit`,
 * `X-RateLimit-Remaining`) derived from that dimension's decision; the downstream
 * handler never runs. Allowed requests pass straight through.
 *
 * Worker-safe: holds only boot-scoped config (store, rules, flags); all counter
 * state lives in the shared store.
 */
final class RateLimitMiddleware
{
    private LoggerInterface $logger;

    /** @var list<string> Exact request paths (query stripped) that bypass limiting. */
    private array $exemptPaths;

    /**
     * @param RateLimitStoreInterface $store       Backing fixed-window counter store.
     * @param list<RateLimitRule>     $rules       Dimensions enforced, in order.
     * @param bool                    $enabled     Master switch; false passes everything through.
     * @param list<string>            $exemptPaths Exact paths (e.g. health probes) never limited.
     * @param LoggerInterface|null    $logger      Optional sink for structured 429 records.
     */
    public function __construct(
        private readonly RateLimitStoreInterface $store,
        private readonly array $rules,
        private readonly bool $enabled = true,
        array $exemptPaths = [],
        ?LoggerInterface $logger = null,
    ) {
        $this->exemptPaths = array_values($exemptPaths);
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param callable(Request): Response $next
     */
    public function handle(Request $request, callable $next): Response
    {
        if (!$this->enabled || $this->rules === [] || $this->isExempt($request)) {
            return $next($request);
        }

        foreach ($this->rules as $rule) {
            $value = ($rule->resolve)($request);
            if ($value === null || $value === '') {
                continue; // dimension absent for this request — skip it
            }

            $decision = $this->store->hit("rl:{$rule->name}:{$value}", $rule->limit, $rule->window);
            if (!$decision->allowed) {
                $this->logBlock($rule, $request, $decision);

                return Response::error('Too Many Requests', 429)->withHeaders([
                    'Retry-After'           => (string) $decision->retryAfter,
                    'X-RateLimit-Limit'     => (string) $decision->limit,
                    'X-RateLimit-Remaining' => (string) $decision->remaining,
                ]);
            }
        }

        return $next($request);
    }

    private function isExempt(Request $request): bool
    {
        if ($this->exemptPaths === []) {
            return false;
        }

        $path = parse_url($request->getPath(), PHP_URL_PATH);
        $path = is_string($path) ? $path : $request->getPath();

        return in_array($path, $this->exemptPaths, true);
    }

    private function logBlock(RateLimitRule $rule, Request $request, RateLimitDecision $decision): void
    {
        $path = parse_url($request->getPath(), PHP_URL_PATH);

        $this->logger->warning('Rate limit exceeded', [
            'event'       => 'rate_limit.exceeded',
            'dimension'   => $rule->name,
            'limit'       => $decision->limit,
            'count'       => $decision->count,
            'retry_after' => $decision->retryAfter,
            'method'      => $request->getMethod(),
            'path'        => is_string($path) ? $path : $request->getPath(),
        ]);
    }
}
