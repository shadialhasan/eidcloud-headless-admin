<?php

declare(strict_types=1);

namespace EidCloud\HeadlessAdmin\Tests;

use EidCloud\HeadlessAdmin\AdminEngine;
use EidCloud\HeadlessAdmin\Database\DatabaseGateway;
use EidCloud\HeadlessAdmin\Http\Request;
use EidCloud\HeadlessAdmin\Metadata\ModuleSchema;
use EidCloud\HeadlessAdmin\Metadata\SchemaRegistry;
use EidCloud\HeadlessAdmin\Security\RbacManager;
use EidCloud\HeadlessAdmin\Validation\SchemaValidator;
use PDO;
use RuntimeException;

class HeadlessAdminTest
{
    private DatabaseGateway $db;
    private SchemaRegistry $registry;
    private AdminEngine $engine;

    public function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Setup tables
        $pdo->exec("
            CREATE TABLE customers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                status TEXT NOT NULL DEFAULT 'active',
                credit_balance REAL DEFAULT 0.0,
                internal_notes TEXT
            );
        ");

        $pdo->exec("
            CREATE TABLE products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                sku TEXT NOT NULL UNIQUE,
                price REAL NOT NULL,
                inventory_count INTEGER NOT NULL DEFAULT 0
            );
        ");

        $this->db = new DatabaseGateway($pdo);

        $schemaFile = dirname(__DIR__) . '/examples/schema.json';
        $this->registry = SchemaRegistry::fromFile($schemaFile);
        $this->engine = new AdminEngine($this->registry, $this->db);
    }

    public function runAll(): array
    {
        $this->setUp();

        $tests = [
            'testSchemaParsingAndRegistry',
            'testDatabaseGatewayCrud',
            'testSchemaValidationRules',
            'testModuleLevelRbac',
            'testFieldLevelRbacRead',
            'testFieldLevelRbacWrite',
            'testEngineRestfulCrudLifecycle',
            'testEngineFilteringAndSearch',
            'testEnginePaginationAndSorting',
            'testEngineExportCsv',
            'testMicroAdminUiRendering',
            'testCliTableInspection',
        ];

        $results = [];
        foreach ($tests as $test) {
            try {
                $this->$test();
                $results[$test] = ['status' => 'PASS'];
            } catch (\Throwable $e) {
                $results[$test] = [
                    'status' => 'FAIL',
                    'message' => $e->getMessage(),
                    'trace' => $e->getFile() . ':' . $e->getLine()
                ];
            }
        }

        return $results;
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException("Assertion failed: {$message}");
        }
    }

    public function testSchemaParsingAndRegistry(): void
    {
        $customerModule = $this->registry->getModule('customers');
        $this->assert($customerModule !== null, "Module 'customers' should exist");
        $this->assert($customerModule->table === 'customers', "Table name should match");
        $this->assert($customerModule->getField('email') !== null, "Field 'email' should exist");
        $this->assert($customerModule->getField('email')->validation['required'] === true, "Email required rule");
        $this->assert(in_array('name', $customerModule->getSearchableFields(), true), "Name field is searchable");
    }

    public function testDatabaseGatewayCrud(): void
    {
        $id = $this->db->insert('customers', [
            'name' => 'Alice Johnson',
            'email' => 'alice@example.com',
            'status' => 'active',
            'credit_balance' => 150.50,
        ]);

        $this->assert(is_numeric($id) && (int)$id > 0, "Insert returned valid ID");

        $record = $this->db->queryOne("SELECT * FROM customers WHERE id = :id", [':id' => $id]);
        $this->assert($record !== null, "Record must be found");
        $this->assert($record['name'] === 'Alice Johnson', "Name must match");

        $affected = $this->db->update('customers', ['status' => 'suspended'], ['id' => $id]);
        $this->assert($affected === 1, "One row updated");

        $updated = $this->db->queryOne("SELECT status FROM customers WHERE id = :id", [':id' => $id]);
        $this->assert($updated['status'] === 'suspended', "Status updated to suspended");

        $del = $this->db->delete('customers', ['id' => $id]);
        $this->assert($del === 1, "One row deleted");

        $deleted = $this->db->queryOne("SELECT * FROM customers WHERE id = :id", [':id' => $id]);
        $this->assert($deleted === null, "Record was deleted");
    }

    public function testSchemaValidationRules(): void
    {
        $validator = new SchemaValidator($this->db);
        $module = $this->registry->getModule('customers');

        // Test 1: Required missing
        $errors = $validator->validate($module, ['email' => 'test@example.com']);
        $this->assert(isset($errors['name']), "Missing required name should produce validation error");

        // Test 2: Min length
        $errors = $validator->validate($module, ['name' => 'A', 'email' => 'valid@domain.com']);
        $this->assert(isset($errors['name']), "Name shorter than min length must fail");

        // Test 3: Regex email
        $errors = $validator->validate($module, ['name' => 'John Doe', 'email' => 'invalid-email']);
        $this->assert(isset($errors['email']), "Malformed email regex failure");

        // Test 4: Enum
        $errors = $validator->validate($module, [
            'name' => 'John Doe',
            'email' => 'john@test.com',
            'status' => 'nonexistent_status'
        ]);
        $this->assert(isset($errors['status']), "Invalid enum value must fail");

        // Test 5: Unique check
        $this->db->insert('customers', ['name' => 'Existing', 'email' => 'taken@test.com']);
        $errors = $validator->validate($module, [
            'name' => 'Another',
            'email' => 'taken@test.com'
        ]);
        $this->assert(isset($errors['email']), "Duplicate unique email must fail");
    }

    public function testModuleLevelRbac(): void
    {
        $rbac = new RbacManager();
        $module = $this->registry->getModule('customers');

        // Customer delete is restricted to 'admin'
        $this->assert($rbac->canAccessModule(['admin'], $module, 'delete') === true, "Admin can delete");
        $this->assert($rbac->canAccessModule(['manager'], $module, 'delete') === false, "Manager cannot delete");
        $this->assert($rbac->canAccessModule(['guest'], $module, 'delete') === false, "Guest cannot delete");

        // Customer list is open to '*'
        $this->assert($rbac->canAccessModule(['guest'], $module, 'list') === true, "Guest can list");
    }

    public function testFieldLevelRbacRead(): void
    {
        $rbac = new RbacManager();
        $module = $this->registry->getModule('customers');

        $record = [
            'id' => 1,
            'name' => 'Bob',
            'email' => 'bob@test.com',
            'credit_balance' => 99.0,
            'internal_notes' => 'Confidential client data',
        ];

        // Guest sees public fields, but neither credit_balance nor internal_notes
        $guestView = $rbac->filterReadableFields(['guest'], $module, $record);
        $this->assert(isset($guestView['name']), "Guest sees name");
        $this->assert(!isset($guestView['credit_balance']), "Guest cannot see credit_balance");
        $this->assert(!isset($guestView['internal_notes']), "Guest cannot see internal_notes");

        // Manager sees credit_balance but not internal_notes
        $managerView = $rbac->filterReadableFields(['manager'], $module, $record);
        $this->assert(isset($managerView['credit_balance']), "Manager sees credit_balance");
        $this->assert(!isset($managerView['internal_notes']), "Manager cannot see internal_notes");

        // Admin sees all
        $adminView = $rbac->filterReadableFields(['admin'], $module, $record);
        $this->assert(isset($adminView['internal_notes']), "Admin sees internal_notes");
    }

    public function testFieldLevelRbacWrite(): void
    {
        $rbac = new RbacManager();
        $module = $this->registry->getModule('customers');

        $input = [
            'name' => 'Charlie',
            'email' => 'charlie@test.com',
            'status' => 'suspended',
            'credit_balance' => 500.0,
            'internal_notes' => 'Hacked balance',
        ];

        // Manager can write name, email, status, but NOT credit_balance or internal_notes
        $managerInput = $rbac->filterWritableFields(['manager'], $module, $input);
        $this->assert(isset($managerInput['name']), "Manager can write name");
        $this->assert(isset($managerInput['status']), "Manager can write status");
        $this->assert(!isset($managerInput['credit_balance']), "Manager cannot write credit_balance");
        $this->assert(!isset($managerInput['internal_notes']), "Manager cannot write internal_notes");
    }

    public function testEngineRestfulCrudLifecycle(): void
    {
        // 1. CREATE record via POST
        $createReq = new Request(
            method: 'POST',
            path: '/api/customers',
            body: [
                'name' => 'Enterprise Corp',
                'email' => 'contact@enterprise.com',
                'status' => 'active',
                'credit_balance' => 1250.0,
                'internal_notes' => 'VIP Customer',
            ],
            headers: ['x-role' => 'admin']
        );
        $createRes = $this->engine->handle($createReq);
        $this->assert($createRes->statusCode === 200, "Create succeeded with 200/201");
        $createdData = $createRes->data['data'];
        $this->assert($createdData['name'] === 'Enterprise Corp', "Created name matched");
        $id = $createdData['id'];

        // 2. READ single record via GET
        $readReq = new Request('GET', "/api/customers/{$id}", headers: ['x-role' => 'admin']);
        $readRes = $this->engine->handle($readReq);
        $this->assert($readRes->statusCode === 200, "Read single record ok");
        $this->assert($readRes->data['data']['email'] === 'contact@enterprise.com', "Read matched email");

        // 3. UPDATE record via PUT
        $updateReq = new Request(
            method: 'PUT',
            path: "/api/customers/{$id}",
            body: ['name' => 'Enterprise International Corp'],
            headers: ['x-role' => 'admin']
        );
        $updateRes = $this->engine->handle($updateReq);
        $this->assert($updateRes->statusCode === 200, "Update status ok");
        $this->assert($updateRes->data['data']['name'] === 'Enterprise International Corp', "Updated name reflected");

        // 4. DELETE record via DELETE
        $deleteReq = new Request('DELETE', "/api/customers/{$id}", headers: ['x-role' => 'admin']);
        $deleteRes = $this->engine->handle($deleteReq);
        $this->assert($deleteRes->statusCode === 200, "Delete record ok");

        // 5. Verify 404 after delete
        $readAgain = $this->engine->handle(new Request('GET', "/api/customers/{$id}", headers: ['x-role' => 'admin']));
        $this->assert($readAgain->statusCode === 404, "Deleted record returns 404");
    }

    public function testEngineFilteringAndSearch(): void
    {
        $this->db->insert('customers', ['name' => 'David Alpha', 'email' => 'david@alpha.com', 'status' => 'active']);
        $this->db->insert('customers', ['name' => 'Diana Beta', 'email' => 'diana@beta.com', 'status' => 'suspended']);
        $this->db->insert('customers', ['name' => 'Edward Gamma', 'email' => 'edward@gamma.com', 'status' => 'active']);

        // Search for 'Alpha'
        $searchReq = new Request('GET', '/api/customers', queryParams: ['search' => 'Alpha', 'role' => 'admin']);
        $res = $this->engine->handle($searchReq);
        $items = $res->data['data'];
        $this->assert(count($items) === 1, "Search returns 1 record");
        $this->assert($items[0]['name'] === 'David Alpha', "Matched David Alpha");

        // Filter by status=suspended
        $filterReq = new Request('GET', '/api/customers', queryParams: ['status' => 'suspended', 'role' => 'admin']);
        $res = $this->engine->handle($filterReq);
        $items = $res->data['data'];
        $this->assert(count($items) === 1, "Filter returns 1 suspended record");
        $this->assert($items[0]['name'] === 'Diana Beta', "Matched Diana Beta");
    }

    public function testEnginePaginationAndSorting(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $this->db->insert('customers', [
                'name' => "User " . sprintf('%02d', $i),
                'email' => "user{$i}@example.com",
                'status' => 'active'
            ]);
        }

        $pageReq = new Request('GET', '/api/customers', queryParams: [
            'page' => 2,
            'per_page' => 5,
            'sort' => 'id',
            'order' => 'ASC',
            'role' => 'admin'
        ]);

        $res = $this->engine->handle($pageReq);
        $this->assert($res->statusCode === 200, "Pagination request success");
        $this->assert(count($res->data['data']) === 5, "Page size is 5");
        $this->assert($res->data['meta']['pagination']['current_page'] === 2, "Current page is 2");
        $this->assert($res->data['meta']['pagination']['total_records'] >= 15, "Total records is at least 15");
    }

    public function testEngineExportCsv(): void
    {
        $exportReq = new Request('GET', '/api/customers/export', headers: ['x-role' => 'admin']);
        $res = $this->engine->handle($exportReq);

        $this->assert($res->statusCode === 200, "Export returns 200");
        $this->assert(str_contains($res->headers['Content-Type'], 'text/csv'), "Content-Type is text/csv");
        $this->assert(str_contains((string)$res->data, 'name'), "CSV header contains 'name'");
    }

    public function testMicroAdminUiRendering(): void
    {
        $uiReq = new Request('GET', '/admin');
        $res = $this->engine->handle($uiReq);
        $this->assert($res->statusCode === 200, "UI endpoint returns 200");
        $this->assert(str_contains((string)$res->data, 'EidCloud Headless Admin'), "UI HTML contains branding");
        $this->assert(str_contains((string)$res->data, 'Customer Accounts'), "UI HTML contains module title");
    }

    public function testCliTableInspection(): void
    {
        $tables = $this->db->listTables();
        $this->assert(in_array('customers', $tables, true), "Database contains 'customers' table");
        $cols = $this->db->inspectTableColumns('customers');
        $this->assert(isset($cols['email']), "Column inspection discovered 'email'");
        $this->assert($cols['email']['type'] === 'text' || $cols['email']['type'] === 'string', "Column type identified");
    }
}
