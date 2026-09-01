<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Typed HTTP exceptions. Services throw these; the front controller turns
 * them into the standard error envelope. This is what keeps controllers thin:
 * they don't check-and-return, they just let the service throw.
 */
class HttpException extends RuntimeException
{
    /** @param array<string,mixed>|null $fields */
    public function __construct(
        string $message,
        public readonly int $status = 400,
        public readonly string $errorCode = 'error',
        public readonly ?array $fields = null,
    ) {
        parent::__construct($message);
    }
}

final class ValidationException extends HttpException
{
    /** @param array<string,list<string>> $errors */
    public function __construct(array $errors, string $message = 'Validation failed')
    {
        parent::__construct($message, 422, 'validation_failed', $errors);
    }
}

final class UnauthorizedException extends HttpException
{
    public function __construct(string $message = 'Unauthenticated')
    {
        parent::__construct($message, 401, 'unauthenticated');
    }
}

final class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'You do not have permission to do this')
    {
        parent::__construct($message, 403, 'forbidden');
    }
}

final class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Resource not found')
    {
        parent::__construct($message, 404, 'not_found');
    }
}

final class ConflictException extends HttpException
{
    public function __construct(string $message = 'Conflict')
    {
        parent::__construct($message, 409, 'conflict');
    }
}

/**
 * The subscription plan does not allow this (§22).
 *
 * 402 rather than 403: nothing is wrong with who the user is or what they may
 * do — the organization has simply used up what it pays for, and the fix is a
 * plan change, not a permission change. Clients can therefore tell "you may
 * not" from "you have run out" and offer the right next step.
 */
final class PlanLimitException extends HttpException
{
    public function __construct(
        string $message,
        public readonly string $metric = '',
        public readonly ?int $limit = null,
        public readonly int $used = 0,
    ) {
        parent::__construct($message, 402, 'plan_limit_reached', [
            'metric' => [$metric],
        ]);
    }
}

final class TooManyRequestsException extends HttpException
{
    public function __construct(string $message = 'Too many requests', public readonly int $retryAfter = 60)
    {
        parent::__construct($message, 429, 'rate_limited');
    }
}
