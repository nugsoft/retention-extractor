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
            ['Does a business have branches, and is that where the work is recorded?', false],
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
            ['Does a business have branches, and is that where the work is recorded?', false],
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

/**
 * Naming a table is not always enough to count a metric out of it.
 *
 * School Monitor writes the same `auth` action for logging in, logging out,
 * changing a password and impersonating somebody. Counting that table — or even
 * counting that action — reports roughly twice the logins there were, every
 * session counted again on the way out, and looks entirely reasonable doing it.
 */
describe('a hint that says which rows count', function (): void {
    it('writes the filter alongside the table', function (): void {
        $this->fakeContract(required: ['login_count_7d'], hints: [
            'login_count_7d' => [
                'tables' => ['audit_trail'],
                'where' => ['action' => 'login'],
            ],
        ]);

        $this->install([
            ...$this->connectAndIdentifyTenant(),
            ['login_count_7d', 'audit_trail'],
            ...$this->declineSubscriptions(),
        ])->assertSuccessful()->run();

        expect($this->writtenConfig()['metrics']['login_count_7d'])
            ->toMatchArray([
                'table' => 'audit_trail',
                'where' => ['action' => 'login'],
                'via' => 'business_id',
            ]);
    });

    it('counts people rather than rows when the hint says to', function (): void {
        $this->fakeContract(required: ['login_count_7d'], hints: [
            'login_count_7d' => [
                'tables' => ['audit_trail'],
                'distinct' => 'user_id',
            ],
        ]);

        $this->install([
            ...$this->connectAndIdentifyTenant(),
            ['login_count_7d', 'audit_trail'],
            ...$this->declineSubscriptions(),
        ])->assertSuccessful()->run();

        $mapping = $this->writtenConfig()['metrics']['login_count_7d'];

        // Counting both would be counting twice.
        expect($mapping['distinct'])->toBe('user_id')
            ->and($mapping)->not->toHaveKey('count');
    });

    /**
     * A filter written for one table means nothing against another, and
     * applied blindly would quietly match no rows at all.
     */
    it('does not carry a filter onto a table the developer chose instead', function (): void {
        $this->fakeContract(required: ['login_count_7d'], hints: [
            'login_count_7d' => [
                'tables' => ['audit_trail'],
                'where' => ['action' => 'login'],
            ],
        ]);

        $this->install([
            ...$this->connectAndIdentifyTenant(),
            ['login_count_7d', 'sessions_log'],
            ...$this->declineSubscriptions(),
        ])->assertSuccessful()->run();

        expect($this->writtenConfig()['metrics']['login_count_7d'])
            ->toMatchArray(['table' => 'sessions_log'])
            ->not->toHaveKey('where');
    });

    it('proposes the hinted table without being told twice', function (): void {
        $this->fakeContract(required: ['login_count_7d'], hints: [
            'login_count_7d' => ['tables' => ['audit_trail']],
        ]);

        $this->install([
            ...$this->connectAndIdentifyTenant(),
            // Answered with the default the wizard offered.
            ['login_count_7d', 'audit_trail'],
            ...$this->declineSubscriptions(),
        ])->assertSuccessful()->run();

        expect($this->writtenConfig()['metrics']['login_count_7d']['table'])->toBe('audit_trail');
    });
});

/**
 * What the wizard proposes when it does not know.
 */
describe('a metric nothing can place', function (): void {
    /**
     * School Monitor has no attendance table at all, and was offered
     * `exam_marks` for its attendance records — already selected, because the
     * fallback proposed whatever had been named as the product's real use. A
     * default is accepted far more often than it is read.
     */
    it('proposes nothing rather than an unrelated table', function (): void {
        $this->fakeContract(required: ['attendance_records_7d', 'transactions_7d']);

        $this->install([
            ...$this->connectAndIdentifyTenant(),
            // Answered with the default the wizard offered, which must be skip.
            ['attendance_records_7d', 'skip'],
            ['transactions_7d', 'sales'],
            ...$this->declineSubscriptions(),
        ])->assertSuccessful()->run();

        $metrics = $this->writtenConfig()['metrics'];

        expect($metrics)->not->toHaveKey('attendance_records_7d')
            ->and($metrics)->toHaveKey('transactions_7d');
    });

    it('still proposes the table Retention Intel named', function (): void {
        $this->fakeContract(required: ['attendance_records_7d'], hints: [
            'attendance_records_7d' => ['tables' => ['audit_trail']],
        ]);

        $this->install([
            ...$this->connectAndIdentifyTenant(),
            ['attendance_records_7d', 'audit_trail'],
            ...$this->declineSubscriptions(),
        ])->assertSuccessful()->run();

        expect($this->writtenConfig()['metrics']['attendance_records_7d']['table'])->toBe('audit_trail');
    });
});

/**
 * A product whose work happens at its branches.
 *
 * This is what a real School Monitor install looked like before the wizard
 * asked about branches: 103 of its tables carry `school_branch_id` and 15 carry
 * `school_id`, so almost every metric arrived as "nothing on this table looks
 * like a link to schools" and a two-step hop to be answered by hand.
 */
describe('branches beneath a client', function (): void {
    it('writes where the branches are and how a row names one', function (): void {
        $this->fakeContract(required: ['transactions_7d']);

        $this->install([
            ...$this->connectAndIdentifyBranches(),
            ['transactions_7d', 'sales'],
            ...$this->declineSubscriptions(),
        ])->assertSuccessful()->run();

        expect($this->writtenConfig()['clients']['branches'])->toBe([
            'table' => 'business_branches',
            'via' => 'business_id',
            'external_id' => 'id',
            'name' => 'name',
            'key' => 'business_branch_id',
            // The client's address and contacts, which live nowhere else.
            'address' => 'address',
            'contact_phone' => 'main_contact',
            'contact_email' => 'email',
        ]);
    });

    /**
     * The question that used to be asked over and over. A table naming the
     * branch already reaches the client through it, so there is nothing to ask
     * and no `via` to write.
     */
    it('asks nothing about a table that names only the branch', function (): void {
        $this->fakeContract(required: ['visits_7d']);

        $this->install([
            ...$this->connectAndIdentifyBranches(),
            // `visits` has no business_id at all. Before branches, this metric
            // demanded a warning and four more answers.
            ['visits_7d', 'visits'],
            ...$this->declineSubscriptions(),
        ])->assertSuccessful()->run();

        $mapping = $this->writtenConfig()['metrics']['visits_7d'];

        expect($mapping['table'])->toBe('visits')
            ->and($mapping)->not->toHaveKey('via');
    });

    it('still names the business column where a table has one', function (): void {
        $this->fakeContract(required: ['transactions_7d']);

        $this->install([
            ...$this->connectAndIdentifyBranches(),
            ['transactions_7d', 'sales'],
            ...$this->declineSubscriptions(),
        ])->assertSuccessful()->run();

        // `sales` knows both, so the client total uses the direct column and
        // the branch key only supplies the breakdown.
        expect($this->writtenConfig()['metrics']['transactions_7d']['via'])->toBe('business_id');
    });

    it('says nothing about branches for a product that has none', function (): void {
        $this->fakeContract(required: ['transactions_7d']);

        $this->install([
            ...$this->connectAndIdentifyTenant(),
            ['transactions_7d', 'sales'],
            ...$this->declineSubscriptions(),
        ])->assertSuccessful()->run();

        expect($this->writtenConfig()['clients']['branches'])->toBeNull();
    });
});
