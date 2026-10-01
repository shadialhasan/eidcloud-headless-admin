<?php

declare(strict_types=1);

namespace EidCloud\HeadlessAdmin\Metadata;

use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;

/**
 * Registry containing multiple module schemas, project configurations, and global auth settings.
 */
class SchemaRegistry implements JsonSerializable
{
    /** @var array<string, ModuleSchema> */
    private array $modules = [];

    /** @var array<string, mixed> */
    private array $config = [];

    public function __construct(array $modules = [], array $config = [])
    {
        foreach ($modules as $module) {
            $this->registerModule($module);
        }
        $this->config = $config;
    }

    public function registerModule(ModuleSchema $schema): void
    {
        $this->modules[$schema->id] = $schema;
    }

    public function hasModule(string $id): bool
    {
        return isset($this->modules[$id]);
    }

    public function getModule(string $id): ?ModuleSchema
    {
        return $this->modules[$id] ?? null;
    }

    /**
     * @return array<string, ModuleSchema>
     */
    public function getModules(): array
    {
        return $this->modules;
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public static function fromFile(string $filePath): self
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("Schema definition file not found: {$filePath}");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException("Unable to read schema file: {$filePath}");
        }

        return self::fromJson($content);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new InvalidArgumentException("Invalid JSON schema: root must be an object or array");
        }

        return self::fromArray($data);
    }

    public static function fromArray(array $data): self
    {
        $registry = new self([], (array) ($data['config'] ?? []));

        // Format 1: { "modules": { "users": {...}, "posts": {...} } }
        // Format 2: { "modules": [ {...}, {...} ] }
        // Format 3: Single module schema directly { "table": "users", "fields": {...} }
        if (isset($data['modules']) && is_array($data['modules'])) {
            foreach ($data['modules'] as $key => $moduleData) {
                if (is_array($moduleData)) {
                    if (!isset($moduleData['id']) && is_string($key)) {
                        $moduleData['id'] = $key;
                    }
                    $registry->registerModule(ModuleSchema::fromArray($moduleData));
                }
            }
        } elseif (isset($data['table']) || isset($data['id'])) {
            $registry->registerModule(ModuleSchema::fromArray($data));
        }

        return $registry;
    }

    public function toArray(): array
    {
        $modules = [];
        foreach ($this->modules as $id => $module) {
            $modules[$id] = $module->toArray();
        }

        return [
            'config' => $this->config,
            'modules' => $modules,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
