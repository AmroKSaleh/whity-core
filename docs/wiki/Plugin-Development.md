# Plugin Development

Plugins extend Whity Core without modifying the framework.

## Structure

```
plugins/my-plugin/
├─ Plugin.php          ← Implements PluginInterface
├─ src/
│  ├─ Controllers/
│  └─ Services/
├─ tests/
└─ migrations/
```

## Implement PluginInterface

```php
<?php
namespace Plugins\MyPlugin;

use Whity\Core\PluginInterface;
use Whity\Auth\RBACEngine;

class Plugin implements PluginInterface {
    public function id(): string { return 'my-plugin'; }
    public function name(): string { return 'My Plugin'; }
    public function version(): string { return '1.0.0'; }
    
    public function onEnable(RBACEngine $rbac): void {
        // Register permissions
    }
    
    public function onDisable(): void {
        // Cleanup
    }
    
    public function route(string $path): callable {
        return match($path) {
            '/dashboard' => new DashboardController(),
            default => throw new RouteNotFoundException(),
        };
    }
}
```

## Key Rules

1. **Stateless** — No static properties
2. **RBAC-aware** — Always check permissions
3. **Tenant-scoped** — Use `$auth->tenant_id` for queries
4. **Exception-safe** — Don't crash core
5. **Well-tested** — 80% coverage minimum

## Testing

```php
public function test_implements_interface() {
    $plugin = new Plugin();
    $this->assertInstanceOf(PluginInterface::class, $plugin);
}
```

## Publishing

Open issue with:
- Plugin name/description
- GitHub repo link
- Test coverage

See [CONTRIBUTING.md](../CONTRIBUTING.md) for full guidelines.
