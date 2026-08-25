<?php

namespace SatusehatPanel\Core;

use SatusehatPanel\Controller\AuthController;
use SatusehatPanel\Controller\AuditController;
use SatusehatPanel\Controller\PatientController;
use SatusehatPanel\Controller\ResourceController;
use SatusehatPanel\Controller\SendController;
use SatusehatPanel\Controller\SettingsController;

/**
 * Route registration for the panel front controller.
 *
 * Kept in its own class (instead of inline in index.php) so tests can build
 * the exact production route table and assert ordering — a catch-all pattern
 * registered before a literal one silently shadows it.
 */
final class Routes
{
    public static function register(Router $router): void
    {
        // Auth endpoints
        $router->add('POST', '/api/auth/login', function () {
            return AuthController::login();
        });
        $router->add('POST', '/api/auth/logout', function () {
            return AuthController::logout();
        });
        $router->add('GET', '/api/auth/status', function () {
            return AuthController::status();
        });

        // API: patient list
        $router->add('GET', '/api/patients', function () {
            return PatientController::list();
        });

        // API: resource payload preview (MOST SPECIFIC first)
        $router->add('GET', '/api/patients/{noRawat:any}/resources/{resource}', function (array $params) {
            return ResourceController::preview($params['noRawat'], $params['resource']);
        });

        // API: send selected resources via Bundle
        $router->add('POST', '/api/patients/{noRawat:any}/send', function (array $params) {
            return SendController::sendBundle($params['noRawat']);
        });

        // API: patient detail with available resources
        $router->add('GET', '/api/patients/{noRawat:any}', function (array $params) {
            return PatientController::detail($params['noRawat']);
        });

        // API: audit log
        // NOTE: stats/export must be registered BEFORE {id} — the catch-all would
        // otherwise shadow them (matched first).
        $router->add('GET', '/api/audit/stats', function () {
            return AuditController::stats();
        });
        $router->add('GET', '/api/audit/export', function () {
            return AuditController::export();
        });
        $router->add('GET', '/api/audit/{id}', function (array $params) {
            return AuditController::detail((int) $params['id']);
        });
        $router->add('GET', '/api/audit', function () {
            return AuditController::list();
        });

        // API: Satu Sehat credential settings
        $router->add('GET', '/api/settings', function () {
            return SettingsController::get();
        });
        $router->add('POST', '/api/settings', function () {
            return SettingsController::save();
        });

        // Dynamic Modular Architecture Routes: /api/modules, /api/modules/{id}/*
        ModuleManager::registerRoutes($router);
    }
}