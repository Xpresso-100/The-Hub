<?php
declare(strict_types=1);

namespace Hub\Http;

/**
 * Routing contract (SPEC s2.5). A routes.php file returns a list of
 * [METHOD, PATH_PATTERN, callable(Request $req, array $params): Response].
 * {id} matches [0-9]+, {sku} matches [A-Z0-9-]{2,20}. Unknown path -> 404, wrong method -> 405.
 */
final class Router
{
    public const PARAM_PATTERNS = [
        'id' => '[0-9]+',
        'sku' => '[A-Z0-9-]{2,20}',
    ];

    /** @var list<array{0:string,1:string,2:callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $parts = preg_split('/(\{[a-z_]+\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex = '';
        foreach ($parts as $part) {
            if (preg_match('/^\{([a-z_]+)\}$/', $part, $m) === 1) {
                $regex .= '(?P<' . $m[1] . '>' . (self::PARAM_PATTERNS[$m[1]] ?? '[^/]+') . ')';
            } else {
                $regex .= preg_quote($part, '#');
            }
        }
        $this->routes[] = [strtoupper($method), '#^' . $regex . '$#', $handler];
    }

    /** @param list<array{0:string,1:string,2:callable}> $routes */
    public function addAll(array $routes): void
    {
        foreach ($routes as $i => $route) {
            if (!is_array($route) || count($route) !== 3 || !is_string($route[0]) || !is_string($route[1]) || !is_callable($route[2])) {
                throw new \LogicException('Route #' . $i . ' must be [METHOD, PATH, callable]');
            }
            $this->add($route[0], $route[1], $route[2]);
        }
    }

    /** Loads a routes.php file that returns the route list. */
    public function loadFile(string $file): void
    {
        $routes = require $file;
        if (!is_array($routes)) {
            throw new \LogicException('Route file must return an array: ' . basename(dirname($file)) . '/' . basename($file));
        }
        $this->addAll($routes);
    }

    /** Dispatches and turns every throwable into a JSON error response. */
    public function handle(Request $request): Response
    {
        try {
            return $this->dispatch($request);
        } catch (HttpError $e) {
            return $e->toResponse();
        } catch (\Throwable $e) {
            error_log('HUB-ERROR ' . self::describe($e));
            return Response::error(500, 'SERVER_ERROR', 'Something went wrong. Please try again.');
        }
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];
        foreach ($this->routes as [$method, $regex, $handler]) {
            if (preg_match($regex, $request->path, $m) !== 1) {
                continue;
            }
            if ($method !== $request->method) {
                $allowed[] = $method;
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $response = $handler($request, $params);
            if (!$response instanceof Response) {
                throw new \LogicException('Route handler for ' . $method . ' ' . $request->path . ' did not return a Response');
            }
            return $response;
        }
        if ($allowed !== []) {
            $response = Response::error(405, 'METHOD_NOT_ALLOWED', 'Method not allowed.');
            $response->headers['Allow'] = implode(', ', array_unique($allowed));
            return $response;
        }
        return Response::error(404, 'NOT_FOUND', 'Not found.');
    }

    /** Class, message and file:line frames - never arguments (they may hold passwords). */
    private static function describe(\Throwable $e): string
    {
        $text = get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
        foreach (array_slice($e->getTrace(), 0, 6) as $frame) {
            $text .= ' <- ' . ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '?')
                . ' (' . basename((string) ($frame['file'] ?? '?')) . ':' . ($frame['line'] ?? '?') . ')';
        }
        return $text;
    }
}
