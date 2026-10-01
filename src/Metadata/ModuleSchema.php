<?php

declare(strict_types=1);

namespace EidCloud\HeadlessAdmin\Metadata;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Module metadata declaration.
 */
class ModuleSchema implements JsonSerializable
{
    /**
     * @param string $id Unique module identifier (e.g., 'customers')
     * @param string $title Human-friendly title (e.g., 'Customer Accounts')
     * @param string $table Underlying database table name
     * @param array<string, FieldSchema> $fields Field definitions
     * @param string $primaryKey Primary key column name
     * @param array<string, array<string>> $permissions Module-level role permissions e.g. ['list' => ['admin', 'manager'], 'delete' => ['admin']]
     * @param array<string, array<string, mixed>> $relationships Associated relations (belongs_to, has_many)
     * @param array<string, mixed> $options Additional module options
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $table,
        public readonly array $fields,
        public readonly string $primaryKey = 'id',
        public readonly array $permissions = [],
        public readonly array $relationships = [],
        public readonly array $options = []
    ) {
    }

    /**
     * Build schema from an associative array.
     *
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $id = (string) ($data['id'] ?? $data['name'] ?? $data['table'] ?? '');
        if ($id === '') {
            throw new InvalidArgumentException("Module definition must contain an 'id' or 'table'.");
        }

        $table = (string) ($data['table'] ?? $id);
        $title = (string) ($data['title'] ?? ucfirst(str_replace('_', ' ', $id)));
        $rawFields = (array) ($data['fields'] ?? []);
        
        $fields = [];
        $pk = (string) ($data['primary_key'] ?? 'id');

        foreach ($rawFields as $key => $val) {
            $name = is_string($key) ? $key : ($val['name'] ?? '');
            if (!$name) {
                continue;
            }
            if ($val instanceof FieldSchema) {
                $fields[$name] = $val;
            } elseif (is_array($val)) {
                $fields[$name] = FieldSchema::fromArray($name, $val);
            }
            if (!empty($val['primary_key'])) {
                $pk = $name;
            }
        }

        return new self(
            id: $id,
            title: $title,
            table: $table,
            fields: $fields,
            primaryKey: $pk,
            permissions: (array) ($data['permissions'] ?? []),
            relationships: (array) ($data['relationships'] ?? []),
            options: (array) ($data['options'] ?? [])
        );
    }

    /**
     * Load ModuleSchema from JSON string.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return self::fromArray($data);
    }

    /**
     * Get a specific field schema.
     */
    public function getField(string $name): ?FieldSchema
    {
        return $this->fields[$name] ?? null;
    }

    /**
     * Get list of searchable field names.
     *
     * @return array<string>
     */
    public function getSearchableFields(): array
    {
        $searchable = [];
        foreach ($this->fields as $name => $field) {
            if ($field->searchable) {
                $searchable[] = $name;
            }
        }
        return $searchable;
    }

    /**
     * Get list of filterable field names.
     *
     * @return array<string>
     */
    public function getFilterableFields(): array
    {
        $filterable = [];
        foreach ($this->fields as $name => $field) {
            if ($field->filterable) {
                $filterable[] = $name;
            }
        }
        return $filterable;
    }

    public function toArray(): array
    {
        $fieldsArr = [];
        foreach ($this->fields as $key => $field) {
            $fieldsArr[$key] = $field->toArray();
        }

        return [
            'id' => $this->id,
            'title' => $this->title,
            'table' => $this->table,
            'primary_key' => $this->primaryKey,
            'permissions' => $this->permissions,
            'relationships' => $this->relationships,
            'options' => $this->options,
            'fields' => $fieldsArr,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
