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

            // Load plugins - plugin metadata is available without database connection
            $pluginLoader->load();

            // Generate schema
            $generator = new SchemaGenerator('Whity Core API', '1.0.0', $pluginLoader);
            $spec = $generator->generate();

            // Save to public/openapi.json
            $outputPath = dirname(__DIR__, 2) . '/public/openapi.json';
            $json = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if (file_put_contents($outputPath, $json) === false) {
                echo "Error: Failed to write to {$outputPath}\n";
                return 1;
            }

            echo "OpenAPI schema generated successfully\n";
            echo "Saved to: public/openapi.json\n";
            echo "Endpoints: " . count($spec['paths']) . "\n";

            return 0;
        } catch (\Throwable $e) {
            echo "Error: " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
            return 1;
        }
    }
}
