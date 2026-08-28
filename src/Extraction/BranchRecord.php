<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Extraction;

/**
 * One of a client's branches.
 *
 * A branch is not a client. The client is the business Retention Intel scores,
 * watchlists and can lose; a branch is where that business's work is recorded.
 * These exist so a client whose figures are falling can be read as "the Kyanja
 * campus stopped" rather than as one number nobody can act on.
 */
final readonly class BranchRecord
{
    public function __construct(
        public string $externalId,
        public string $name,
        /** The branch's primary key, used to scope its share of each metric. */
        public int|string $key,
        /*
         * Where and how to reach it.
         *
         * Usually the only place these exist. `schools` holds a name and a
         * flag; `school_branches` holds the address, the email and the phone
         * numbers — so a client profile built from the parent alone can name a
         * school and say nothing about where it is.
         */
        public ?string $address = null,
        public ?string $contactPhone = null,
        public ?string $contactEmail = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toPayload(): array
    {
        return array_filter([
            'external_id' => $this->externalId,
            'name' => $this->name,
            'address' => $this->address,
            'contact_phone' => $this->contactPhone,
            'contact_email' => $this->contactEmail,
        ], fn (?string $value): bool => $value !== null);
    }
}
