<?php
/**
 * NexAlert - Request & Response Helpers
 */

declare(strict_types=1);

namespace NexAlert\Api;

/**
 * Immutable HTTP request value object.
 * Provides typed access to body, query params, headers, and route params.
 */
class Request
{
    private array $body;
    private array $query;
    private array $headers;

    /** Authenticated user set by AuthMiddleware */
    public ?array $user = null;

    /** System token set by TokenMiddleware */
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

    /**
     * Get a route parameter (from URL pattern).
     */
    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * Get a query string parameter.
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Get a parsed body field.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * Get all parsed body fields.
     */
    public function all(): array
    {
        return $this->body;
    }

    /**
     * Get a specific header (case-insensitive).
     */
    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * Get the raw Authorization header value.
     */
    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization');
        if ($auth && str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        return null;
    }

    /**
     * Get the client IP, accounting for Dreamhost proxying.
     */
    public function ip(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? 'unknown';
    }

    /**
     * Validate required fields exist and are non-empty.
     * Returns array of missing field names.
     */
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

        // Form-encoded
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
        // Content-Type and Content-Length are not prefixed with HTTP_
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        return $headers;
    }
}

/**
 * JSON response helper. All API responses go through this.
 */
class Response
{
    /**
     * Send a JSON response and terminate.
     */
    public static function json(mixed $data, int $status = 200, array $headers = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        foreach ($headers as $name => $value) {
            header("{$name}: {$value}");
        }

        echo json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
        exit;
    }

    /**
     * Standard success envelope.
     */
    public static function success(mixed $data = null, string $message = 'OK', int $status = 200): never
    {
        self::json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    /**
     * Standard error envelope.
     */
    public static function error(string $message, int $status = 400, ?array $errors = null): never
    {
        $body = [
            'success' => false,
            'error'   => $message,
        ];
        if ($errors !== null) {
            $body['errors'] = $errors;
        }
        self::json($body, $status);
    }

    /**
     * 401 Unauthorized.
     */
    public static function unauthorized(string $message = 'Unauthorized'): never
    {
        self::error($message, 401);
    }

    /**
     * 403 Forbidden.
     */
    public static function forbidden(string $message = 'Forbidden'): never
    {
        self::error($message, 403);
    }

    /**
     * 404 Not Found.
     */
    public static function notFound(string $message = 'Not Found'): never
    {
        self::error($message, 404);
    }

    /**
     * 422 Validation error with field-level detail.
     */
    public static function validationError(array $errors, string $message = 'Validation failed'): never
    {
        self::error($message, 422, $errors);
    }
}
