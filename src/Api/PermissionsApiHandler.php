<?php

namespace Whity\Api;

use Whity\Core\Request;
use Whity\Core\Response;
use PDO;

/**
 * Permissions API Handler
 *
 * Returns available permissions for role assignment.
 */
class PermissionsApiHandler
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * GET /api/permissions - List all permissions
     */
    public function list(Request $request): Response
    {
        try {
            $stmt = $this->db->prepare('
                SELECT id, name, description
                FROM permissions
                ORDER BY name
            ');
            $stmt->execute();
            $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return Response::json(['data' => $permissions], 200);
        } catch (\Exception $e) {
            return Response::error('Failed to fetch permissions: ' . $e->getMessage(), 500);
        }
    }
}
