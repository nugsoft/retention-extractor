<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Nugsoft\RetentionExtractor\Extraction\ClientResolver;
use Nugsoft\RetentionExtractor\Extraction\SnapshotBuilder;

/**
 * Counting the people using a product, wherever that product happens to record
 * it.
 *
 * `login_count_7d` is the metric every product is asked for and almost none
 * keeps in the same place. Laravel's `sessions` has no tenant column and stores
 * a unix integer; an audit trail has the tenant but mixes logins in with every
 * other event; some products record nothing at all. Three capabilities cover
 * the first two — a hop, a filter, and an integer window — and the third is a
 * question for Retention Intel rather than for the extractor.
 */
beforeEach(function (): void {
    multiTenantConfig();

    $this->acme = makeBusiness(['business_name' => 'Acme Clinic']);
    $this->other = makeBusiness(['business_name' => 'Rival Clinic']);
});

function makeUser(int $businessId, string $name): int
{
    return DB::table('users')->insertGetId(['business_id' => $businessId, 'name' => $name]);
}

function makeSession(int $userId, int $daysAgo): void
{
    DB::table('sessions')->insert([
        'id' => bin2hex(random_bytes(8)),
        'user_id' => $userId,
        'last_activity' => now()->subDays($daysAgo)->getTimestamp(),
    ]);
}

function makeAuditEntry(int $businessId, int $userId, string $action, int $daysAgo): void
{
    DB::table('audit_trail')->insert([
        'business_id' => $businessId,
        'user_id' => $userId,
        'action' => $action,
        'created_at' => now()->subDays($daysAgo),
        'updated_at' => now()->subDays($daysAgo),
    ]);
}

function loginCountFor(object $business): int
{
    $clients = iterator_to_array(app(ClientResolver::class)->all());

    $client = collect($clients)->firstWhere('externalId', (string) $business->id);

    return app(SnapshotBuilder::class)->activityPayload($client)['login_count_7d'];
}

describe('a unix timestamp column', function (): void {
    beforeEach(function (): void {
        config()->set('retention-extractor.metrics.login_count_7d', [
            'table' => 'sessions',
            'count' => '*',
            'via' => ['user_id' => ['users', 'id', 'business_id']],
            'date' => 'last_activity',
            'date_format' => 'timestamp',
        ]);
    });

    /**
     * The failure this exists to prevent. Bound as a datetime, MySQL casts the
     * string to 0 and every row in the table compares greater — so the metric
     * reports the whole table and looks entirely plausible doing it.
     */
    it('leaves out rows outside the window instead of counting them all', function (): void {
        $user = makeUser($this->acme->id, 'Nurse Grace');

        makeSession($user, daysAgo: 1);
        makeSession($user, daysAgo: 2);
        makeSession($user, daysAgo: 40);

        expect(loginCountFor($this->acme))->toBe(2);
    });

    it('reaches the tenant through the user who owns the session', function (): void {
        makeSession(makeUser($this->acme->id, 'Nurse Grace'), daysAgo: 1);
        makeSession(makeUser($this->other->id, 'Rival Nurse'), daysAgo: 1);
        makeSession(makeUser($this->other->id, 'Rival Doctor'), daysAgo: 1);

        expect(loginCountFor($this->acme))->toBe(1)
            ->and(loginCountFor($this->other))->toBe(2);
    });
});

describe('an audit trail holding every kind of event', function (): void {
    beforeEach(function (): void {
        config()->set('retention-extractor.metrics.login_count_7d', [
            'table' => 'audit_trail',
            'count' => '*',
            'via' => 'business_id',
            'date' => 'created_at',
            'where' => ['action' => 'login'],
        ]);
    });

    it('counts only the events that are actually logins', function (): void {
        makeAuditEntry($this->acme->id, 1, 'login', daysAgo: 1);
        makeAuditEntry($this->acme->id, 1, 'login', daysAgo: 2);
        makeAuditEntry($this->acme->id, 1, 'updated_patient', daysAgo: 1);
        makeAuditEntry($this->acme->id, 1, 'printed_report', daysAgo: 1);

        expect(loginCountFor($this->acme))->toBe(2);
    });

    it('takes several actions where a product names them differently', function (): void {
        config()->set('retention-extractor.metrics.login_count_7d.where', [
            'action' => ['login', 'signed_in'],
        ]);

        makeAuditEntry($this->acme->id, 1, 'login', daysAgo: 1);
        makeAuditEntry($this->acme->id, 1, 'signed_in', daysAgo: 1);
        makeAuditEntry($this->acme->id, 1, 'logout', daysAgo: 1);

        expect(loginCountFor($this->acme))->toBe(2);
    });

    it('still keeps one client\'s events away from another\'s', function (): void {
        makeAuditEntry($this->acme->id, 1, 'login', daysAgo: 1);
        makeAuditEntry($this->other->id, 2, 'login', daysAgo: 1);
        makeAuditEntry($this->other->id, 2, 'login', daysAgo: 1);

        expect(loginCountFor($this->acme))->toBe(1)
            ->and(loginCountFor($this->other))->toBe(2);
    });
});

describe('counting people rather than rows', function (): void {
    it('counts each person once however busy they were', function (): void {
        config()->set('retention-extractor.metrics.login_count_7d', [
            'table' => 'audit_trail',
            'distinct' => 'user_id',
            'via' => 'business_id',
            'date' => 'created_at',
            'where' => ['action' => 'login'],
        ]);

        // Grace signed in three times this week; Peter once. Two people used it.
        makeAuditEntry($this->acme->id, 10, 'login', daysAgo: 1);
        makeAuditEntry($this->acme->id, 10, 'login', daysAgo: 2);
        makeAuditEntry($this->acme->id, 10, 'login', daysAgo: 3);
        makeAuditEntry($this->acme->id, 11, 'login', daysAgo: 1);

        expect(loginCountFor($this->acme))->toBe(2);
    });

    it('counts rows when asked for rows, so the two readings stay distinct', function (): void {
        config()->set('retention-extractor.metrics.login_count_7d', [
            'table' => 'audit_trail',
            'count' => '*',
            'via' => 'business_id',
            'date' => 'created_at',
            'where' => ['action' => 'login'],
        ]);

        makeAuditEntry($this->acme->id, 10, 'login', daysAgo: 1);
        makeAuditEntry($this->acme->id, 10, 'login', daysAgo: 2);
        makeAuditEntry($this->acme->id, 10, 'login', daysAgo: 3);

        expect(loginCountFor($this->acme))->toBe(3);
    });
});

describe('last activity read from a unix column', function (): void {
    it('gives a real date rather than one in the eighteenth century', function (): void {
        config()->set('retention-extractor.last_activity', [
            'table' => 'sessions',
            'via' => ['user_id' => ['users', 'id', 'business_id']],
            'date' => 'last_activity',
            'date_format' => 'timestamp',
        ]);

        makeSession(makeUser($this->acme->id, 'Nurse Grace'), daysAgo: 3);

        $clients = iterator_to_array(app(ClientResolver::class)->all());
        $client = collect($clients)->firstWhere('externalId', (string) $this->acme->id);

        expect(app(SnapshotBuilder::class)->activityPayload($client)['last_activity_date'])
            ->toBe(now()->subDays(3)->toDateString());
    });
});
