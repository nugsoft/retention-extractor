<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Exceptions;

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

    public static function unknownTable(string $table, string $key): self
    {
        return new self("Table '{$table}' (from retention-extractor.{$key}) does not exist in this database.");
    }

    public static function unknownColumn(string $table, string $column, string $key): self
    {
        return new self("Column '{$table}.{$column}' (from retention-extractor.{$key}) does not exist.");
    }
}
