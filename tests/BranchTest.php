<?php

declare(strict_types=1);

use Nugsoft\RetentionExtractor\Exceptions\ConfigurationException;
use Nugsoft\RetentionExtractor\Extraction\ClientResolver;
use Nugsoft\RetentionExtractor\Extraction\SnapshotBuilder;

/**
 * Branches beneath a client.
 *
 * Nearly every product here is multi-tenant AND multi-branch, and the records
 * hang off the branch: 103 of School Monitor's tables carry `school_branch_id`
 * and 15 carry `school_id`. Without this a client's figure could fall by a
 * third and nothing could say which branch had stopped.
 *
 * A branch is never a client. Retention Intel keeps one health score and one
 * watchlist entry per business; this is the breakdown behind that number.
 */
beforeEach(function (): void {
    multiTenantConfig();

    $this->business = makeBusiness(['business_name' => 'Kampala Retail Ltd']);
});

function clientRecord(): object
{
    return iterator_to_array(app(ClientResolver::class)->all())[0];
}

describe('finding a client\'s branches', function (): void {
    it('finds none when the product does not record them', function (): void {
        makeBranch($this->business->id, 'Kyanja');

        // No `branches` mapping: the product may have the table and still not
        // have said it means anything.
        expect(clientRecord()->hasBranches())->toBeFalse();
    });

    it('finds each branch once the mapping says where they are', function (): void {
        withBranches();

        makeBranch($this->business->id, 'Kyanja');
        makeBranch($this->business->id, 'Ntinda');

        expect(clientRecord()->branches)->toHaveCount(2)
            ->and(collect(clientRecord()->branches)->pluck('name')->all())->toBe(['Kyanja', 'Ntinda']);
    });

    it('never hands one client another client\'s branches', function (): void {
        withBranches();

        $other = makeBusiness(['business_name' => 'Jinja Traders Co']);

        makeBranch($this->business->id, 'Kyanja');
        makeBranch($other->id, 'Jinja Main');
        makeBranch($other->id, 'Jinja Second');

        $clients = iterator_to_array(app(ClientResolver::class)->all());

        expect($clients[0]->branches)->toHaveCount(1)
            ->and($clients[1]->branches)->toHaveCount(2);
    });
});

describe('a table that names both the business and the branch', function (): void {
    beforeEach(function (): void {
        withBranches();

        $this->kyanja = makeBranch($this->business->id, 'Kyanja');
        $this->ntinda = makeBranch($this->business->id, 'Ntinda');
    });

    it('gives each branch its own share', function (): void {
        makeBranchSale($this->business->id, $this->kyanja, 100_000, daysAgo: 1);
        makeBranchSale($this->business->id, $this->kyanja, 100_000, daysAgo: 2);
        makeBranchSale($this->business->id, $this->ntinda, 100_000, daysAgo: 1);

        $payload = app(SnapshotBuilder::class)->activityPayload(clientRecord());

        $byName = collect($payload['branches'])->keyBy('name');

        expect($byName['Kyanja']['transactions_7d'])->toBe(2)
            ->and($byName['Ntinda']['transactions_7d'])->toBe(1);
    });

    it('still reports the business total in its own right', function (): void {
        makeBranchSale($this->business->id, $this->kyanja, 100_000, daysAgo: 1);
        makeBranchSale($this->business->id, $this->ntinda, 100_000, daysAgo: 1);

        expect(app(SnapshotBuilder::class)->activityPayload(clientRecord())['transactions_7d'])->toBe(2);
    });

    /**
     * The client's own figure is queried, not summed from the branches. A
     * product whose two readings disagree — a sale recorded against the
     * business and no branch — is worth knowing about, and quietly replacing
     * one with the other would hide it.
     */
    it('does not derive the business total by adding the branches up', function (): void {
        makeBranchSale($this->business->id, $this->kyanja, 100_000, daysAgo: 1);

        // Belongs to the business and to no branch at all.
        makeSale($this->business->id, 100_000, daysAgo: 1);

        $payload = app(SnapshotBuilder::class)->activityPayload(clientRecord());

        $branchTotal = collect($payload['branches'])->sum('transactions_7d');

        expect($payload['transactions_7d'])->toBe(2)
            ->and($branchTotal)->toBe(1);
    });
});

describe('a table that knows only the branch', function (): void {
    beforeEach(function (): void {
        withBranches();

        $this->kyanja = makeBranch($this->business->id, 'Kyanja');
        $this->ntinda = makeBranch($this->business->id, 'Ntinda');

        // `visits` has no business_id — the shape most of School Monitor is in.
        config()->set('retention-extractor.metrics.visits_7d', [
            'table' => 'visits',
            'count' => '*',
            'date' => 'created_at',
        ]);
    });

    it('counts the business as the sum of its branches', function (): void {
        makeVisit($this->kyanja, daysAgo: 1);
        makeVisit($this->kyanja, daysAgo: 2);
        makeVisit($this->ntinda, daysAgo: 1);

        expect(app(SnapshotBuilder::class)->activityPayload(clientRecord())['visits_7d'])->toBe(3);
    });

    it('still keeps one client\'s branches out of another\'s total', function (): void {
        $other = makeBusiness(['business_name' => 'Jinja Traders Co']);
        $jinja = makeBranch($other->id, 'Jinja Main');

        makeVisit($this->kyanja, daysAgo: 1);
        makeVisit($jinja, daysAgo: 1);
        makeVisit($jinja, daysAgo: 1);

        $clients = iterator_to_array(app(ClientResolver::class)->all());
        $builder = app(SnapshotBuilder::class);

        expect($builder->activityPayload($clients[0])['visits_7d'])->toBe(1)
            ->and($builder->activityPayload($clients[1])['visits_7d'])->toBe(2);
    });

    /**
     * Without branches this mapping is refused, and rightly — a table with no
     * way to the client counted whole would report everybody's rows to
     * everybody. The branches are what make it answerable.
     */
    it('is refused when the product records no branches to reach through', function (): void {
        config()->set('retention-extractor.clients.branches', null);

        expect(fn () => app(SnapshotBuilder::class)->activityPayload(clientRecord()))
            ->toThrow(ConfigurationException::class, 'metrics.visits_7d.via');
    });
});

describe('what the payload carries', function (): void {
    it('says nothing about branches when there are none', function (): void {
        makeSale($this->business->id, 100_000, daysAgo: 1);

        expect(app(SnapshotBuilder::class)->activityPayload(clientRecord()))->not->toHaveKey('branches');
    });

    it('gives each branch an identifier and a name of its own', function (): void {
        withBranches();

        $kyanja = makeBranch($this->business->id, 'Kyanja');

        makeBranchSale($this->business->id, $kyanja, 100_000, daysAgo: 1);

        $branch = app(SnapshotBuilder::class)->activityPayload(clientRecord())['branches'][0];

        expect($branch['external_id'])->toBe((string) $kyanja)
            ->and($branch['name'])->toBe('Kyanja')
            ->and($branch['last_activity_date'])->toBe(now()->subDay()->toDateString());
    });

    it('dates a branch that has never done anything as long dormant', function (): void {
        withBranches();

        makeBranch($this->business->id, 'Never Opened');

        $branch = app(SnapshotBuilder::class)->activityPayload(clientRecord())['branches'][0];

        expect($branch['last_activity_date'])->toBe(now()->subYear()->toDateString())
            ->and($branch['transactions_7d'])->toBe(0);
    });
});
