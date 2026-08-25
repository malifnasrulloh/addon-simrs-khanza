<?php

declare(strict_types=1);

namespace SatusehatPanel\Core;

/**
 * ModuleManager - Runtime auto-discovery and router registration for
 * modular SATUSEHAT panel resource workspaces.
 *
 * Scans `satusehat-panel/modules/*\/manifest.json` dynamically so any newly
 * created resource folder is instantly available as an API endpoint,
 * Launchpad card, and workspace view without modifying core route files.
 */
final class ModuleManager
{
    private static ?string $modulesDirOverride = null;
    private static ?array $cachedManifests = null;

    public static function getModulesDirectory(): string
    {
        return self::$modulesDirOverride ?? (__DIR__ . '/../../modules');
    }

    public static function setModulesDirectoryForTesting(?string $dir): void
    {
        self::$modulesDirOverride = $dir;
        self::$cachedManifests = null;
    }

    /**
     * Scan the modules directory and return all valid manifests sorted by category and order.
     *
     * @return array<string, array> Keyed by module ID
     */
    public static function discover(): array
    {
        if (self::$cachedManifests !== null) {
            return self::$cachedManifests;
        }

        $dir = self::getModulesDirectory();
        if (!is_dir($dir)) {
            return [];
        }

        $manifests = [];
        $entries = scandir($dir);
        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($dir . '/' . $entry)) {
                continue;
            }

            $manifestFile = $dir . '/' . $entry . '/manifest.json';
            if (!is_file($manifestFile)) {
                continue;
            }

            $raw = @file_get_contents($manifestFile);
            if ($raw === false) {
                continue;
            }

            $manifest = json_decode($raw, true);
            if (!is_array($manifest) || empty($manifest['id'])) {
                continue;
            }

            $manifest['dir'] = $entry;
            $manifest['has_controller'] = is_file($dir . '/' . $entry . '/Controller.php');
            $manifest['has_view'] = is_file($dir . '/' . $entry . '/view.js');
            $manifest['has_style'] = is_file($dir . '/' . $entry . '/style.css');

            $manifests[$manifest['id']] = $manifest;
        }

        // Sort by order/category
        uasort($manifests, function (array $a, array $b) {
            $orderA = $a['order'] ?? 999;
            $orderB = $b['order'] ?? 999;
            if ($orderA !== $orderB) {
                return $orderA <=> $orderB;
            }
            return strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
        });

        self::$cachedManifests = $manifests;
        return $manifests;
    }

    /**
     * Get manifest for a specific module.
     */
    public static function get(string $moduleId): ?array
    {
        $manifests = self::discover();
        return $manifests[$moduleId] ?? null;
    }

    /**
     * Dynamically register REST endpoints for all discovered modules.
     */
    public static function registerRoutes(Router $router): void
    {
        // 1. API: List all discovered modules for Launchpad and Quick-Switcher
        $router->add('GET', '/api/modules', function () {
            $manifests = self::discover();
            return [
                'success' => true,
                'data' => array_values($manifests),
                'count' => count($manifests),
            ];
        });

        // 2. Register module-specific routes
        $manifests = self::discover();
        foreach ($manifests as $id => $manifest) {
            $controllerPath = self::getModulesDirectory() . '/' . $manifest['dir'] . '/Controller.php';
            if (!is_file($controllerPath)) {
                continue;
            }

            // GET /api/modules/{id}/list
            $router->add('GET', '/api/modules/' . $id . '/list', function () use ($controllerPath, $id) {
                require_once $controllerPath;
                $className = self::getControllerClassName($id);
                if (class_exists($className) && method_exists($className, 'list')) {
                    return $className::list();
                }
                return ['success' => false, 'error' => "Module {$id} controller list() not found"];
            });

            // GET /api/modules/{id}/preview/{key:any}
            $router->add('GET', '/api/modules/' . $id . '/preview/{key:any}', function (array $params) use ($controllerPath, $id) {
                require_once $controllerPath;
                $className = self::getControllerClassName($id);
                if (class_exists($className) && method_exists($className, 'preview')) {
                    return $className::preview($params['key']);
                }
                return ['success' => false, 'error' => "Module {$id} controller preview() not found"];
            });

            // POST /api/modules/{id}/send
            $router->add('POST', '/api/modules/' . $id . '/send', function () use ($controllerPath, $id) {
                require_once $controllerPath;
                $className = self::getControllerClassName($id);
                if (class_exists($className) && method_exists($className, 'send')) {
                    return $className::send();
                }
                return ['success' => false, 'error' => "Module {$id} controller send() not found"];
            });
        }
    }

    /**
     * Derive standardized class name for module controller.
     * e.g. "observation_ttv" -> "SatusehatPanel\Modules\ObservationTtv\Controller"
     * or fallback to global namespace "SatusehatPanel\Modules\{PascalCase}\Controller"
     */
    public static function getControllerClassName(string $moduleId): string
    {
        $parts = explode('_', str_replace('-', '_', $moduleId));
        $pascal = implode('', array_map('ucfirst', $parts));
        return "SatusehatPanel\\Modules\\{$pascal}\\Controller";
    }
}
