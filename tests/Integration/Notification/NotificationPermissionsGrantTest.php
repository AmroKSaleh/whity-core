<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;

/**
 * Real-engine test for the notification RBAC grant migration (074, #4b87abf0):
 * on the migration-built schema (admin role seeded by migration 001), the two
 * notification admin permissions are in the catalogue AND granted to `admin`.
 */
final class NotificationPermissionsGrantTest extends TestCase
{
    private const PERMS = ['notification_settings:manage', 'notifications:manage'];

    public function testNotificationAdminPermissionsAreInTheCatalogue(): void
    {
        $pdo = SchemaFromMigrations::make();
        $stmt = $pdo->query(
            "SELECT name FROM permissions WHERE name IN ('notifications:manage', 'notification_settings:manage')"
        );
        self::assertNotFalse($stmt);
        $names = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        sort($names);
        self::assertSame(self::PERMS, $names, 'both notification admin permissions must exist in the catalogue');
    }

    public function testNotificationAdminPermissionsAreGrantedToAdmin(): void
    {
        $pdo = SchemaFromMigrations::make();
        $stmt = $pdo->query(
            "SELECT p.name FROM role_permissions rp
               JOIN roles r ON r.id = rp.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE r.name = 'admin'
                AND p.name IN ('notifications:manage', 'notification_settings:manage')"
        );
        self::assertNotFalse($stmt);
        $granted = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        sort($granted);
        self::assertSame(self::PERMS, $granted, 'the admin role must hold both notification admin permissions');
    }
}
