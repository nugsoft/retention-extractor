<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;

/**
 * Drives `retention:install` the way a developer does, and reads back what it
 * wrote.
 *
 * This did not exist for a long time, and both faults reported from real
 * installs lived in that gap: a prompt with no options that could not be
 * answered or escaped, and a `null` handed to a `string` parameter that killed
 * the command four questions in.
 *
 * Two things made it look harder than it is, and both were wrong. Laravel puts
 * Prompts into fallback mode whenever it is running tests — see
 * ConfiguresPrompts — so the wizard can be answered with expectsQuestion() by
 * VALUE, rather than by simulating arrow keys and encoding the position of
 * every option. And the application's config path can be moved, so the command
 * can write a real file somewhere disposable and the result read back as PHP
 * rather than asserted against console output.
 */
trait InstallHarness
{
    private ?string $configDirectory = null;

    /**
     * Points config_path() somewhere disposable, so the command writes a real
     * file that a test can read back.
     */
    protected function useDisposableConfigPath(): string
    {
        $this->configDirectory = sys_get_temp_dir().'/retention-extractor-'.bin2hex(random_bytes(6));

        mkdir($this->configDirectory, recursive: true);

        $this->app->useConfigPath($this->configDirectory);

        return $this->configDirectory;
    }

    /**
     * What Retention Intel says it needs, when asked.
     *
     * @param  array<int, string>  $required
     * @param  array<string, array<int, string>>  $hints
     */
    protected function fakeContract(
        string $code = 'poscream',
        string $name = 'POScream',
        array $required = ['login_count_7d', 'items_sold_7d', 'transactions_7d', 'transaction_value_7d'],
        bool $scored = true,
        array $hints = [],
    ): void {
        Http::fake(['*/api/v1/metrics' => Http::response([
            'product' => ['code' => $code, 'name' => $name],
            'scored' => $scored,
            'required' => $required,
            'accepted' => $required,
            'components' => ['usage', 'transaction', 'login'],
            'hints' => $hints,
        ])]);
    }

    /**
     * Retention Intel unreachable, which must fall back rather than fail.
     */
    protected function fakeUnreachableRetentionIntel(): void
    {
        Http::fake(['*/api/v1/metrics' => Http::response(['message' => 'Unauthenticated.'], 401)]);
    }

    /**
     * Runs the installer, answering its questions.
     *
     * Answers are given by value against the real label of each prompt, so a
     * test reads as the conversation it is having. Laravel matches on the
     * question text, so the labels here are the labels the developer sees.
     *
     * @param  array<int, array{0: string, 1: string|bool}>  $answers
     */
    protected function install(array $answers, bool $force = true): PendingCommand
    {
        $command = $this->artisan('retention:install'.($force ? ' --force' : ''));

        foreach ($answers as [$label, $answer]) {
            is_bool($answer)
                ? $command->expectsConfirmation($label, $answer ? 'yes' : 'no')
                : $command->expectsQuestion($label, $answer);
        }

        return $command;
    }

    /**
     * The questions every multi-tenant install answers before reaching its
     * metrics, so a test can say what it is actually about.
     *
     * @return array<int, array{0: string, 1: string|bool}>
     */
    protected function connectAndIdentifyTenant(?string $key = null, string $realUse = 'sales'): array
    {
        return [
            ['Where is Retention Intel?', 'https://retention.test'],
            ['The API key issued for this product', $key ?? str_repeat('a', 64)],
            ['Does this installation serve more than one business?', true],
            ['Which table holds those businesses?', 'businesses'],
            ['Which column identifies each business to Retention Intel?', 'id'],
            ['Which column holds the business name?', 'business_name'],
            ['Does a business have branches, and is that where the work is recorded?', false],
            ['Which table best represents real use of this product?', $realUse],
        ];
    }

    /**
     * The same preamble for a product whose work happens at its branches.
     *
     * @return array<int, array{0: string, 1: string|bool}>
     */
    protected function connectAndIdentifyBranches(string $realUse = 'sales'): array
    {
        return [
            ['Where is Retention Intel?', 'https://retention.test'],
            ['The API key issued for this product', str_repeat('a', 64)],
            ['Does this installation serve more than one business?', true],
            ['Which table holds those businesses?', 'businesses'],
            ['Which column identifies each business to Retention Intel?', 'id'],
            ['Which column holds the business name?', 'business_name'],
            ['Does a business have branches, and is that where the work is recorded?', true],
            ['Which table holds those branches?', 'business_branches'],
            ["Which column on 'business_branches' says which business a branch belongs to?", 'business_id'],
            ['Which column identifies each branch to Retention Intel?', 'id'],
            ['Which column holds the branch name?', 'name'],
            ['And which column do your other tables use to name a branch?', 'business_branch_id'],
            ["Send the address and contacts on 'business_branches' as part of the client's profile?", true],
            ['Which table best represents real use of this product?', $realUse],
        ];
    }

    /**
     * The last question of every install: whether to push subscription dates.
     *
     * @return array<int, array{0: string, 1: string|bool}>
     */
    protected function declineSubscriptions(): array
    {
        return [["Push subscription dates from 'subscriptions'?", false]];
    }

    /**
     * The written config, loaded as PHP.
     *
     * Read back rather than asserted against console output, because what
     * matters is the mapping the next command will run, not what was printed
     * while arriving at it.
     *
     * @return array<string, mixed>
     */
    protected function writtenConfig(): array
    {
        $path = $this->configDirectory.'/retention-extractor.php';

        expect(file_exists($path))->toBeTrue('the installer wrote no config at all');

        return require $path;
    }

    protected function wroteNoConfig(): bool
    {
        return ! file_exists($this->configDirectory.'/retention-extractor.php');
    }

    protected function tearDownInstallHarness(): void
    {
        if ($this->configDirectory === null) {
            return;
        }

        foreach (glob($this->configDirectory.'/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->configDirectory);
    }
}
