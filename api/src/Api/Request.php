<?php
/**
 * NexAlert - Request
 * Immutable HTTP request value object.
 */

declare(strict_types=1);

namespace NexAlert\Api;

class Request
{
    private array $body;
    private array $query;
    private array $headers;

    /** Authenticated user set by AuthMiddleware */
    public ?array $user = null;

    /** System token set by SystemTokenMiddleware */
    public ?array $token = null;

    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly array $params = []
    ) {
        $this->query   = $_GET ?? [];
        $this->headers = $this->parseHeaders();
        $this->body    = $this->parseBody();
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->body;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization');
        if ($auth && str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        return null;
    }

    public function ip(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? 'unknown';
    }

    public function validate(array $required): array
    {
        $missing = [];
        foreach ($required as $field) {
            $value = $this->input($field);
            if ($value === null || $value === '') {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    private function parseBody(): array
    {
        $contentType = $this->header('content-type', '');

        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            if ($raw === '' || $raw === false) {
                return [];
            }
            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }
            return is_array($decoded) ? $decoded : [];
        }

        return $_POST ?? [];
    }

    private function parseHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name           = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        return $headers;
    }
}