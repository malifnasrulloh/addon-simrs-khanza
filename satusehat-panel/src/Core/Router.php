<?php

namespace SatusehatPanel\Core;

class Router
{
    private array $routes = [];
    private string $basePath = '';

    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'handler' => $handler,
        ];
    }

    public function setBasePath(string $basePath): void
    {
        $this->basePath = $basePath;
    }

    private ?string $explicitUri = null;

    /**
     * Drop-in mode (root index.php): tell the router the already-resolved
     * API path (e.g. /api/patients). When set, it is used verbatim and the
     * base-path stripping is skipped.
     */
    public function setRequestUri(string $uri): void
    {
        $this->explicitUri = $uri;
    }

    public function dispatch(): void
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // ── CSRF enforcement (the A6 fix) ─────────────────────────────
        // Every state-changing POST needs a session CSRF token, except the
        // initial login (no session token exists before authentication).
        // SameSite=Lax is a secondary defense; this is the primary one.
        if ($requestMethod === 'POST') {
            $uriForCheck = $this->explicitUri ?? $_SERVER['REQUEST_URI'] ?? '/';
            $uriForCheck = strtok($uriForCheck, '?') ?: '/';
            $uriForCheck = rawurldecode($uriForCheck);
            if ($uriForCheck !== '/api/auth/login' && !Auth::validateCsrf()) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => 'CSRF token invalid atau kedaluwarsa. Muat ulang halaman dan coba lagi.']);
                return;
            }
        }

        $match = $this->matchRoute($requestMethod, $this->requestUri());
        if ($match !== null) {
            // Set params globally for the handler
            $_SERVER['ROUTE_PARAMS'] = $match['params'];
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            $response = call_user_func($this->routes[$match['index']]['handler'], $match['params']);
            echo is_string($response) ? $response : json_encode($response);
            return;
        }

        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Route not found']);
    }

    /**
     * Resolve a method+URI to a registered route (first match wins, in
     * registration order). Returns the route index + captured params, or
     * null when nothing matches. Public so tests can assert ordering
     * invariants (literal routes before catch-alls) without dispatching.
     *
     * @return array{index:int,path:string,params:array<string,string>}|null
     */
    public function matchRoute(string $method, string $uri): ?array
    {
        foreach ($this->routes as $i => $route) {
            if ($route['method'] !== strtoupper($method)) {
                continue;
            }
            $params = $this->matchPath($route['path'], $uri);
            if ($params !== null) {
                return ['index' => $i, 'path' => $route['path'], 'params' => $params];
            }
        }
        return null;
    }

    private function requestUri(): string
    {
        if ($this->explicitUri !== null) {
            $requestUri = $this->explicitUri;
        } else {
            $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
            // Remove base path if present
            if ($this->basePath !== '' && str_starts_with($requestUri, $this->basePath)) {
                $requestUri = substr($requestUri, strlen($this->basePath));
            }
        }

        // Always strip query string before route matching
        $queryPos = strpos($requestUri, '?');
        if ($queryPos !== false) {
            $requestUri = substr($requestUri, 0, $queryPos);
        }

        if ($requestUri === '' || $requestUri === false) {
            $requestUri = '/';
        }

        // Decode percent-encoding so params with slashes (%2F) work.
        // Single decode pass — avoids double-decoding corruption.
        return rawurldecode($requestUri);
    }

    private function matchPath(string $pattern, string $uri): ?array
    {
        // {param}     -> single segment: ([^/]+)
        // {param:any} -> multi-segment:  (.+?) non-greedy so trailing route parts still match
        $regex = preg_replace_callback(
            '#\{([a-zA-Z0-9_]+)(:any)?\}#',
            function (array $m): string {
                $name = $m[1];
                $multi = !empty($m[2]);
                return $multi ? '(?P<' . $name . '>.+?)' : '(?P<' . $name . '>[^/]+)';
            },
            $pattern
        );
        $regex = '#^' . $regex . '$#';

        if (!preg_match($regex, $uri, $matches)) {
            return null;
        }

        // Return only named params
        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }
}
