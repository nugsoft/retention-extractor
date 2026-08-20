<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Extraction;

/**
 * One client to report on, resolved from either a tenant row or single-tenant
 * configuration. Everything downstream works with this rather than caring which
 * deployment model the product uses.
 */
final readonly class ClientRecord
{
    public function __construct(
        public string $externalId,
        public string $name,
        public ?string $contactPhone = null,
        public ?string $contactEmail = null,
        /** The tenant's primary key, used to scope metric queries. Null when single-tenant. */
        public int|string|null $key = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return array_filter([
            'external_id' => $this->externalId,
            'name' => $this->name,
            'contact_phone' => $this->contactPhone,
            'contact_email' => $this->contactEmail,
        ], fn (mixed $value): bool => $value !== null);
    }

    public function isScoped(): bool
    {
        return $this->key !== null;
    }
}
