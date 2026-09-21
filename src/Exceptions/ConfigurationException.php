<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Exceptions;

use Nugsoft\RetentionExtractor\Support\ApiKeyShape;
use RuntimeException;

/**
 * Raised when the mapping is incomplete or points at something that does not
 * exist. Always thrown before anything is sent — a misconfigured extractor must
 * fail loudly rather than push wrong numbers.
 */
class ConfigurationException extends RuntimeException
{
    public static function missing(string $key, string $hint): self
    {
        return new self("retention-extractor.{$key} is not configured. {$hint}");
    }

    /**
     * The key is set but could never authenticate.
     *
     * Raised before the request rather than after it. NugsoftOS refuses a
     * malformed key with a bare 401, which this package reports as "the API
     * key was not recognised" — so somebody re-pastes a key that was right all
     * along, and a trailing newline from a `.env` file costs an afternoon.
     *
     * The key itself is never in the message: an error somebody cannot paste
     * into a ticket is an error they will describe from memory instead.
     */
    public static function malformedApiKey(string $key): self
    {
        return new self(
            'retention-extractor.api.key is not the shape NugsoftOS issues: '
            .ApiKeyShape::describe($key).'. A key is '.ApiKeyShape::Length
            .' lowercase hex characters. Check RETENTION_API_KEY — and remember that a cached'
            .' config keeps the old value until `php artisan config:clear`.',
        );
    }

    public static function unknownTable(string $table, string $key): self
    {
        return new self("Table '{$table}' (from retention-extractor.{$key}) does not exist in this database.");
    }

    public static function unknownColumn(string $table, string $column, string $key): self
    {
        return new self("Column '{$table}.{$column}' (from retention-extractor.{$key}) does not exist.");
    }
}
