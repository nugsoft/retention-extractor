<?php

declare(strict_types=1);

use Nugsoft\RetentionExtractor\Console\InstallCommand;

/**
 * The installer walks a developer through mapping their schema, and until now
 * nothing checked any of it. Both faults reported from real installs lived
 * here: a prompt with no answers, and a `null` passed to a `string` parameter
 * that killed the command halfway through.
 *
 * The interactive walk still needs a harness of its own — Laravel Prompts
 * cannot be faked in this version and the command writes to config_path(). What
 * IS checked here is everything reachable without driving a prompt: the two
 * constants that must agree, and the argument types the prompts are called
 * with, which is the fault that got shipped.
 */
function installConstant(string $name): array
{
    return (new ReflectionClass(InstallCommand::class))->getConstant($name);
}

describe('the metric tables it proposes', function (): void {
    /**
     * `rankForProduct()` reads a hint for every metric a product reports. It
     * used to fall back to an empty list where there was none, which quietly
     * covered for two constants disagreeing; the fallback is gone, so this is
     * what keeps them honest.
     */
    it('has a table hint for every metric a product reports', function (): void {
        $hints = installConstant('MetricTableHints');

        $missing = [];

        foreach (installConstant('ProductMetrics') as $product => $metrics) {
            foreach ($metrics as $metric) {
                if (! array_key_exists($metric, $hints)) {
                    $missing[] = "{$product}.{$metric}";
                }
            }
        }

        expect($missing)->toBe([], 'metrics with no table hint: '.implode(', ', $missing));
    });

    it('has a hint for the login count every product is offered', function (): void {
        expect(installConstant('MetricTableHints'))->toHaveKey('login_count_7d');
    });

    it('proposes at least one table for every metric', function (): void {
        foreach (installConstant('MetricTableHints') as $metric => $tables) {
            expect($tables)->not->toBeEmpty("{$metric} proposes nothing");
        }
    });
});

/**
 * The fault that reached a real install: select()'s $hint is typed `string`,
 * not `?string`, so a ternary passing null on one branch is a TypeError partway
 * through setup — after the developer has already answered several questions.
 *
 * PHPStan does not help here. Laravel Prompts declares its helpers inside
 * function_exists() guards in a files-autoloaded file, which PHPStan will not
 * resolve, so every argument passed to select() and confirm() is unchecked.
 *
 * This is a targeted guard, not a general one: it knows the shape that shipped.
 * Every `: null` ternary branch in this file feeds a prompt argument, and none
 * of those arguments is nullable. Crude, and it would have caught the bug.
 */
describe('the prompts it calls', function (): void {
    it('never hands a prompt a null where the signature wants a string', function (): void {
        $source = (string) file_get_contents(
            (new ReflectionClass(InstallCommand::class))->getFileName(),
        );

        $offenders = [];

        foreach (explode("\n", $source) as $number => $line) {
            // A ternary's else branch on its own line, or a null passed
            // outright to one of the named string arguments.
            if (preg_match('/^\s*:\s*null\s*,?\s*$/', $line) === 1
                || preg_match('/\b(hint|label|modalHeading):\s*null\b/', $line) === 1) {
                $offenders[] = 'line '.($number + 1).': '.trim($line);
            }
        }

        expect($offenders)->toBe([], 'a prompt argument is null: '.implode(', ', $offenders));
    });
});
