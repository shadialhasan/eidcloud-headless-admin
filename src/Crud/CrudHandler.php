<?php

declare(strict_types=1);

namespace EidCloud\HeadlessAdmin\Crud;

use EidCloud\HeadlessAdmin\Database\DatabaseGateway;
use EidCloud\HeadlessAdmin\Http\Request;
use EidCloud\HeadlessAdmin\Http\Response;
use EidCloud\HeadlessAdmin\Metadata\ModuleSchema;
use EidCloud\HeadlessAdmin\Security\RbacManager;
use EidCloud\HeadlessAdmin\Validation\SchemaValidator;
use Exception;

/**
 * Core CRUD operations handler supporting:
 * - List (filtering, text search, sorting, pagination)
 * - Read (single record retrieval by primary key)
 * - Create (input validation, RBAC write filtering, insertion)
 * - Update (partial/full updates, validation, RBAC write filtering)
 * - Delete (permission check, deletion)
 * - Export (CSV generation with field permissions applied)
 */
class CrudHandler
{
    private SchemaValidator $validator;

    public function __construct(
        private DatabaseGateway $db,
        private RbacManager $rbac,
        ?SchemaValidator $validator = null
    ) {
        $this->validator = $validator ?? new SchemaValidator($db);
    }

    /**
     * Handle LIST operation.
     */
    public function list(ModuleSchema $module, Request $request): Response
    {
        $roles = $request->getRoles();
        if (!$this->rbac->canAccessModule($roles, $module, 'list')) {
            return Response::error('Access denied for action [list]', 403);
        }

        $page = max(1, (int) $request->getQuery('page', 1));
        $perPage = max(1, min(100, (int) $request->getQuery('per_page', 20)));
        $offset = ($page - 1) * $perPage;

        $search = (string) $request->getQuery('search', '');
        $sort = (string) $request->getQuery('sort', $module->primaryKey);
        $order = strtoupper((string) $request->getQuery('order', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

        // Validate sort column exists
        if (!$module->getField($sort)) {
            $sort = $module->primaryKey;
        }

        $whereConditions = [];
        $params = [];
        $pIndex = 0;

        // 1. Text search across searchable fields
        if ($search !== '') {
            $searchableFields = $module->getSearchableFields();
            if (!empty($searchableFields)) {
                $searchOr = [];
                $searchParam = ":search_{$pIndex}";
                $params[$searchParam] = "%{$search}%";
                $pIndex++;

                foreach ($searchableFields as $sField) {
                    $searchOr[] = $this->db->quoteIdentifier($sField) . " LIKE {$searchParam}";
                }
                $whereConditions[] = '(' . implode(' OR ', $searchOr) . ')';
            }
        }

        // 2. Specific field filters (e.g. ?status=active)
        $filterableFields = $module->getFilterableFields();
        foreach ($filterableFields as $fField) {
            $filterVal = $request->getQuery($fField);
            if ($filterVal !== null && $filterVal !== '') {
                $fParam = ":filter_{$pIndex}";
                $params[$fParam] = $filterVal;
                $pIndex++;
                $whereConditions[] = $this->db->quoteIdentifier($fField) . " = {$fParam}";
            }
        }

        $tableQuoted = $this->db->quoteIdentifier($module->table);
        $whereSql = !empty($whereConditions) ? ' WHERE ' . implode(' AND ', $whereConditions) : '';

        // Count total
        $countSql = "SELECT COUNT(*) as total FROM {$tableQuoted}{$whereSql}";
        $countRow = $this->db->queryOne($countSql, $params);
        $total = (int) ($countRow['total'] ?? 0);

        // Fetch records
        $sortQuoted = $this->db->quoteIdentifier($sort);
        $selectSql = "SELECT * FROM {$tableQuoted}{$whereSql} ORDER BY {$sortQuoted} {$order} LIMIT {$perPage} OFFSET {$offset}";
        $rawRecords = $this->db->query($selectSql, $params);

        // Filter readable fields based on RBAC
        $records = [];
        foreach ($rawRecords as $record) {
            $records[] = $this->rbac->filterReadableFields($roles, $module, $record);
        }

        $totalPages = (int) ceil($total / $perPage);

        return Response::success($records, 'Records retrieved successfully', 200, [
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_records' => $total,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1,
            ],
            'sort' => [
                'column' => $sort,
                'order' => $order,
            ],
        ]);
    }

    /**
     * Handle READ operation (single record).
     */
    public function read(ModuleSchema $module, mixed $id, Request $request): Response
    {
        $roles = $request->getRoles();
        if (!$this->rbac->canAccessModule($roles, $module, 'read')) {
            return Response::error('Access denied for action [read]', 403);
        }

        $tableQuoted = $this->db->quoteIdentifier($module->table);
        $pkQuoted = $this->db->quoteIdentifier($module->primaryKey);

        $sql = "SELECT * FROM {$tableQuoted} WHERE {$pkQuoted} = :id LIMIT 1";
        $record = $this->db->queryOne($sql, [':id' => $id]);

        if (!$record) {
            return Response::error("Record not found with ID: {$id}", 404);
        }

        $record = $this->rbac->filterReadableFields($roles, $module, $record);

        return Response::success($record, 'Record retrieved successfully');
    }

    /**
     * Handle CREATE operation.
     */
    public function create(ModuleSchema $module, Request $request): Response
    {
        $roles = $request->getRoles();
        if (!$this->rbac->canAccessModule($roles, $module, 'create')) {
            return Response::error('Access denied for action [create]', 403);
        }

        $input = $request->body;
        // Filter out fields user has no write access to
        $filteredInput = $this->rbac->filterWritableFields($roles, $module, $input);

        // Validate
        $errors = $this->validator->validate($module, $filteredInput, isUpdate: false);
        if (!empty($errors)) {
            return Response::error('Validation failed', 422, $errors);
        }

        // Apply defaults for missing fields
        foreach ($module->fields as $fieldName => $field) {
            if (!array_key_exists($fieldName, $filteredInput) && $field->default !== null && !$field->autoIncrement) {
                $filteredInput[$fieldName] = $field->default;
            }
        }

        try {
            $newId = $this->db->insert($module->table, $filteredInput);
            // Fetch created record
            $fetchId = $newId ?: ($filteredInput[$module->primaryKey] ?? null);
            return $this->read($module, $fetchId, $request);
        } catch (Exception $e) {
            return Response::error('Failed to create record: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Handle UPDATE operation.
     */
    public function update(ModuleSchema $module, mixed $id, Request $request): Response
    {
        $roles = $request->getRoles();
        if (!$this->rbac->canAccessModule($roles, $module, 'update')) {
            return Response::error('Access denied for action [update]', 403);
        }

        $tableQuoted = $this->db->quoteIdentifier($module->table);
        $pkQuoted = $this->db->quoteIdentifier($module->primaryKey);

        $exists = $this->db->queryOne("SELECT {$pkQuoted} FROM {$tableQuoted} WHERE {$pkQuoted} = :id LIMIT 1", [':id' => $id]);
        if (!$exists) {
            return Response::error("Record not found with ID: {$id}", 404);
        }

        $input = $request->body;
        $filteredInput = $this->rbac->filterWritableFields($roles, $module, $input);

        // Don't allow changing primary key via update
        unset($filteredInput[$module->primaryKey]);

        if (empty($filteredInput)) {
            return Response::error('No modifiable fields provided', 400);
        }

        // Validate
        $errors = $this->validator->validate($module, $filteredInput, isUpdate: true, recordId: $id);
        if (!empty($errors)) {
            return Response::error('Validation failed', 422, $errors);
        }

        try {
            $this->db->update($module->table, $filteredInput, [$module->primaryKey => $id]);
            return $this->read($module, $id, $request);
        } catch (Exception $e) {
            return Response::error('Failed to update record: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Handle DELETE operation.
     */
    public function delete(ModuleSchema $module, mixed $id, Request $request): Response
    {
        $roles = $request->getRoles();
        if (!$this->rbac->canAccessModule($roles, $module, 'delete')) {
            return Response::error('Access denied for action [delete]', 403);
        }

        $tableQuoted = $this->db->quoteIdentifier($module->table);
        $pkQuoted = $this->db->quoteIdentifier($module->primaryKey);

        $record = $this->db->queryOne("SELECT * FROM {$tableQuoted} WHERE {$pkQuoted} = :id LIMIT 1", [':id' => $id]);
        if (!$record) {
            return Response::error("Record not found with ID: {$id}", 404);
        }

        try {
            $this->db->delete($module->table, [$module->primaryKey => $id]);
            return Response::success(['deleted_id' => $id], 'Record deleted successfully');
        } catch (Exception $e) {
            return Response::error('Failed to delete record: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Handle EXPORT operation (generates CSV stream).
     */
    public function export(ModuleSchema $module, Request $request): Response
    {
        $roles = $request->getRoles();
        if (!$this->rbac->canAccessModule($roles, $module, 'export')) {
            return Response::error('Access denied for action [export]', 403);
        }

        $tableQuoted = $this->db->quoteIdentifier($module->table);
        $sortQuoted = $this->db->quoteIdentifier($module->primaryKey);

        $sql = "SELECT * FROM {$tableQuoted} ORDER BY {$sortQuoted} ASC";
        $records = $this->db->query($sql);

        if (empty($records)) {
            return Response::csv("No records found\n", "{$module->id}_export.csv");
        }

        // Determine accessible columns from first record
        $filteredFirst = $this->rbac->filterReadableFields($roles, $module, $records[0]);
        $columns = array_keys($filteredFirst);

        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, $columns);

        foreach ($records as $record) {
            $filtered = $this->rbac->filterReadableFields($roles, $module, $record);
            $row = [];
            foreach ($columns as $col) {
                $row[] = $filtered[$col] ?? '';
            }
            fputcsv($fp, $row);
        }

        rewind($fp);
        $csv = stream_get_contents($fp) ?: '';
        fclose($fp);

        return Response::csv($csv, "{$module->id}_export.csv");
    }
}
