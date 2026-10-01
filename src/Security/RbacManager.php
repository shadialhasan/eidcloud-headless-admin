<?php

declare(strict_types=1);

namespace EidCloud\HeadlessAdmin\Security;

use EidCloud\HeadlessAdmin\Metadata\FieldSchema;
use EidCloud\HeadlessAdmin\Metadata\ModuleSchema;

/**
 * Dynamic Role-Based Access Control (RBAC) manager.
 * Supports module-level actions (list, read, create, update, delete, export)
 * and field-level permissions (read, write).
 */
class RbacManager
{
    /**
     * @param array<string, array<string, mixed>> $roleHierarchy Role definitions / hierarchy
     */
    public function __construct(
        private array $roleHierarchy = []
    ) {
    }

    /**
     * Check if user roles have permission to perform an action on a module.
     *
     * @param string|array<string> $roles User's assigned role(s)
     * @param ModuleSchema $module Target module schema
     * @param string $action 'list', 'read', 'create', 'update', 'delete', 'export'
     * @return bool
     */
    public function canAccessModule(string|array $roles, ModuleSchema $module, string $action): bool
    {
        $userRoles = (array) $roles;
        
        // Superadmin bypass
        if (in_array('admin', $userRoles, true) || in_array('superadmin', $userRoles, true)) {
            return true;
        }

        $modulePermissions = $module->permissions;

        // If no permissions defined for this action or module, allow by default
        if (empty($modulePermissions) || !isset($modulePermissions[$action])) {
            return true;
        }

        $allowedRoles = (array) $modulePermissions[$action];
        if (empty($allowedRoles) || in_array('*', $allowedRoles, true)) {
            return true;
        }

        foreach ($userRoles as $role) {
            if (in_array($role, $allowedRoles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filter record fields based on read permissions.
     * Removes restricted fields from the output record.
     *
     * @param string|array<string> $roles
     * @param ModuleSchema $module
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function filterReadableFields(string|array $roles, ModuleSchema $module, array $record): array
    {
        $userRoles = (array) $roles;
        if (in_array('admin', $userRoles, true) || in_array('superadmin', $userRoles, true)) {
            return $record;
        }

        $filtered = [];
        foreach ($record as $field => $val) {
            $fieldSchema = $module->getField($field);
            if ($fieldSchema === null) {
                $filtered[$field] = $val;
                continue;
            }

            if ($this->canAccessField($userRoles, $fieldSchema, 'read')) {
                $filtered[$field] = $val;
            }
        }

        return $filtered;
    }

    /**
     * Filter payload input fields based on write permissions.
     * Prevents unauthorized fields from being created or updated.
     *
     * @param string|array<string> $roles
     * @param ModuleSchema $module
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function filterWritableFields(string|array $roles, ModuleSchema $module, array $input): array
    {
        $userRoles = (array) $roles;
        if (in_array('admin', $userRoles, true) || in_array('superadmin', $userRoles, true)) {
            return $input;
        }

        $filtered = [];
        foreach ($input as $field => $val) {
            $fieldSchema = $module->getField($field);
            if ($fieldSchema === null) {
                // Ignore unknown fields or fields not in schema
                continue;
            }

            if ($this->canAccessField($userRoles, $fieldSchema, 'write')) {
                $filtered[$field] = $val;
            }
        }

        return $filtered;
    }

    /**
     * Check if role can access field for action ('read' or 'write').
     */
    public function canAccessField(array $userRoles, FieldSchema $field, string $action): bool
    {
        if (in_array('admin', $userRoles, true) || in_array('superadmin', $userRoles, true)) {
            return true;
        }

        $permissions = $field->permissions;
        if (empty($permissions) || !isset($permissions[$action])) {
            return true;
        }

        $allowedRoles = (array) $permissions[$action];
        if (empty($allowedRoles) || in_array('*', $allowedRoles, true)) {
            return true;
        }

        foreach ($userRoles as $role) {
            if (in_array($role, $allowedRoles, true)) {
                return true;
            }
        }

        return false;
    }
}
