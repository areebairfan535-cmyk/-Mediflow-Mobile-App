<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Router — layer 1 of §12.
 *
 * Registration:
 *   $router->group('/api/v1', [], function ($r) {
 *       $r->post('/auth/login', [AuthController::class, 'login']);
 *       $r->group('', ['auth'], function ($r) {
 *           $r->get('/patients', [PatientController::class, 'index'], ['perm:patient.view']);
 *       });
 *   });
 *
 * Middleware is referenced by short alias ('auth', 'tenant', 'perm:x') and
 * resolved through an alias map, so route files stay readable and the
 * concrete classes stay swappable.
 *
 * Static segments are matched before dynamic ones regardless of registration
 * order, so `/scan/history` can never be captured by `/scan/{id}`.
 */
final class Router
{
    /**
     * @var list<array{
     *   method:string, path:string, regex:string, params:list<string>,
     *   handler:array{0:class-string,1:string}, middleware:list<string>,
     *   statics:int
     * }>
     */
    private array $routes = [];

    private string $prefix = '';

    /** @var list<string> */
    private array $groupMiddleware = [];

    /** @var array<string,class-string<Middleware>> */
    private array $aliases = [];

    /** @param array<string,class-string<Middleware>> $aliases */
    public function registerAliases(array $aliases): void
    {
        $this->aliases = $aliases + $this->aliases;
    }

    /**
     * @param list<string> $middleware
     * @param callable(self):void $register
     */
    public function group(string $prefix, array $middleware, callable $register): void
    {
        $prevPrefix     = $this->prefix;
        $prevMiddleware = $this->groupMiddleware;

        $this->prefix          = $prevPrefix . $prefix;
        $this->groupMiddleware = [...$prevMiddleware, ...$middleware];

        $register($this);

        $this->prefix          = $prevPrefix;
        $this->groupMiddleware = $prevMiddleware;
    }

    /** @param array{0:class-string,1:string} $handler @param list<string> $middleware */
    public function get(string $p, array $handler, array $middleware = []): void
    {
        $this->add('GET', $p, $handler, $middleware);
    }
    public function post(string $p, array $handler, array $middleware = []): void
    {
        $this->add('POST', $p, $handler, $middleware);
    }
    public function put(string $p, array $handler, array $middleware = []): void
    {
        $this->add('PUT', $p, $handler, $middleware);
    }
    public function patch(string $p, array $handler, array $middleware = []): void
    {
        $this->add('PATCH', $p, $handler, $middleware);
    }
    public function delete(string $p, array $handler, array $middleware = []): void
    {
        $this->add('DELETE', $p, $handler, $middleware);
    }

    /** @param array{0:class-string,1:string} $handler @param list<string> $middleware */
    private function add(string $method, string $path, array $handler, array $middleware): void
    {
        $full = $this->prefix . $path;
        $full = '/' . trim($full, '/');

        // Compile {name} into a named capture group.
        $params = [];
        $regex  = preg_replace_callback(
            '#\{([A-Za-z_][A-Za-z0-9_]*)\}#',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '(?P<' . $m[1] . '>[^/]+)';
            },
            $full,
        );

        $this->routes[] = [
            'method'     => $method,
            'path'       => $full,
            'regex'      => '#^' . $regex . '$#',
            'params'     => $params,
            'handler'    => $handler,
            'middleware' => [...$this->groupMiddleware, ...$middleware],
            // Specificity: more static segments = matched first.
            'statics'    => count(array_filter(
                explode('/', $full),
                static fn(string $s): bool => $s !== '' && !str_starts_with($s, '{'),
            )),
        ];
    }

    /**
     * Match and run. Throws NotFoundException / HttpException; the front
     * controller converts those into the error envelope.
     */
    public function dispatch(Request $request): void
    {
        // Most-specific first, so static paths beat dynamic ones.
        $candidates = $this->routes;
        usort(
            $candidates,
            static fn(array $a, array $b): int => $b['statics'] <=> $a['statics'],
        );

        $pathMatched = false;

        foreach ($candidates as $route) {
            if (preg_match($route['regex'], $request->path, $matches) !== 1) {
                continue;
            }
            $pathMatched = true;

            if ($route['method'] !== $request->method) {
                continue;
            }

            foreach ($route['params'] as $name) {
                $request->params[$name] = $matches[$name];
            }

            foreach ($route['middleware'] as $definition) {
                $this->runMiddleware($definition, $request);
            }

            [$class, $method] = $route['handler'];
            (new $class())->$method($request);
            return;
        }

        if ($pathMatched) {
            throw new HttpException(
                "Method {$request->method} is not allowed for {$request->path}",
                405,
                'method_not_allowed',
            );
        }

        throw new NotFoundException("No route for {$request->method} {$request->path}");
    }

    /** Resolve 'perm:invoice.create' into the class plus its arguments. */
    private function runMiddleware(string $definition, Request $request): void
    {
        $parts = explode(':', $definition, 2);
        $alias = $parts[0];
        $args  = isset($parts[1]) ? explode(',', $parts[1]) : [];

        $class = $this->aliases[$alias] ?? null;
        if ($class === null) {
            throw new \LogicException("Unknown middleware alias: $alias");
        }

        (new $class())->handle($request, $args);
    }

    /** @return list<array{method:string,path:string,middleware:list<string>}> */
    public function routeTable(): array
    {
        return array_map(
            static fn(array $r): array => [
                'method'     => $r['method'],
                'path'       => $r['path'],
                'middleware' => $r['middleware'],
            ],
            $this->routes,
        );
    }
}
