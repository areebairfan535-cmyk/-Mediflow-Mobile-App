<?php
declare(strict_types=1);

namespace App\Core;

/**
 * JSON response writer. One envelope shape for the whole API so all three
 * clients (patient app, clinic web, super admin) share one parser.
 *
 *   success: { "data": {...}, "meta": {...}? }
 *   failure: { "error": { "message": "...", "code": "...", "fields": {...}? } }
 */
final class Response
{
    /** @param array<string,mixed> $headers */
    public static function json(mixed $payload, int $status = 200, array $headers = []): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            foreach ($headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        if ($payload !== null) {
            echo json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR,
            );
        }
        exit;
    }

    /** @param array<string,mixed>|null $meta */
    public static function success(mixed $data, int $status = 200, ?array $meta = null): never
    {
        $body = ['data' => $data];
        if ($meta !== null) {
            $body['meta'] = $meta;
        }
        self::json($body, $status);
    }

    /** @param array<string,mixed>|null $fields */
    public static function error(
        string $message,
        int $status = 400,
        string $code = 'error',
        ?array $fields = null,
    ): never {
        $error = ['message' => $message, 'code' => $code];
        if ($fields !== null) {
            $error['fields'] = $fields;
        }
        self::json(['error' => $error], $status);
    }

    /** Emit CORS headers and short-circuit preflight requests. */
    public static function cors(Request $request, array $allowedOrigins): void
    {
        $origin = $request->header('origin');
        $allow  = in_array('*', $allowedOrigins, true)
            ? ($origin ?? '*')
            : (in_array($origin, $allowedOrigins, true) ? $origin : null);

        if ($allow !== null && !headers_sent()) {
            header('Access-Control-Allow-Origin: ' . $allow);
            header('Vary: Origin');
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Organization-Id, X-Device-Id, X-Device-Name');
            header('Access-Control-Max-Age: 86400');
        }

        if ($request->method === 'OPTIONS') {
            self::json(null, 204);
        }
    }
}
