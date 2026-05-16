# Installation

## Requirements

- PHP 8.4+
- Composer
- FrankenPHP or PHP-FPM with Swoole
- SQLite 3.8+ (or PostgreSQL for production)

## Quick Start

### 1. Install via Composer

```bash
composer require amroksaleh/whity-core:^1.0
```

### 2. Create Configuration

Create `tenant.toml`:

```toml
[branding]
app_name = "My SaaS"

[database]
url = "sqlite:tenant.db"
pool_size = 10
```

### 3. Initialize Database

```bash
./vendor/bin/whity migrate
```

### 4. Load Plugins

Create plugins in `/plugins/{name}/Plugin.php` implementing `PluginInterface`.

### 5. Bootstrap

```php
<?php
require 'vendor/autoload.php';
$app = new \Whity\Core\Application('tenant.toml');
echo $app->handle($request);
```

## Troubleshooting

**"Package not found"** — Ensure whity-core is tagged on GitHub:
```bash
git tag v1.0.0 && git push origin v1.0.0
```

See [CONTRIBUTING.md](../CONTRIBUTING.md) for more help.
