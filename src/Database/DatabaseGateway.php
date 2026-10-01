<?php

declare(strict_types=1);

namespace EidCloud\HeadlessAdmin\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Lightweight PDO database gateway (zero heavy ORM bloat).
 * Supports SQLite, MySQL, and PostgreSQL with consistent prepared statements.
 */
class DatabaseGateway
{
    private PDO $pdo;

    public function __construct(PDO|string $connection = 'sqlite::memory:', ?string $user = null, ?string $pass = null, array $options = [])
    {
        if ($connection instanceof PDO) {
            $this->pdo = $connection;
        } else {
            $defaultOptions = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            try {
                $this->pdo = new PDO($connection, $user, $pass, $options + $defaultOptions);
            } catch (PDOException $e) {
                throw new RuntimeException("Database connection failed: " . $e->getMessage(), (int) $e->getCode(), $e);
            }
        }
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function getDriver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Escape identifier (table or column name) safely depending on driver.
     */
    public function quoteIdentifier(string $identifier): string
    {
        $sanitized = str_replace(['"', '`', '[', ']', ';', ' '], '', $identifier);
        $driver = $this->getDriver();
        if ($driver === 'mysql') {
            return "`{$sanitized}`";
        }
        return "\"{$sanitized}\"";
    }

    /**
     * Execute SQL and fetch all rows.
     *
     * @param string $sql
     * @param array<string|int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Execute SQL and fetch single row.
     *
     * @param string $sql
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res !== false ? $res : null;
    }

    /**
     * Execute DDL or DML statement and return affected rows.
     *
     * @param string $sql
     * @param array<string|int, mixed> $params
     * @return int
     */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Insert a record and return last inserted ID.
     *
     * @param string $table
     * @param array<string, mixed> $data
     * @return string|int
     */
    public function insert(string $table, array $data): string|int
    {
        if (empty($data)) {
            throw new RuntimeException("Cannot insert empty data into {$table}");
        }

        $fields = [];
        $placeholders = [];
        $params = [];

        foreach ($data as $col => $val) {
            $fields[] = $this->quoteIdentifier($col);
            $ph = ':' . preg_replace('/[^a-zA-Z0-9_]/', '', $col);
            $placeholders[] = $ph;
            $params[$ph] = $val;
        }

        $quotedTable = $this->quoteIdentifier($table);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $quotedTable,
            implode(', ', $fields),
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $lastId = $this->pdo->lastInsertId();
        return is_numeric($lastId) && (int)$lastId > 0 ? (int)$lastId : $lastId;
    }

    /**
     * Update records matching criteria.
     *
     * @param string $table
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     * @return int
     */
    public function update(string $table, array $data, array $where): int
    {
        if (empty($data)) {
            return 0;
        }
        if (empty($where)) {
            throw new RuntimeException("Unconditional UPDATE forbidden for safety.");
        }

        $setParts = [];
        $params = [];

        $idx = 0;
        foreach ($data as $col => $val) {
            $ph = ":set_" . $idx++;
            $setParts[] = sprintf('%s = %s', $this->quoteIdentifier($col), $ph);
            $params[$ph] = $val;
        }

        $whereParts = [];
        $wIdx = 0;
        foreach ($where as $col => $val) {
            $ph = ":where_" . $wIdx++;
            $whereParts[] = sprintf('%s = %s', $this->quoteIdentifier($col), $ph);
            $params[$ph] = $val;
        }

        $quotedTable = $this->quoteIdentifier($table);
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $quotedTable,
            implode(', ', $setParts),
            implode(' AND ', $whereParts)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Delete records matching criteria.
     *
     * @param string $table
     * @param array<string, mixed> $where
     * @return int
     */
    public function delete(string $table, array $where): int
    {
        if (empty($where)) {
            throw new RuntimeException("Unconditional DELETE forbidden for safety.");
        }

        $whereParts = [];
        $params = [];
        $idx = 0;

        foreach ($where as $col => $val) {
            $ph = ":del_" . $idx++;
            $whereParts[] = sprintf('%s = %s', $this->quoteIdentifier($col), $ph);
            $params[$ph] = $val;
        }

        $quotedTable = $this->quoteIdentifier($table);
        $sql = sprintf('DELETE FROM %s WHERE %s', $quotedTable, implode(' AND ', $whereParts));

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Inspect tables in current database connection.
     *
     * @return array<string>
     */
    public function listTables(): array
    {
        $driver = $this->getDriver();
        if ($driver === 'sqlite') {
            $rows = $this->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            return array_map(fn($r) => (string)$r['name'], $rows);
        } elseif ($driver === 'mysql') {
            $rows = $this->query("SHOW TABLES");
            return array_map(fn($r) => (string)array_values($r)[0], $rows);
        } elseif ($driver === 'pgsql') {
            $rows = $this->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
            return array_map(fn($r) => (string)$r['tablename'], $rows);
        }
        return [];
    }

    /**
     * Inspect columns for a table.
     *
     * @param string $table
     * @return array<string, array<string, mixed>>
     */
    public function inspectTableColumns(string $table): array
    {
        $driver = $this->getDriver();
        $cols = [];

        if ($driver === 'sqlite') {
            $rows = $this->query("PRAGMA table_info(" . $this->quoteIdentifier($table) . ")");
            foreach ($rows as $row) {
                $name = (string)$row['name'];
                $rawType = strtolower((string)$row['type']);
                $type = 'string';
                if (str_contains($rawType, 'int')) {
                    $type = 'integer';
                } elseif (str_contains($rawType, 'real') || str_contains($rawType, 'float') || str_contains($rawType, 'double') || str_contains($rawType, 'decimal')) {
                    $type = 'float';
                } elseif (str_contains($rawType, 'bool')) {
                    $type = 'boolean';
                } elseif (str_contains($rawType, 'time') || str_contains($rawType, 'date')) {
                    $type = 'datetime';
                } elseif (str_contains($rawType, 'text')) {
                    $type = 'text';
                }

                $cols[$name] = [
                    'name' => $name,
                    'type' => $type,
                    'primary_key' => ((int)$row['pk']) > 0,
                    'not_null' => ((int)$row['notnull']) === 1,
                    'default' => $row['dflt_value'],
                ];
            }
        } else {
            // Generic fallback via SELECT limit 0
            $stmt = $this->pdo->query("SELECT * FROM " . $this->quoteIdentifier($table) . " LIMIT 0");
            $colCount = $stmt->columnCount();
            for ($i = 0; $i < $colCount; $i++) {
                $meta = $stmt->getColumnMeta($i);
                $name = $meta['name'];
                $cols[$name] = [
                    'name' => $name,
                    'type' => 'string',
                    'primary_key' => $name === 'id',
                    'not_null' => false,
                    'default' => null,
                ];
            }
        }

        return $cols;
    }
}
