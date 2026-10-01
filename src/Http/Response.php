<?php

declare(strict_types=1);

namespace EidCloud\HeadlessAdmin\Http;

use JsonSerializable;

/**
 * Clean HTTP Response generator.
 */
class Response implements JsonSerializable
{
    /**
     * @param int $statusCode
     * @param array<string, mixed>|string $data
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $statusCode = 200,
        public readonly mixed $data = [],
        public readonly array $headers = []
    ) {
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        return new self($status, $data, $headers);
    }

    public static function html(string $html, int $status = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'text/html; charset=utf-8';
        return new self($status, $html, $headers);
    }

    public static function csv(string $csvContent, string $filename = 'export.csv', int $status = 200): self
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];
        return new self($status, $csvContent, $headers);
    }

    public static function error(string $message, int $status = 400, array $errors = []): self
    {
        $payload = [
            'success' => false,
            'status' => $status,
            'message' => $message,
        ];
        if (!empty($errors)) {
            $payload['errors'] = $errors;
        }

        return self::json($payload, $status);
    }

    public static function success(mixed $data, string $message = 'Success', int $status = 200, array $meta = []): self
    {
        $payload = [
            'success' => true,
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ];
        if (!empty($meta)) {
            $payload['meta'] = $meta;
        }

        return self::json($payload, $status);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->statusCode);
            foreach ($this->headers as $header => $val) {
                header("{$header}: {$val}");
            }
        }

        if (is_array($this->data) || is_object($this->data)) {
            echo json_encode($this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } else {
            echo $this->data;
        }
    }

    public function jsonSerialize(): mixed
    {
        return $this->data;
    }
}
