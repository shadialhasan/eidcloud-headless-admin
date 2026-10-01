<?php

declare(strict_types=1);

namespace EidCloud\HeadlessAdmin\Metadata;

use InvalidArgumentException;

/**
 * Field metadata representation.
 */
class FieldSchema
{
    /**
     * @param string $name Field column name
     * @param string $type Data type: string, integer, float, boolean, datetime, json, text
     * @param string $label Human readable label
     * @param array<string, mixed> $validation Validation rules (required, min, max, regex, unique, enum)
     * @param array<string, array<string>> $permissions Field-level permissions e.g. ['read' => ['admin', 'manager'], 'write' => ['admin']]
     * @param bool $primaryKey Is this field the primary key
     * @param bool $autoIncrement Is this field auto incrementing
     * @param mixed $default Default value
     * @param bool $filterable Can this field be filtered in list queries
     * @param bool $searchable Can this field be included in global text search
     * @param bool $sortable Can this field be sorted
     * @param array<string, mixed>|null $relation Relationship metadata if foreign key
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type = 'string',
        public readonly string $label = '',
        public readonly array $validation = [],
        public readonly array $permissions = [],
        public readonly bool $primaryKey = false,
        public readonly bool $autoIncrement = false,
        public readonly mixed $default = null,
        public readonly bool $filterable = true,
        public readonly bool $searchable = true,
        public readonly bool $sortable = true,
        public readonly ?array $relation = null
    ) {
    }

    /**
     * Create from array definition.
     *
     * @param string $name
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(string $name, array $data): self
    {
        return new self(
            name: $name,
            type: (string) ($data['type'] ?? 'string'),
            label: (string) ($data['label'] ?? ucfirst(str_replace('_', ' ', $name))),
            validation: (array) ($data['validation'] ?? []),
            permissions: (array) ($data['permissions'] ?? []),
            primaryKey: (bool) ($data['primary_key'] ?? false),
            autoIncrement: (bool) ($data['auto_increment'] ?? false),
            default: $data['default'] ?? null,
            filterable: (bool) ($data['filterable'] ?? true),
            searchable: (bool) ($data['searchable'] ?? ($data['type'] ?? 'string') === 'string' || ($data['type'] ?? '') === 'text'),
            sortable: (bool) ($data['sortable'] ?? true),
            relation: isset($data['relation']) && is_array($data['relation']) ? $data['relation'] : null
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'label' => $this->label,
            'validation' => $this->validation,
            'permissions' => $this->permissions,
            'primary_key' => $this->primaryKey,
            'auto_increment' => $this->autoIncrement,
            'default' => $this->default,
            'filterable' => $this->filterable,
            'searchable' => $this->searchable,
            'sortable' => $this->sortable,
            'relation' => $this->relation,
        ];
    }
}
