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

/*
 * The regex guard that used to live here — scanning the source for a `: null`
 * ternary feeding a prompt argument — is gone. It was a stand-in for not being
 * able to run the command, written on the mistaken belief that Prompts could
 * not be faked. InstallWizardTest runs the wizard for real and catches that
 * fault as the TypeError it is, along with the ones a regex could never see.
 */
