<?php

declare(strict_types=1);

use Nugsoft\RetentionExtractor\Support\SchemaInspector;

/**
 * The inspector reads the schema to save the developer typing. Reading the
 * WRONG schema is worse than reading none: it proposed a tenant table belonging
 * to an unrelated application, and the install then stalled on a prompt with no
 * options, which cannot be answered or escaped.
 */
describe('reading the schema', function (): void {
    it('lists only the tables in the product\'s own database', function (): void {
        $tables = app(SchemaInspector::class)->tables();

        expect($tables)
            ->toContain('businesses', 'sales', 'sale_items', 'sessions_log', 'subscriptions')
            ->and(array_unique($tables))->toBe($tables);
    });

    it('says whether a table is one this product actually has', function (): void {
        $inspector = app(SchemaInspector::class);

        expect($inspector->hasTable('sales'))->toBeTrue()
            ->and($inspector->hasTable('facilities'))->toBeFalse();
    });

    /**
     * The guard that matters: every name offered during install must be a table
     * whose columns can actually be read. A name with no columns is what
     * produced the unanswerable prompt.
     */
    it('offers no table whose columns cannot be read', function (): void {
        $inspector = app(SchemaInspector::class);

        $empty = array_values(array_filter(
            $inspector->tables(),
            fn (string $table): bool => $inspector->columns($table) === [],
        ));

        expect($empty)->toBe([], 'tables offered with no readable columns: '.implode(', ', $empty));
    });
});

describe('guessing the tenant', function (): void {
    it('prefers an unambiguous tenant word over clients or customers', function (): void {
        // The fixture schema has `businesses`, which must win outright.
        expect(app(SchemaInspector::class)->guessTenantTable())->toBe('businesses');
    });

    it('finds the tenant column on a table that carries one', function (): void {
        expect(app(SchemaInspector::class)->guessTenantKey('sales', 'businesses'))->toBe('business_id');
    });

    it('returns null for a table with no way back to the tenant', function (): void {
        // sale_items reaches the tenant only through sales — the caller has to
        // describe that hop, and a null here is what tells them to.
        expect(app(SchemaInspector::class)->guessTenantKey('sale_items', 'businesses'))->toBeNull();
    });
});

/**
 * Reading the schema to tell which table holds a metric.
 *
 * This does much less than it first appears able to, deliberately. Six School
 * Monitor tables carry the same tenant column and the same timestamp, and only
 * one is the academic entries — structure cannot separate them and neither can
 * a shared word, which is how a first attempt proposed `journal_entries` for
 * academic entries and `learner_medical_records` for attendance. Both were
 * already selected, and both would have been accepted.
 *
 * So it proposes only where the metric's whole name is accounted for, and
 * otherwise proposes nothing and lets the developer be asked. Its real win is
 * the case no list of exact names could have caught: `fee_payments_7d` reading
 * `fees_payments`, a difference of one letter.
 */
describe('ranking tables for a metric', function (): void {
    it('reads a table whose name is the metric, give or take a plural', function (): void {
        expect(app(SchemaInspector::class)->rankForMetric('sale_count_7d', ['subscriptions', 'sales'], 'businesses'))
            ->toBe(['sales']);
    });

    it('prefers the table that adds nothing of its own', function (): void {
        // `fee_payments_7d` matches both `fees_payments` and
        // `fees_payment_details`; the one meant is the one that is only that.
        $inspector = app(SchemaInspector::class);

        expect($inspector->rankForMetric('subscription_count_7d', ['sales', 'subscriptions'], 'businesses'))
            ->toBe(['subscriptions']);
    });

    it('proposes nothing when the name does not say so, however apt the shape', function (): void {
        $inspector = app(SchemaInspector::class);

        // `sessions_log` is reachable and dated and is very probably right —
        // but it does not say "login", and being nearly right is what produced
        // the wrong answers this exists to stop.
        expect($inspector->rankForMetric('login_count_7d', ['sessions', 'sessions_log'], 'businesses'))->toBe([])
            ->and($inspector->rankForMetric('items_sold_7d', ['sale_items', 'sales'], 'businesses'))->toBe([]);
    });

    it('rules out a table with no way back to the client', function (): void {
        // sale_items reaches the tenant only through sales, so it cannot be
        // counted per client on its own however apt its name.
        expect(app(SchemaInspector::class)->rankForMetric('sale_item_count_7d', ['sale_items'], 'businesses'))
            ->toBe([]);
    });

    it('rules out a table with nothing to window by', function (): void {
        // `sessions` has neither a tenant column nor a usable date — exactly
        // the table the installer used to propose for a login count.
        expect(app(SchemaInspector::class)->rankForMetric('session_count_7d', ['sessions'], 'businesses'))
            ->toBe([]);
    });

    it('does not ask a single-tenant product to reach anything', function (): void {
        expect(app(SchemaInspector::class)->rankForMetric('sale_item_count_7d', ['sale_items'], null))
            ->toBe(['sale_items']);
    });
});

/**
 * The find underneath all of this, and the worst of the three.
 */
describe('finding the column that reaches the tenant', function (): void {
    it('never accepts a column that merely looks like an id', function (): void {
        // In School Monitor, `fees_payment_details` has no school_id and does
        // have `account_id` — a chart-of-accounts reference. That used to be
        // returned as the way to the school. Grouped by it, every figure from
        // that table would have been wrong and reported as fact.
        expect(app(SchemaInspector::class)->guessTenantKey('sale_items', 'businesses'))->toBeNull();
    });

    it('still finds the column derived from the tenant table', function (): void {
        expect(app(SchemaInspector::class)->guessTenantKey('sales', 'businesses'))->toBe('business_id');
    });

    it('falls back to a generic name only when there is no tenant table to derive from', function (): void {
        expect(app(SchemaInspector::class)->guessTenantKey('sales', null))->toBe('business_id');
    });
});
