<?php
/**
 * NexAlert - API Router
 * Lightweight regex-based router. No framework dependency.
 * Supports route parameters, middleware stacks, and versioned prefixes.
 */

declare(strict_types=1);

namespace NexAlert\Api;

use NexAlert\Config\Logger;

class Router
{
    /** @var array<array{method: string, pattern: string, handler: callable, middleware: array}> */
    private array $routes = [];

    /** @var array<callable> Global middleware applied to every route */
    private array $globalMiddleware = [];

    private string $prefix = '';

    /**
     * Add global middleware run before every route handler.
     */
    public function use(callable $middleware): void
    {
        $this->globalMiddleware[] = $middleware;
    }

    /**
     * Register a GET route.
     */
    public function get(string $path, callable $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $handler, $middleware);
    }

    /**
     * Register a POST route.
     */
    public function post(string $path, callable $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $handler, $middleware);
    }

    /**
     * Register a PUT route.
     */
    public function put(string $path, callable $handler, array $middleware = []): void
    {
        $this->addRoute('PUT', $path, $handler, $middleware);
    }

    /**
     * Register a PATCH route.
     */
    public function patch(string $path, callable $handler, array $middleware = []): void
    {
        $this->addRoute('PATCH', $path, $handler, $middleware);
    }

    /**
     * Register a DELETE route.
     */
    public function delete(string $path, callable $handler, array $middleware = []): void
    {
        $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    /**
     * Group routes under a common prefix with shared middleware.
     */
    public function group(string $prefix, callable $callback, array $middleware = []): void
    {
        $previousPrefix = $this->prefix;
        $this->prefix   = $previousPrefix . $prefix;

        // Temporarily inject group middleware into global stack for this group
        $groupMiddlewareAdded = [];
        foreach ($middleware as $mw) {
            $this->globalMiddleware[] = $mw;
            $groupMiddlewareAdded[]   = array_key_last($this->globalMiddleware);
        }

        $callback($this);

        // Remove group middleware from global stack
        foreach (array_reverse($groupMiddlewareAdded) as $idx) {
            unset($this->globalMiddleware[$idx]);
        }
        $this->globalMiddleware = array_values($this->globalMiddleware);
        $this->prefix           = $previousPrefix;
    }

    /**
     * Dispatch the current HTTP request.
     */
    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri    = $this->normalizeUri($_SERVER['REQUEST_URI'] ?? '/');

        // Handle CORS preflight
        if ($method === 'OPTIONS') {
            $this->sendCorsHeaders();
            http_response_code(204);
            exit;
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method && $route['method'] !== 'ANY') {
                continue;
            }

            $params = $this->matchRoute($route['pattern'], $uri);
            if ($params === null) {
                continue;
            }

            $request = new Request($method, $uri, $params);

            try {
                // Build middleware stack: global + route-specific
                $middleware = array_merge($this->globalMiddleware, $route['middleware']);
                $this->runMiddleware($middleware, $request, $route['handler']);
            } catch (\Throwable $e) {
                $this->handleException($e);
            }

            return;
        }

        // No route matched
        Response::json(['error' => 'Not Found', 'path' => $uri], 404);
    }

    /**
     * Run middleware chain then the route handler.
     */
    private function runMiddleware(array $middleware, Request $request, callable $handler): void
    {
        $next = $handler;

        foreach (array_reverse($middleware) as $mw) {
            $nextCapture = $next;
            $next = function (Request $req) use ($mw, $nextCapture): void {
                $mw($req, $nextCapture);
            };
        }

        $next($request);
    }

    /**
     * Convert path pattern to regex and extract named params.
     * Pattern: /api/v1/users/{id} → regex with named capture group 'id'
     * Returns null on no match, or array of captured params.
     */
    private function matchRoute(string $pattern, string $uri): ?array
    {
        // Convert {param} and {param:\d+} to named capture groups
        $regex = preg_replace_callback('/\{(\w+)(?::([^}]+))?\}/', function ($m) {
            $name    = $m[1];
            $pattern = $m[2] ?? '[^/]+';
            return "(?P<{$name}>{$pattern})";
        }, $pattern);

        $regex = '@^' . $regex . '$@';

        if (!preg_match($regex, $uri, $matches)) {
            return null;
        }

        // Return only named captures
        return array_filter($matches, fn($k) => is_string($k), ARRAY_FILTER_USE_KEY);
    }

    private function addRoute(string $method, string $path, callable $handler, array $middleware): void
    {
        $this->routes[] = [
            'method'     => $method,
            'pattern'    => $this->prefix . $path,
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    private function normalizeUri(string $uri): string
    {
        // Strip query string
        $uri = strtok($uri, '?') ?: '/';
        // Ensure leading slash, strip trailing slash (except root)
        $uri = '/' . trim($uri, '/');
        return $uri === '/' ? '/' : rtrim($uri, '/');
    }

    private function sendCorsHeaders(): void
    {
        header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');
    }

    private function handleException(\Throwable $e): void
    {
        Logger::error('Unhandled exception in router', [
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'trace'   => $e->getTraceAsString(),
        ]);

        $isDev = \NexAlert\Config\Env::isDevelopment();

        Response::json([
            'error'   => 'Internal Server Error',
            'message' => $isDev ? $e->getMessage() : 'An unexpected error occurred.',
            'trace'   => $isDev ? explode("\n", $e->getTraceAsString()) : null,
        ], 500);
    }
}
