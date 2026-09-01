<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Base service — the Service Layer of §12/§13. Business rules live here:
 * "can this invoice be cancelled", "how is tax computed for this country",
 * "who may read this patient's record".
 *
 * A service receives the Request context (actor + tenant), never the raw
 * HTTP request, so the same service can later be driven by a queue worker
 * or CLI command rather than only by a controller.
 */
abstract class Service
{
    public function __construct(
        protected readonly ?int $organizationId = null,
        protected readonly ?int $actorId = null,
    ) {
    }

    /** Build a service already bound to the request's tenant and actor. */
    public static function for(Request $request): static
    {
        /** @phpstan-ignore-next-line — late static binding on a concrete child */
        return new static($request->organizationId(), $request->userId());
    }

    /** Run a closure atomically. Every multi-table write must use this. */
    protected function transaction(callable $callback): mixed
    {
        return Database::transaction($callback);
    }

    /**
     * Stamp created_by/updated_by on a write payload so §5's requirement
     * ("every sensitive record should include creator/updater") holds without
     * each service remembering it.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    protected function stampCreate(array $data): array
    {
        if ($this->actorId !== null) {
            $data['created_by'] ??= $this->actorId;
            $data['updated_by'] ??= $this->actorId;
        }
        return $data;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    protected function stampUpdate(array $data): array
    {
        if ($this->actorId !== null) {
            $data['updated_by'] = $this->actorId;
        }
        return $data;
    }

    /**
     * The organization's subscription envelope, for §22 limit checks.
     *
     * Core naming one concrete service is a deliberate exception: every
     * tenant-scoped service needs the same check before it creates something
     * the plan counts, and a copy of the wiring in each of them is one more
     * place to forget it.
     */
    protected function plan(): \App\Services\SubscriptionService
    {
        return new \App\Services\SubscriptionService($this->organizationId, $this->actorId);
    }

    protected function requireOrganization(): int
    {
        if ($this->organizationId === null) {
            throw new ForbiddenException('No active organization for this request');
        }
        return $this->organizationId;
    }
}
