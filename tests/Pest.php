<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Nugsoft\RetentionExtractor\Tests\Fixtures\Business;
use Nugsoft\RetentionExtractor\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
|
| Shared by every test file. These were defined inside ExtractionTest.php,
| which meant any other file using them only worked when that one happened to
| have been loaded first — running a single file on its own failed.
|
*/

/**
 * The mapping a real multi-tenant POS product would write: three metrics with a
 * direct tenant column, and one that has to hop through `sales` to find it.
 */
function multiTenantConfig(): void
{
    config()->set('retention-extractor.clients', [
        'model' => Business::class,
        'external_id' => 'id',
        'name' => 'business_name',
        'contact_phone' => 'phone',
        'contact_email' => 'email',
        'scope' => null,
        'single' => [],
    ]);

    config()->set('retention-extractor.last_activity', [
        'table' => 'sales', 'via' => 'business_id', 'date' => 'created_at',
    ]);

    config()->set('retention-extractor.metrics', [
        'login_count_7d' => ['table' => 'sessions_log', 'count' => '*', 'via' => 'business_id', 'date' => 'created_at'],
        'transactions_7d' => ['table' => 'sales', 'count' => '*', 'via' => 'business_id', 'date' => 'created_at'],
        'transaction_value_7d' => ['table' => 'sales', 'sum' => 'total', 'via' => 'business_id', 'date' => 'created_at'],
        'items_sold_7d' => [
            'table' => 'sale_items',
            'sum' => 'quantity',
            'via' => ['sale_id' => ['sales', 'id', 'business_id']],
            'date' => 'created_at',
        ],
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeBusiness(array $attributes = []): Business
{
    return Business::create([
        'business_name' => 'Kampala Retail Ltd',
        'phone' => '+256700000001',
        'email' => 'owner@kampalaretail.com',
        'is_active' => true,
        ...$attributes,
    ]);
}

function makeSale(int $businessId, float $total, int $daysAgo = 0, int $items = 0): int
{
    $saleId = DB::table('sales')->insertGetId([
        'business_id' => $businessId,
        'total' => $total,
        'created_at' => now()->subDays($daysAgo),
        'updated_at' => now()->subDays($daysAgo),
    ]);

    if ($items > 0) {
        DB::table('sale_items')->insert([
            'sale_id' => $saleId,
            'quantity' => $items,
            'created_at' => now()->subDays($daysAgo),
            'updated_at' => now()->subDays($daysAgo),
        ]);
    }

    return $saleId;
}

/**
 * A branch beneath a business, and the sale/visit rows that belong to it.
 */
function makeBranch(int $businessId, string $name): int
{
    return DB::table('business_branches')->insertGetId([
        'business_id' => $businessId,
        'name' => $name,
    ]);
}

function makeBranchSale(int $businessId, int $branchId, float $total, int $daysAgo = 0): int
{
    return DB::table('sales')->insertGetId([
        'business_id' => $businessId,
        'business_branch_id' => $branchId,
        'total' => $total,
        'created_at' => now()->subDays($daysAgo),
        'updated_at' => now()->subDays($daysAgo),
    ]);
}

/**
 * A visit knows only which BRANCH it belongs to — the shape School Monitor is
 * in, where the business must be reached through its branches.
 */
function makeVisit(int $branchId, int $daysAgo = 0): void
{
    DB::table('visits')->insert([
        'business_branch_id' => $branchId,
        'created_at' => now()->subDays($daysAgo),
        'updated_at' => now()->subDays($daysAgo),
    ]);
}

/**
 * The mapping a branch-aware product writes.
 */
function withBranches(): void
{
    config()->set('retention-extractor.clients.branches', [
        'table' => 'business_branches',
        'via' => 'business_id',
        'external_id' => 'id',
        'name' => 'name',
        'key' => 'business_branch_id',
    ]);
}
