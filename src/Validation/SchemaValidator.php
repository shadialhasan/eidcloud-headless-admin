<?php

declare(strict_types=1);

namespace EidCloud\HeadlessAdmin\Validation;

use EidCloud\HeadlessAdmin\Database\DatabaseGateway;
use EidCloud\HeadlessAdmin\Metadata\ModuleSchema;

/**
 * Dynamic schema validator enforcing required, min, max, regex, unique, enum.
 */
class SchemaValidator
{
    public function __construct(
        private ?DatabaseGateway $db = null
    ) {
    }

    /**
     * Validate an input dataset against module schema.
     *
     * @param ModuleSchema $module
     * @param array<string, mixed> $data
     * @param bool $isUpdate True if partial update (PUT/PATCH), false for create (POST)
     * @param mixed $recordId Current record ID when updating (to exclude self from unique check)
     * @return array<string, array<string>> Map of field name to array of error messages
     */
    public function validate(ModuleSchema $module, array $data, bool $isUpdate = false, mixed $recordId = null): array
    {
        $errors = [];

        foreach ($module->fields as $fieldName => $field) {
            // Auto increment or primary keys on insert are skipped if not present
            if ($field->autoIncrement && !$isUpdate && !array_key_exists($fieldName, $data)) {
                continue;
            }

            $hasValue = array_key_exists($fieldName, $data);
            $val = $hasValue ? $data[$fieldName] : null;
            $rules = $field->validation;

            // 1. Required check
            $isRequired = !empty($rules['required']);
            if (!$isUpdate && $isRequired && ($val === null || $val === '')) {
                $errors[$fieldName][] = "Field '{$fieldName}' is required.";
                continue;
            }

            // On update, only validate if field is provided
            if ($isUpdate && !$hasValue) {
                continue;
            }

            if ($val === null || $val === '') {
                // If not required and value is empty, skip further format checks
                continue;
            }

            // 2. Type coercion check
            switch ($field->type) {
                case 'integer':
                    if (!is_int($val) && !ctype_digit((string)$val) && !preg_match('/^-?\d+$/', (string)$val)) {
                        $errors[$fieldName][] = "Field '{$fieldName}' must be an integer.";
                    }
                    break;
                case 'float':
                    if (!is_numeric($val)) {
                        $errors[$fieldName][] = "Field '{$fieldName}' must be a number.";
                    }
                    break;
                case 'boolean':
                    if (!is_bool($val) && !in_array($val, [0, 1, '0', '1', 'true', 'false'], true)) {
                        $errors[$fieldName][] = "Field '{$fieldName}' must be a boolean.";
                    }
                    break;
            }

            // 3. Min rule (length or numeric value)
            if (isset($rules['min'])) {
                $min = $rules['min'];
                if (is_numeric($val) && in_array($field->type, ['integer', 'float'], true)) {
                    if ($val < $min) {
                        $errors[$fieldName][] = "Field '{$fieldName}' must be at least {$min}.";
                    }
                } elseif (is_string($val)) {
                    if (mb_strlen($val) < $min) {
                        $errors[$fieldName][] = "Field '{$fieldName}' length must be at least {$min} characters.";
                    }
                }
            }

            // 4. Max rule (length or numeric value)
            if (isset($rules['max'])) {
                $max = $rules['max'];
                if (is_numeric($val) && in_array($field->type, ['integer', 'float'], true)) {
                    if ($val > $max) {
                        $errors[$fieldName][] = "Field '{$fieldName}' must not exceed {$max}.";
                    }
                } elseif (is_string($val)) {
                    if (mb_strlen($val) > $max) {
                        $errors[$fieldName][] = "Field '{$fieldName}' length must not exceed {$max} characters.";
                    }
                }
            }

            // 5. Enum rule
            if (!empty($rules['enum']) && is_array($rules['enum'])) {
                if (!in_array($val, $rules['enum'], true) && !in_array((string)$val, array_map('strval', $rules['enum']), true)) {
                    $allowed = implode(', ', $rules['enum']);
                    $errors[$fieldName][] = "Field '{$fieldName}' must be one of: {$allowed}.";
                }
            }

            // 6. Regex rule
            if (!empty($rules['regex'])) {
                $pattern = (string)$rules['regex'];
                // Check if pattern has delimiters, if not wrap with /
                if (!preg_match('/^([\/#~%]).*\1[imsxADSUXJu]*$/', $pattern)) {
                    $pattern = '/' . str_replace('/', '\/', $pattern) . '/';
                }
                if (@preg_match($pattern, (string)$val) !== 1) {
                    $errors[$fieldName][] = "Field '{$fieldName}' does not match required pattern.";
                }
            }

            // 7. Unique rule
            if (!empty($rules['unique']) && $this->db !== null) {
                $pkCol = $module->primaryKey;
                $table = $module->table;
                
                $sql = "SELECT COUNT(*) as cnt FROM " . $this->db->quoteIdentifier($table) . " WHERE " . $this->db->quoteIdentifier($fieldName) . " = :val";
                $params = [':val' => $val];

                if ($isUpdate && $recordId !== null) {
                    $sql .= " AND " . $this->db->quoteIdentifier($pkCol) . " != :pk";
                    $params[':pk'] = $recordId;
                }

                $res = $this->db->queryOne($sql, $params);
                if ($res && (int)$res['cnt'] > 0) {
                    $errors[$fieldName][] = "Value for '{$fieldName}' must be unique.";
                }
            }
        }

        return $errors;
    }
}
