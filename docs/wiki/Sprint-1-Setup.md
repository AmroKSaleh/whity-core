# Sprint 1: Setup and Development Guide

This guide provides everything you need to set up the Whity Core development environment for the Sprint 1 "Hello World" MVP. It covers local development setup, testing the basic framework, and creating your first plugin.

## Overview

The Whity Core Sprint 1 MVP includes:
- **HTTP Kernel**: Request dispatching with middleware support
- **Router**: Route matching with path parameters
- **JWT Parser**: Token creation and validation with HS256 signatures
- **RBAC Middleware**: Role-based access control enforcement
- **Role Checker**: Database-backed role verification
- **Plugin Loader**: Reflection-based plugin discovery and registration
- **Admin Stats Plugin**: Example plugin demonstrating the plugin system

This guide will help you:
1. Set up the development environment
2. Verify everything works with health checks
3. Test authentication and authorization
4. Create a new plugin from scratch
5. Run the test suite

## Prerequisites

Before getting started, ensure you have:

- **PHP 8.4 or higher** - Check with `php -v`
- **Composer** - PHP dependency manager ([install here](https://getcomposer.org/))
- **Docker and Docker Compose** - For PostgreSQL database
- **Git** - For version control
- **curl** or **Postman** - For testing HTTP endpoints
- **PHPUnit 10+** - For running tests (installed via Composer)

### System Requirements

**Minimum:**
- 4 GB RAM
- 2 GB disk space
- macOS, Linux, or Windows (WSL2)

**Recommended:**
- 8 GB RAM
- 5 GB disk space
- Modern CPU with multiple cores

## Local Setup

### 1. Clone the Repository

```bash
git clone https://github.com/AmroKSaleh/whity-core.git
cd whity-core
```

### 2. Configure Environment Variables

Copy the example environment file:

```bash
cp .env.example .env
```

The `.env` file contains:

```env
DB_USER=whity
DB_PASSWORD=whity_dev
DB_NAME=whity_core
DB_HOST=postgres
DB_PORT=5432
JWT_SECRET=dev_secret_key_change_in_production
```

For development, these defaults are fine. For production, change `JWT_SECRET` to a secure random value:

```bash
# Generate a random secret (Linux/macOS)
openssl rand -base64 32
```

### 3. Install PHP Dependencies

```bash
composer install
```

This installs:
- **phpunit/phpunit** - Testing framework
- **phpstan/phpstan** - Static analysis
- Framework code into `/vendor/`

### 4. Start Docker Services

```bash
docker-compose up -d
```

This starts PostgreSQL on `localhost:5432`. Verify it's ready:

```bash
docker-compose ps
```

Expected output:
```
NAME                COMMAND                  SERVICE      STATUS
whity_postgres      "docker-entrypoint.s…"   postgres     Up (healthy)
```

Wait for the health check to pass (it may take 10-15 seconds).

### 5. Run Database Migrations

**Note:** For Sprint 1, the database schema is set up with basic users and roles tables. In a production environment, you would use a migration system. For now, we'll manually create the schema.

Create the database tables by running the SQL setup script:

```bash
docker-compose exec postgres psql -U whity -d whity_core << 'EOF'
-- Create roles table
CREATE TABLE IF NOT EXISTS roles (
    id SERIAL PRIMARY KEY,
    name VARCHAR(50) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create users table
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    name VARCHAR(255),
    role_id INTEGER REFERENCES roles(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Seed default roles
INSERT INTO roles (name) VALUES ('admin'), ('user') ON CONFLICT DO NOTHING;

-- Seed test users
INSERT INTO users (email, name, role_id) 
SELECT 'admin@example.com', 'Admin User', (SELECT id FROM roles WHERE name = 'admin')
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'admin@example.com');

INSERT INTO users (email, name, role_id) 
SELECT 'user@example.com', 'Regular User', (SELECT id FROM roles WHERE name = 'user')
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'user@example.com');
EOF
```

Verify the setup:

```bash
docker-compose exec postgres psql -U whity -d whity_core -c "SELECT * FROM users;"
```

Expected output:
```
 id |        email         |     name     | role_id |         created_at
----+----------------------+--------------+---------+----------------------------
  1 | admin@example.com    | Admin User   |       1 | 2025-05-17 10:00:00
  2 | user@example.com     | Regular User |       2 | 2025-05-17 10:00:01
```

## Verify Setup

### Health Check Endpoint

The framework includes an Admin Stats plugin that provides a `/api/admin/stats` endpoint. Let's verify the setup works.

First, generate JWT tokens for testing. Run PHP locally:

```bash
php -r "
\$secret = 'dev_secret_key_change_in_production';
\$adminPayload = ['user_id' => 1, 'email' => 'admin@example.com'];
\$userPayload = ['user_id' => 2, 'email' => 'user@example.com'];

// Admin token (expires in 1 hour)
\$adminToken = generateToken(\$adminPayload, \$secret);
echo \"Admin Token: \$adminToken\n\n\";

// User token (expires in 1 hour)
\$userToken = generateToken(\$userPayload, \$secret);
echo \"User Token: \$userToken\n\";

function generateToken(\$payload, \$secret) {
    \$payload['exp'] = time() + 3600;
    \$header = ['alg' => 'HS256', 'typ' => 'JWT'];
    
    \$headerB64 = base64URLEncode(json_encode(\$header));
    \$payloadB64 = base64URLEncode(json_encode(\$payload));
    \$signature = base64URLEncode(hash_hmac('sha256', \"\$headerB64.\$payloadB64\", \$secret, true));
    
    return \"\$headerB64.\$payloadB64.\$signature\";
}

function base64URLEncode(\$data) {
    return strtr(base64_encode(\$data), '+/', '-_');
}
"
```

Save the tokens for testing. Then test the endpoints:

### Test 1: Admin Access (Should Return 200)

```bash
# Replace ADMIN_TOKEN with the token from above
curl -X GET http://localhost:8000/api/admin/stats \
  -H "Authorization: Bearer ADMIN_TOKEN"
```

Expected response:
```json
{
  "timestamp": "2025-05-17 10:15:30",
  "uptime": "0.05 seconds",
  "message": "Admin stats endpoint working",
  "user": {
    "user_id": 1,
    "email": "admin@example.com",
    "exp": 1747329330
  }
}
```

Status code: **200 OK**

### Test 2: User Access (Should Return 403)

```bash
# Replace USER_TOKEN with the token from above
curl -X GET http://localhost:8000/api/admin/stats \
  -H "Authorization: Bearer USER_TOKEN"
```

Expected response:
```json
{
  "error": "Insufficient permissions"
}
```

Status code: **403 Forbidden**

### Test 3: No Token (Should Return 401)

```bash
curl -X GET http://localhost:8000/api/admin/stats
```

Expected response:
```json
{
  "error": "Missing or invalid Authorization header"
}
```

Status code: **401 Unauthorized**

## Testing the Hello World MVP

The Admin Stats plugin serves as the "Hello World" for Sprint 1. It demonstrates:
- Route registration via plugins
- RBAC enforcement at the middleware layer
- JWT token validation
- Role checking with database lookup

### Core Concepts Demonstrated

**1. Route Registration**
The `AdminStats` plugin implements `PluginInterface` and is automatically discovered by `PluginLoader`. Its `getRoute()` method registers `/api/admin/stats` with the router.

**2. HTTP Method**
The `getMethod()` returns `'GET'`, telling the router this endpoint only accepts GET requests.

**3. Required Role**
The `getRequiredRole()` returns `'admin'`, which tells the `HttpKernel` to apply RBAC middleware validation.

**4. Request Handler**
The `handle(Request $request)` method receives the authenticated request (with `$request->user` populated) and returns a JSON response.

**5. RBAC Flow**
```
Request with JWT token
  ↓ [RbacMiddleware] Extract and validate JWT
  ↓ [JwtParser] Verify signature and expiration
  ↓ [RoleChecker] Query database for user's role
  ↓ [RbacMiddleware] Check if role matches required role
  ↓ [Plugin Handler] Execute business logic
  ↓
Response
```

## Running Tests

The framework includes comprehensive unit tests for all components.

### Run All Tests

```bash
vendor/bin/phpunit
```

### Run Tests for Specific Component

```bash
# Test HTTP Kernel
vendor/bin/phpunit tests/Http/HttpKernelTest.php

# Test RBAC Middleware
vendor/bin/phpunit tests/Http/RbacMiddlewareTest.php

# Test JWT Parser
vendor/bin/phpunit tests/Auth/JwtParserTest.php
```

### Run with Coverage Report

```bash
vendor/bin/phpunit --coverage-html=coverage/
# Then open coverage/index.html in a browser
```

### Expected Test Results

All tests should pass. Example output:

```
PHPUnit 10.5.0 by Sebastian Bergmann and contributors.

Testing Tests\Http\HttpKernelTest

 .......                                                            7 / 7 (100%)

Testing Tests\Auth\JwtParserTest

 ...................                                               19 / 19 (100%)

OK (26 tests, 0 assertions)
```

## Creating a New Plugin

Let's create a simple "Hello World" plugin to demonstrate the plugin system.

### Step 1: Create the Plugin File

Create `/plugins/HelloWorld.php`:

```php
<?php

namespace Whity\Plugins;

use Whity\Core\PluginInterface;
use Whity\Core\Request;
use Whity\Core\Response;

/**
 * HelloWorld plugin - A simple greeting plugin
 *
 * This plugin demonstrates the basic structure of a Whity Core plugin.
 * It provides a public endpoint that returns a greeting message.
 */
class HelloWorld implements PluginInterface
{
    /**
     * Get the route path
     *
     * @return string
     */
    public function getRoute(): string
    {
        return '/api/hello';
    }

    /**
     * Get the HTTP method
     *
     * @return string
     */
    public function getMethod(): string
    {
        return 'GET';
    }

    /**
     * Get the required role (null = public)
     *
     * @return string|null
     */
    public function getRequiredRole(): ?string
    {
        return null;
    }

    /**
     * Handle the request
     *
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        $data = [
            'message' => 'Hello from Whity Core!',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0.0',
        ];

        return Response::json($data);
    }
}
```

### Step 2: Test the Plugin

The `PluginLoader` automatically discovers plugins in `/plugins/` directory. Restart your application (or create a simple test script) and test:

```bash
curl -X GET http://localhost:8000/api/hello
```

Expected response:
```json
{
  "message": "Hello from Whity Core!",
  "timestamp": "2025-05-17 10:30:45",
  "version": "1.0.0"
}
```

Status code: **200 OK**

### Step 3: Add Authentication to the Plugin

Let's modify the plugin to show the authenticated user when provided with a token:

```php
<?php

namespace Whity\Plugins;

use Whity\Core\PluginInterface;
use Whity\Core\Request;
use Whity\Core\Response;

class HelloWorld implements PluginInterface
{
    public function getRoute(): string
    {
        return '/api/hello';
    }

    public function getMethod(): string
    {
        return 'GET';
    }

    public function getRequiredRole(): ?string
    {
        // Now requires authentication
        return 'user';
    }

    public function handle(Request $request): Response
    {
        $data = [
            'message' => 'Hello ' . ($request->user->email ?? 'Guest'),
            'timestamp' => date('Y-m-d H:i:s'),
            'user' => $request->user,
        ];

        return Response::json($data);
    }
}
```

Now test with your user token:

```bash
curl -X GET http://localhost:8000/api/hello \
  -H "Authorization: Bearer USER_TOKEN"
```

Expected response:
```json
{
  "message": "Hello user@example.com",
  "timestamp": "2025-05-17 10:32:00",
  "user": {
    "user_id": 2,
    "email": "user@example.com",
    "exp": 1747329330
  }
}
```

## Key Framework Components

### 1. Request

**File:** `src/Core/Request.php`

Wraps HTTP request data:

```php
$request->getMethod();      // 'GET', 'POST', etc.
$request->getPath();        // '/api/hello'
$request->getHeader($name); // Get specific header
$request->getHeaders();     // Get all headers
$request->getBody();        // Request body
$request->user;             // Populated by RbacMiddleware
```

### 2. Response

**File:** `src/Core/Response.php`

Builds HTTP responses:

```php
// JSON response
Response::json(['key' => 'value']);

// Error response
Response::error('Error message', 400);

// Custom response
new Response(200, 'body', ['header' => 'value']);
```

### 3. Router

**File:** `src/Core/Router.php`

Registers and matches routes:

```php
$router->register('GET', '/users/{id}', $handler, 'admin');
// Supports path parameters: /users/{id} → $request->params['id']
```

### 4. HttpKernel

**File:** `src/Http/HttpKernel.php`

Dispatches requests to routes and applies middleware:

```php
$kernel = new HttpKernel($router, $rbacMiddleware);
$response = $kernel->handle($request);
```

### 5. RbacMiddleware

**File:** `src/Http/RbacMiddleware.php`

Enforces role-based access control:

```php
// Validates JWT
// Checks user role if required
// Attaches user to request
```

### 6. JwtParser

**File:** `src/Auth/JwtParser.php`

Creates and validates JWT tokens:

```php
$parser = new JwtParser($secret);

// Create token
$token = $parser->create(['user_id' => 1, 'email' => 'user@example.com'], 3600);

// Validate token
$payload = $parser->parse($token);
```

### 7. RoleChecker

**File:** `src/Auth/RoleChecker.php`

Verifies user roles from database:

```php
$checker = new RoleChecker($db);
$checker->hasRole(1, 'admin'); // true/false
```

### 8. PluginLoader

**File:** `src/Core/PluginLoader.php`

Discovers and registers plugins using reflection:

```php
$loader = new PluginLoader('/plugins', $router);
$loader->load(); // Scans /plugins/*, registers plugins
```

## Plugin Development Cheat Sheet

### Plugin Interface

Every plugin must implement `PluginInterface`:

```php
interface PluginInterface {
    public function getRoute(): string;          // '/api/endpoint'
    public function getMethod(): string;         // 'GET', 'POST', etc.
    public function getRequiredRole(): ?string;  // 'admin' or null
    public function handle(Request $request): Response;
}
```

### Plugin Naming Convention

- **File:** `CamelCase.php` (e.g., `HelloWorld.php`, `AdminStats.php`)
- **Namespace:** `Whity\Plugins\`
- **Class Name:** Must match filename (e.g., `HelloWorld`)

### Plugin Discovery

The `PluginLoader` automatically:
1. Scans `/plugins/` directory
2. Requires each `.php` file
3. Uses reflection to check if class implements `PluginInterface`
4. Instantiates and registers with router

**No manual registration needed!**

### Plugin Lifecycle

1. **Load Phase**: PluginLoader instantiates your plugin
2. **Route Registration**: `getRoute()`, `getMethod()`, `getRequiredRole()` tell router how to handle requests
3. **Request Phase**: When a request matches, `handle()` is called
4. **Response Phase**: Return JSON, HTML, or custom response

## Architecture Overview

```
┌─────────────────────────────────────────────────────────┐
│                    Browser / Client                      │
└──────────────────┬──────────────────────────────────────┘
                   │ HTTP Request
                   ↓
┌─────────────────────────────────────────────────────────┐
│                   FrankenPHP Server                      │
│  ┌────────────────────────────────────────────────────┐ │
│  │ Request Parser                                     │ │
│  │ Convert $_SERVER, headers to Request object        │ │
│  └────────────────┬─────────────────────────────────┘ │
│                   │                                    │
│  ┌────────────────↓─────────────────────────────────┐ │
│  │ HttpKernel::handle()                            │ │
│  │ ┌─────────────────────────────────────────────┐ │ │
│  │ │ 1. Router::match()                          │ │ │
│  │ │    Find matching route                      │ │ │
│  │ └────────────┬────────────────────────────────┘ │ │
│  │              │                                  │ │
│  │ ┌────────────↓────────────────────────────────┐ │ │
│  │ │ 2. Check if role required?                 │ │ │
│  │ │    If yes: Apply RbacMiddleware            │ │ │
│  │ └────────────┬────────────────────────────────┘ │ │
│  │              │                                  │ │
│  │ ┌────────────↓────────────────────────────────┐ │ │
│  │ │ RbacMiddleware::handle()                   │ │ │
│  │ │ ┌──────────────────────────────────────┐ │ │ │
│  │ │ │ Extract JWT from Authorization     │ │ │ │
│  │ │ │ JwtParser::parse() - validate sig  │ │ │ │
│  │ │ │ RoleChecker - query database       │ │ │ │
│  │ │ │ Verify role matches                │ │ │ │
│  │ │ │ Attach user to request             │ │ │ │
│  │ │ └────────────┬─────────────────────┘ │ │ │ │
│  │ │              │                        │ │ │ │
│  │ └──────────────┼────────────────────────┘ │ │
│  │                │                          │ │
│  │ ┌──────────────↓────────────────────────┐ │ │
│  │ │ 3. Call Plugin Handler                │ │ │
│  │ │    Plugin::handle(Request $request)   │ │ │
│  │ │    → BusinessLogic()                  │ │ │
│  │ │    ← Response::json($data)            │ │ │
│  │ └────────────┬─────────────────────────┘ │ │
│  │              │                           │ │
│  └──────────────┼───────────────────────────┘ │
│                 │                             │
│                 ↓ HTTP Response               │
└────────────────────────────────────────────────┘
                   │
                   ↓
         Browser / Client Display
```

## Troubleshooting

### Docker Issues

**Problem:** `docker-compose up` fails or PostgreSQL won't start

```bash
# Check Docker daemon
docker ps

# Remove and restart containers
docker-compose down
docker-compose up -d

# Check logs
docker-compose logs postgres
```

**Problem:** Can't connect to database

```bash
# Verify PostgreSQL is running
docker-compose exec postgres pg_isready

# Check environment variables
cat .env

# Verify users table exists
docker-compose exec postgres psql -U whity -d whity_core -c "\dt"
```

### JWT / Authentication Issues

**Problem:** "Invalid or expired token"

```bash
# Verify JWT_SECRET matches in .env and token generation
cat .env | grep JWT_SECRET

# Generate new token with correct secret
php token-generator.php
```

**Problem:** "Insufficient permissions" (403)

```bash
# Check user role in database
docker-compose exec postgres psql -U whity -d whity_core \
  -c "SELECT u.email, r.name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = 1;"

# Verify role matches endpoint requirement
# Check plugin's getRequiredRole() method
```

### Plugin Loading Issues

**Problem:** Plugin not being discovered

```bash
# Verify file location
ls -la /plugins/

# Verify class name matches filename (case-sensitive)
# HelloWorld.php → class HelloWorld

# Verify namespace
grep -n "namespace" /plugins/HelloWorld.php
# Should be: namespace Whity\Plugins;

# Check for syntax errors
php -l /plugins/HelloWorld.php

# Enable debug logging if available
```

**Problem:** "Not Found" (404) when accessing plugin route

1. Verify plugin file exists in `/plugins/`
2. Verify class implements `PluginInterface`
3. Verify `getRoute()` matches your URL exactly
4. Verify `getMethod()` matches HTTP method (GET, POST, etc.)
5. Restart application (PluginLoader only runs at startup)

### Testing Issues

**Problem:** Tests fail with "class not found"

```bash
# Ensure dependencies are installed
composer install

# Clear autoload cache
composer dumpautoload

# Run specific test
vendor/bin/phpunit tests/Http/HttpKernelTest.php --verbose
```

**Problem:** Database connection errors in tests

```bash
# Some tests may need a database connection
# Ensure PostgreSQL is running
docker-compose ps

# Check DATABASE_URL or similar env vars
cat .env
```

## Production Deployment

**Note:** Sprint 1 is a development MVP. Before production, implement:

### Security

- [ ] Change `JWT_SECRET` to cryptographically secure value
- [ ] Enable HTTPS/TLS
- [ ] Add rate limiting
- [ ] Add input validation
- [ ] Add SQL injection protection (use parameterized queries)
- [ ] Enable CORS properly
- [ ] Add request signing for API integrity

### Performance

- [ ] Enable PHP opcache
- [ ] Set up database connection pooling
- [ ] Add caching layer (Redis)
- [ ] Enable HTTP caching headers
- [ ] Set up load balancing

### Operations

- [ ] Set up logging and monitoring
- [ ] Create backup strategy
- [ ] Document deployment procedure
- [ ] Set up CI/CD pipeline
- [ ] Create alerting rules
- [ ] Plan disaster recovery

### Architecture

- [ ] Move to multi-tenant database (per-tenant or shared)
- [ ] Implement database migrations
- [ ] Add GraphQL layer (optional)
- [ ] Set up event system
- [ ] Add plugin marketplace
- [ ] Implement feature flags

## Next Steps

Now that you have Sprint 1 running:

1. **Create custom plugins** - Build domain-specific functionality
2. **Write tests** - Ensure code quality with unit/integration tests
3. **Review architecture** - Read [Architecture.md](Architecture.md) for design principles
4. **Read plugin guide** - See [Plugin-Development.md](Plugin-Development.md) for details
5. **Explore code** - Navigate `src/` to understand components

## Getting Help

- **Documentation**: [docs/wiki/](https://github.com/AmroKSaleh/whity-core/tree/main/docs/wiki/)
- **Issues**: [GitHub Issues](https://github.com/AmroKSaleh/whity-core/issues/)
- **Discussions**: [GitHub Discussions](https://github.com/AmroKSaleh/whity-core/discussions/)

## Summary

Sprint 1 gives you:
- ✅ Basic HTTP framework with routing
- ✅ JWT authentication and validation
- ✅ RBAC enforcement at middleware layer
- ✅ Plugin system with automatic discovery
- ✅ Database-backed role checking
- ✅ Example Admin Stats plugin
- ✅ Comprehensive test suite

Use this as a foundation for building multi-tenant applications with zero-downtime deployments and hot-loadable plugins.

Happy coding!
