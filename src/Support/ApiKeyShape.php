<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Support;

/**
 * What a NugsoftOS API key looks like, and what is wrong with one that does not.
 *
 * NugsoftOS checks this shape before it looks a key up, and answers a bare 401
 * when it fails — indistinguishable, from here, from a key that was revoked or
 * never issued. So the same check is made on this side, where the key is still
 * in front of us and the answer can name the problem.
 *
 * Nothing here ever returns the key. A diagnostic that prints a secret is one
 * nobody can paste into a ticket.
 */
final class ApiKeyShape
{
    /**
     * Characters in a key NugsoftOS issued. `bin2hex(random_bytes(32))`.
     *
     * Untyped: this package supports PHP 8.2, and a typed class constant is
     * a parse error before 8.3.
     */
    public const Length = 64;

    public static function isWellFormed(string $key): bool
    {
        return preg_match('/^[0-9a-f]{'.self::Length.'}$/', $key) === 1;
    }

    /**
     * The key's shape in words, for a person reading an error.
     *
     * Says what is wrong rather than what was expected, because the expected
     * shape is stated beside it wherever this is used and repeating it twice
     * in one sentence reads like a riddle.
     */
    public static function describe(string $key): string
    {
        $length = strlen($key);

        if ($length !== self::Length) {
            $difference = abs($length - self::Length);
            $direction = $length > self::Length ? 'too many' : 'too few';

            return "{$length} characters — {$difference} ".$direction;
        }

        if (! ctype_xdigit($key)) {
            return 'the right length, but not every character is a hex digit';
        }

        if ($key !== strtolower($key)) {
            return 'the right length and all hex, but some characters are uppercase';
        }

        return 'well formed';
    }
}
