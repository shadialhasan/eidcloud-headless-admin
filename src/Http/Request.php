<?php

declare(strict_types=1);

namespace EidCloud\HeadlessAdmin\Http;

use JsonSerializable;

/**
 * Standard HTTP Request representation without external dependencies.
 */
class Request
{
    /**
     * @param string $method HTTP method (GET, POST, PUT, PATCH, DELETE, OPTIONS, etc.)
     * @param string $path URL path e.g. /api/customers
     * @param array<string, mixed> $queryParams $_GET parameters
     * @param array<string, mixed> $body Parsed JSON or form body
     * @param array<string, string> $headers Request headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $queryParams = [],
        public readonly array $body = [],
        public readonly array $headers = []
    ) {
    }

    /**
     * Create Request instance from PHP globals.
     */
    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$headerName] = (string) $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headerName = strtolower(str_replace('_', '-', $key));
                $headers[$headerName] = (string) $value;
            }
        }

        $rawBody = file_get_contents('php://input');
        $body = [];
        if ($rawBody !== false && $rawBody !== '') {
            $parsed = json_decode($rawBody, true);
            if (is_array($parsed)) {
                $body = $parsed;
            } else {
                $body = $_POST;
            }
        } else {
            $body = $_POST;
        }

        return new self($method, $path, $_GET, $body, $headers);
    }

    public function getHeader(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function getQuery(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }

    public function getBodyParam(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function getRoles(): array
    {
        $rolesHeader = $this->getHeader('x-user-roles') ?? $this->getHeader('x-role');
        if ($rolesHeader) {
            $roles = array_map('trim', explode(',', $rolesHeader));
            return array_filter($roles);
        }

        $qRole = $this->getQuery('role');
        if ($qRole) {
            return is_array($qRole) ? $qRole : array_map('trim', explode(',', (string)$qRole));
        }

        return ['guest'];
    }
}
