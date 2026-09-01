<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Immutable-ish view of the incoming HTTP request, plus the context that
 * middleware attaches to it (authenticated user, active tenant, permissions).
 *
 * Controllers read context from here and never from globals.
 */
final class Request
{
    public string $method;
    public string $path;
    /** @var array<string,mixed> Parsed JSON body or form fields. */
    public array $body = [];
    /** @var array<string,string> Query-string params. */
    public array $query = [];
    /** @var array<string,string> Route params captured from {placeholders}. */
    public array $params = [];
    /** @var array<string,array<string,mixed>> Normalised $_FILES. */
    public array $files = [];
    /** @var array<string,string> */
    public array $headers = [];

    /** Correlates every audit_log row written during this request. */
    public string $requestId;

    // ---- Context populated by middleware ----

    /** @var array<string,mixed>|null Authenticated user row. */
    private ?array $user = null;
    /** @var array<string,mixed>|null Active organization row (tenant). */
    private ?array $organization = null;
    /** @var array<string,mixed>|null organization_users membership row. */
    private ?array $membership = null;
    /** @var list<string> Permission slugs granted in the active tenant. */
    private array $permissions = [];
    private ?int $tokenId = null;

    public static function capture(): self
    {
        $r = new self();

        $r->method    = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $r->path      = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/', '/');
        $r->query     = array_map('strval', $_GET);
        $r->headers   = self::readHeaders();
        $r->files     = $_FILES;
        $r->requestId = bin2hex(random_bytes(16));
        $r->body      = self::readBody($r->headers);

        return $r;
    }

    /** @return array<string,string> */
    private static function readHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        // CONTENT_TYPE / CONTENT_LENGTH arrive without the HTTP_ prefix.
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        return $headers;
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    private static function readBody(array $headers): array
    {
        $type = $headers['content-type'] ?? '';

        if (str_contains($type, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            if ($raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        // multipart/form-data and x-www-form-urlencoded.
        if ($_POST !== []) {
            return $_POST;
        }

        // PUT/PATCH with urlencoded body: PHP does not populate $_POST.
        if (in_array($_SERVER['REQUEST_METHOD'] ?? '', ['PUT', 'PATCH', 'DELETE'], true)) {
            $raw = file_get_contents('php://input') ?: '';
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
                parse_str($raw, $parsed);
                return $parsed;
            }
        }

        return [];
    }

    // ---- Accessors ----

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function param(string $key, ?string $default = null): ?string
    {
        return $this->params[$key] ?? $default;
    }

    public function intParam(string $key): int
    {
        return (int) ($this->params[$key] ?? 0);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string,mixed>|null */
    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        return [
            'name' => (string) $f['name'],
            'type' => (string) $f['type'],
            'tmp'  => (string) $f['tmp_name'],
            'size' => (int) $f['size'],
        ];
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');
        if ($header === null || !preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return null;
        }
        return $m[1];
    }

    public function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return substr($this->header('user-agent') ?? '', 0, 500);
    }

    // ---- Context (set by middleware) ----

    /** @param array<string,mixed> $user */
    public function setUser(array $user, int $tokenId): void
    {
        $this->user    = $user;
        $this->tokenId = $tokenId;
    }

    /** @return array<string,mixed>|null */
    public function user(): ?array
    {
        return $this->user;
    }

    public function userId(): ?int
    {
        return isset($this->user['id']) ? (int) $this->user['id'] : null;
    }

    public function tokenId(): ?int
    {
        return $this->tokenId;
    }

    public function isPlatformAdmin(): bool
    {
        return (bool) ($this->user['is_platform_admin'] ?? false);
    }

    /**
     * @param array<string,mixed> $organization
     * @param array<string,mixed> $membership
     * @param list<string>        $permissions
     */
    public function setTenant(array $organization, array $membership, array $permissions): void
    {
        $this->organization = $organization;
        $this->membership   = $membership;
        $this->permissions  = $permissions;
    }

    /** @return array<string,mixed>|null */
    public function organization(): ?array
    {
        return $this->organization;
    }

    /**
     * The active tenant id. Every repository call is scoped by this —
     * see Repository::scope(). Null only for platform-admin routes.
     */
    public function organizationId(): ?int
    {
        return isset($this->organization['id']) ? (int) $this->organization['id'] : null;
    }

    /** @return array<string,mixed>|null */
    public function membership(): ?array
    {
        return $this->membership;
    }

    public function roleSlug(): ?string
    {
        return isset($this->membership['role_slug'])
            ? (string) $this->membership['role_slug']
            : null;
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return $this->permissions;
    }

    public function can(string $permission): bool
    {
        // Platform admins bypass tenant permission checks by design (§21).
        if ($this->isPlatformAdmin()) {
            return true;
        }
        return in_array($permission, $this->permissions, true);
    }
}
