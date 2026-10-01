<?php

declare(strict_types=1);

namespace EidCloud\HeadlessAdmin;

use EidCloud\HeadlessAdmin\Crud\CrudHandler;
use EidCloud\HeadlessAdmin\Database\DatabaseGateway;
use EidCloud\HeadlessAdmin\Http\Request;
use EidCloud\HeadlessAdmin\Http\Response;
use EidCloud\HeadlessAdmin\Metadata\ModuleSchema;
use EidCloud\HeadlessAdmin\Metadata\SchemaRegistry;
use EidCloud\HeadlessAdmin\Security\RbacManager;
use EidCloud\HeadlessAdmin\Ui\MicroAdminUi;
use EidCloud\HeadlessAdmin\Validation\SchemaValidator;
use PDO;
use Throwable;

/**
 * Main Headless Admin Engine & Router.
 */
class AdminEngine
{
    private DatabaseGateway $db;
    private RbacManager $rbac;
    private SchemaValidator $validator;
    private CrudHandler $crud;
    private string $apiPrefix = '/api';

    public function __construct(
        private SchemaRegistry $registry,
        PDO|DatabaseGateway|string $database = 'sqlite::memory:',
        ?RbacManager $rbac = null,
        string $apiPrefix = '/api'
    ) {
        if ($database instanceof DatabaseGateway) {
            $this->db = $database;
        } else {
            $this->db = new DatabaseGateway($database);
        }

        $this->rbac = $rbac ?? new RbacManager();
        $this->validator = new SchemaValidator($this->db);
        $this->crud = new CrudHandler($this->db, $this->rbac, $this->validator);
        $this->apiPrefix = rtrim($apiPrefix, '/');
    }

    public function getRegistry(): SchemaRegistry
    {
        return $this->registry;
    }

    public function getDatabase(): DatabaseGateway
    {
        return $this->db;
    }

    public function getRbac(): RbacManager
    {
        return $this->rbac;
    }

    public function getCrud(): CrudHandler
    {
        return $this->crud;
    }

    /**
     * Dispatch an incoming HTTP request.
     */
    public function handle(?Request $request = null): Response
    {
        $req = $request ?? Request::fromGlobals();
        $path = $req->path;

        // 1. CORS Pre-flight check
        if ($req->method === 'OPTIONS') {
            return new Response(204, '', [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-User-Roles, X-Role',
            ]);
        }

        try {
            // 2. Micro Admin UI route
            if ($path === '/' || $path === '/admin' || $path === '/admin/') {
                $html = MicroAdminUi::render($this->registry, $this->apiPrefix);
                return Response::html($html);
            }

            // 3. Schema Discovery endpoint: /api or /api/_schema
            if ($path === $this->apiPrefix || $path === $this->apiPrefix . '/' || $path === $this->apiPrefix . '/_schema') {
                return Response::success([
                    'version' => '1.0.0',
                    'engine' => 'EidCloud Headless Admin',
                    'modules' => $this->registry->toArray()['modules'],
                ]);
            }

            // 4. API routes: /api/{module} or /api/{module}/{id} or /api/{module}/export
            if (!str_starts_with($path, $this->apiPrefix . '/')) {
                return Response::error('Route not found', 404);
            }

            $subPath = substr($path, strlen($this->apiPrefix) + 1);
            $segments = explode('/', trim($subPath, '/'));

            $moduleId = $segments[0] ?? '';
            $module = $this->registry->getModule($moduleId);

            if (!$module) {
                return Response::error("Module '{$moduleId}' not registered", 404);
            }

            // Check if sub-route is export: /api/{module}/export
            if (isset($segments[1]) && $segments[1] === 'export' && $req->method === 'GET') {
                return $this->crud->export($module, $req);
            }

            $id = $segments[1] ?? null;

            // Route matching
            return match ($req->method) {
                'GET' => $id !== null ? $this->crud->read($module, $id, $req) : $this->crud->list($module, $req),
                'POST' => $this->crud->create($module, $req),
                'PUT', 'PATCH' => $id !== null ? $this->crud->update($module, $id, $req) : Response::error('ID required for update', 400),
                'DELETE' => $id !== null ? $this->crud->delete($module, $id, $req) : Response::error('ID required for delete', 400),
                default => Response::error("Method {$req->method} not allowed", 405),
            };
        } catch (Throwable $e) {
            return Response::error($e->getMessage(), 500, [
                'trace' => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }
}
