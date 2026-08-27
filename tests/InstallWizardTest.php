<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

/**
 * The wizard, walked end to end.
 *
 * Every fault reported from a real install has been in this command, and none
 * of it was covered: the prompts were assumed untestable and the config assumed
 * unwritable in a test. Neither was true — Laravel already puts Prompts into
 * fallback mode when running tests, and the config path can be moved somewhere
 * disposable. These answer the questions and read back the file the next
 * command will run.
 */
beforeEach(function (): void {
    $this->useDisposableConfigPath();
});

describe('a multi-tenant product', function (): void {
    it('writes a mapping that reaches every client', function (): void {
        $this->fakeContract(required: ['transactions_7d', 'transaction_value_7d']);

        $this->install([
            ...$this->connectAndIdentifyTenant(),
            ['transactions_7d', 'sales'],
            ['transaction_value_7d', 'sales'],
            ...$this->declineSubscriptions(),
        ])->assertSuccessful()->run();

        $config = $this->writtenConfig();

        expect($config['metrics']['transactions_7d'])
            ->toMatchArray(['table' => 'sales', 'via' => 'business_id', 'date' => 'created_at'])
            ->and($config['last_activity']['via'])->toBe('business_id')
            ->and($config['clients']['external_id'])->toBe('id')
            ->and($config['clients']['name'])->toBe('business_name');
    });

    /**
     * The bug that reached a real install. `hint:` is typed `string`, and the
     * null passed on one branch threw a TypeError at the SECOND metric — so
     * anything walking past one metric to the next catches it.
     */
    it('gets past the first metric without dying on a prompt argument', function (): void {
        $this->fakeContract(required: ['login_count_7d', 'transactions_7d']);

        $this->install([
            ...$this->connectAndIdentifyTenant(),
            ['login_count_7d', 'sessions_log'],
            ['transactions_7d', 'sales'],
            ...$this->declineSubscriptions(),
        ])->assertSuccessful()->run();

        expect($this->writtenConfig()['metrics'])
            ->toHaveKey('login_count_7d')
            ->toHaveKey('transactions_7d');
    });

    /**
     * The other reported fault, from the other side. A table with no route to
     * the tenant used to be written without a `via` at all, and the push then
     * refused it. It has to ask.
     */
    it('asks how a table with no tenant column reaches its client', function (): void {
        $this->fakeContract(required: ['items_sold_7d']);

        $this->install([
            ...$this->connectAndIdentifyTenant(),
            ['items_sold_7d', 'sale_items'],
            ["How does a row in 'sale_items' reach the businesses it belongs to?", 'hop'],
            ["Which column on 'sale_items' points at that other table?", 'sale_id'],
            ['And which table is that?', 'sales'],
            ["Which column on 'sales' does sale_items.sale_id match?", 'id'],
            ["And which column on 'sales' holds the businesses it belongs to?", 'business_id'],
            ...$this->declineSubscriptions(),
        ])->assertSuccessful()->run();

        expect($this->writtenConfig()['metrics']['items_sold_7d']['via'])
            ->toBe(['sale_id' => ['sales', 'id', 'business_id']]);
    });

    it('leaves out a metric the product cannot measure, rather than writing a broken one', function (): void {
        $this->fakeContract(required: ['items_sold_7d', 'transactions_7d']);

        $this->install([
            ...$this->connectAndIdentifyTenant(),
            ['items_sold_7d', 'sale_items'],
            ["How does a row in 'sale_items' reach the businesses it belongs to?", 'skip'],
            ['transactions_7d', 'sales'],
            ...$this->declineSubscriptions(),
        ])->assertSuccessful()->run();

        $metrics = $this->writtenConfig()['metrics'];

        expect($metrics)->not->toHaveKey('items_sold_7d')
            ->and($metrics)->toHaveKey('transactions_7d');
    });

    /**
     * The invariant both reported faults broke: nothing the installer writes
     * may be a mapping the push then refuses.
     */
    it('never writes a multi-tenant mapping without a way to the client', function (): void {
        $this->fakeContract(required: ['login_count_7d', 'transactions_7d']);

        $this->install([
            ...$this->connectAndIdentifyTenant(),
            ['login_count_7d', 'sessions_log'],
            ['transactions_7d', 'sales'],
            ...$this->declineSubscriptions(),
        ])->assertSuccessful()->run();

        $config = $this->writtenConfig();

        foreach ($config['metrics'] as $metric => $mapping) {
            expect($mapping['via'] ?? null)->not->toBeNull("{$metric} has no via");
        }

        expect($config['last_activity']['via'] ?? null)->not->toBeNull('last_activity has no via');
    });
});

describe('what Retention Intel says it needs', function (): void {
    it('asks only for the metrics the contract names', function (): void {
        $this->fakeContract(required: ['transactions_7d']);

        $this->install([
            ...$this->connectAndIdentifyTenant(),
            ['transactions_7d', 'sales'],
            ...$this->declineSubscriptions(),
        ])->assertSuccessful()->run();

        expect(array_keys($this->writtenConfig()['metrics']))->toBe(['transactions_7d']);
    });

    /**
     * The point of the endpoint: a product this package has never heard of can
     * be set up without releasing the package again.
     */
    it('sets up a product the package knows nothing about', function (): void {
        $this->fakeContract(code: 'boda_express', name: 'Boda Express', required: ['transactions_7d']);

        $this->install([
            ...$this->connectAndIdentifyTenant(),
            ['transactions_7d', 'sales'],
            ...$this->declineSubscriptions(),
        ])->expectsOutputToContain('Boda Express')->assertSuccessful()->run();

        expect(array_keys($this->writtenConfig()['metrics']))->toBe(['transactions_7d']);
    });

    it('says so when nobody has decided how to score the product yet', function (): void {
        $this->fakeContract(code: 'brand_new', name: 'Brand New', required: [], scored: false);

        $this->install([
            ...$this->connectAndIdentifyTenant(),
            ...$this->declineSubscriptions(),
        ])->expectsOutputToContain('scored')->assertSuccessful()->run();

        expect($this->writtenConfig()['metrics'])->toBe([]);
    });
});

describe('when Retention Intel cannot be reached', function (): void {
    it('falls back to the built-in list and says that is what it is doing', function (): void {
        $this->fakeUnreachableRetentionIntel();

        $this->install([
            ['Where is Retention Intel?', 'https://retention.test'],
            ['The API key issued for this product', str_repeat('a', 64)],
            ['Which product is this?', 'poscream'],
            ['Does this installation serve more than one business?', true],
            ['Which table holds those businesses?', 'businesses'],
            ['Which column identifies each business to Retention Intel?', 'id'],
            ['Which column holds the business name?', 'business_name'],
            ['Which table best represents real use of this product?', 'sales'],
            ['login_count_7d', 'sessions_log'],
            ['items_sold_7d', 'skip'],
            ['transactions_7d', 'sales'],
            ['transaction_value_7d', 'sales'],
            ...$this->declineSubscriptions(),
        ])
            ->expectsOutputToContain('Falling back to the list built into this package')
            ->assertSuccessful()
            ->run();

        expect($this->writtenConfig()['metrics'])->toHaveKey('transactions_7d');
    });

    it('does not reach for the network at all when no key is given', function (): void {
        Http::fake();

        $this->install([
            ['Where is Retention Intel?', 'https://retention.test'],
            ['The API key issued for this product', ''],
            ['Which product is this?', 'poscream'],
            ['Does this installation serve more than one business?', true],
            ['Which table holds those businesses?', 'businesses'],
            ['Which column identifies each business to Retention Intel?', 'id'],
            ['Which column holds the business name?', 'business_name'],
            ['Which table best represents real use of this product?', 'sales'],
            ['login_count_7d', 'sessions_log'],
            ['items_sold_7d', 'skip'],
            ['transactions_7d', 'sales'],
            ['transaction_value_7d', 'sales'],
            ...$this->declineSubscriptions(),
        ])
            ->expectsOutputToContain('Falling back to the list built into this package')
            ->assertSuccessful()
            ->run();

        Http::assertNothingSent();
    });
});
