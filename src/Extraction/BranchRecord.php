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
    ) {}

    /**
     * @return array<string, string>
     */
    public function toPayload(): array
    {
        return [
            'external_id' => $this->externalId,
            'name' => $this->name,
        ];
    }
}
