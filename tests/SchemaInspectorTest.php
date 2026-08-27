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
