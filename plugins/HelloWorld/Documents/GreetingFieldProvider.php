<?php

declare(strict_types=1);

namespace HelloWorld\Documents;

use PDO;
use Whity\Sdk\Documents\DocumentFieldProvider;

/**
 * A greeting as a document record type (SDK 1.56, WHIT-742): a template
 * written for `HelloWorld.greeting` prints its message, and the plugin issues
 * "greeting cards" from its `greeting_card` slot.
 *
 * Every read answers for one reader in one workspace: without `hello:view`,
 * or for another workspace's greeting, there is no record. The PDO is resolved
 * per call by the plugin, as its greetings handler's is.
 */
final class GreetingFieldProvider implements DocumentFieldProvider
{
    public const TYPE = 'HelloWorld.greeting';

    /** @var \Closure(): PDO */
    private \Closure $pdo;

    /** @param \Closure(): PDO $pdo */
    public function __construct(\Closure $pdo)
    {
        $this->pdo = $pdo;
    }

    public function recordType(): string
    {
        return self::TYPE;
    }

    public function recordLabel(): string
    {
        return 'Greeting';
    }

    public function groups(int $tenantId): array
    {
        return [[
            'group' => 'Greeting',
            'items' => [
                ['path' => 'greeting.message', 'label' => 'Message', 'sample' => 'Hello from Example Bakery', 'required' => true],
                ['path' => 'greeting.number', 'label' => 'Greeting number', 'sample' => '7'],
            ],
        ]];
    }

    public function values(int $tenantId, int $callerId, callable $hasPermission, ?string $recordId): ?array
    {
        if ($recordId === null || !ctype_digit($recordId) || !$hasPermission('hello:view')) {
            return null;
        }
        $stmt = ($this->pdo)()->prepare('SELECT id, message FROM hello_greetings WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([':id' => (int) $recordId, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'label' => '#' . (int) $row['id'] . ' · ' . (string) $row['message'],
            'values' => [
                'greeting.message' => (string) $row['message'],
                'greeting.number' => (string) $row['id'],
            ],
        ];
    }

    public function records(int $tenantId, int $callerId, callable $hasPermission, string $query, int $limit): array
    {
        if (!$hasPermission('hello:view')) {
            return [];
        }
        $stmt = ($this->pdo)()->prepare(
            'SELECT id, message FROM hello_greetings WHERE tenant_id = :tenant_id AND message LIKE :q ORDER BY id DESC LIMIT ' . max(1, min($limit, 50))
        );
        $stmt->execute([':tenant_id' => $tenantId, ':q' => '%' . addcslashes($query, '%_\\') . '%']);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = ['id' => (string) $row['id'], 'label' => '#' . (int) $row['id'] . ' · ' . (string) $row['message']];
        }

        return $out;
    }
}
