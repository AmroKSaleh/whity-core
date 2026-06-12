<?php

namespace Whity\Console;

use Whity\Database\Database;
use Whity\Core\Router;
use Whity\Core\PluginLoader;
use Whity\OpenAPI\SchemaGenerator;

/**
 * Generate OpenAPI Schema Console Command
 *
 * Usage: php public/index.php generate:openapi
 *
 * Generates OpenAPI 3.0 schema from discovered plugins and
 * saves to public/openapi.json
 */
class GenerateOpenApiSchemaCommand
{
    public static function execute(array $argv): int
    {
        try {
            // Load environment variables from .env file (skip if already set)
            $envFile = dirname(__DIR__, 2) . '/.env';
            if (file_exists($envFile)) {
                $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    if (empty(trim($line)) || strpos(trim($line), '#') === 0) {
                        continue;
                    }

                    if (strpos($line, '=') !== false) {
                        [$key, $value] = explode('=', $line, 2);
                        $key = trim($key);
                        $value = trim($value);

                        if (!getenv($key) && !isset($_ENV[$key])) {
                            $_ENV[$key] = $value;
                            putenv("{$key}={$value}");
                        }
                    }
                }
            }

            // Require composer autoloader
            require dirname(__DIR__, 2) . '/vendor/autoload.php';

            // Initialize router and plugin loader
            $router = new Router();
            $pluginLoader = new PluginLoader(dirname(__DIR__, 2) . '/plugins', $router);

            // Register the core admin resources' typed declarations (WC-167)
            // BEFORE plugins load, mirroring the runtime ordering (WC-169):
            // the Router refuses duplicate method+path registrations (first
            // wins), so the published spec attributes a colliding path to the
            // core handler exactly as the live router would serve it.
            \Whity\OpenAPI\CoreApiSchemas::registerRoutes($router);

            // Load plugins - plugin metadata is available without database connection
            $pluginLoader->load();

            // Generate from the ROUTER (carries every registered route with
            // its typed schema declaration, WC-166) and validate before
            // anything is written: an invalid contract must never be published.
            // The spec's info.version is the platform core version (WC-172) —
            // CoreVersion is the single source of truth, never a literal here.
            $generator = new SchemaGenerator('Whity Core API', \Whity\Core\CoreVersion::VERSION, $pluginLoader, $router);
            ['spec' => $spec, 'errors' => $errors] = $generator->generateAndValidate();

            if ($errors !== []) {
                echo "Error: generated spec failed OpenAPI validation:\n";
                foreach ($errors as $error) {
                    echo "  - {$error}\n";
                }
                return 1;
            }

            // Save to public/openapi.json (deterministic: builder output is
            // sorted, so regeneration over the same routes is byte-identical;
            // the encoder keeps empty maps as JSON objects, valid OAS).
            // An explicit --output=<path> overrides the destination so tests
            // (and tooling) can generate without touching the tracked file.
            $outputPath = dirname(__DIR__, 2) . '/public/openapi.json';
            foreach ($argv as $arg) {
                if (is_string($arg) && str_starts_with($arg, '--output=')) {
                    $outputPath = substr($arg, strlen('--output='));
                }
            }
            $json = SchemaGenerator::encode($spec);

            if (file_put_contents($outputPath, $json) === false) {
                echo "Error: Failed to write to {$outputPath}\n";
                return 1;
            }

            echo "OpenAPI schema generated successfully\n";
            echo "Saved to: public/openapi.json\n";
            echo "Endpoints: " . count($spec['paths']) . "\n";
            echo "Component schemas: " . count($spec['components']['schemas']) . "\n";

            return 0;
        } catch (\Throwable $e) {
            echo "Error: " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
            return 1;
        }
    }
}
