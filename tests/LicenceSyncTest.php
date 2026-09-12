<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Nugsoft\RetentionExtractor\Licensing\LicenceApplier;
use Nugsoft\RetentionExtractor\Licensing\LicenceState;

/**
 * Clinic Plus's shape: one row per client, access as a word.
 */
function withStatusLicence(): void
{
    config()->set('retention-extractor.licence', [
        'table' => 'facilities',
        'via' => 'id',
        'primary_key' => 'id',
        'status' => ['column' => 'status', 'granted' => 'Active', 'revoked' => 'Suspend'],
        'ceiling' => null,
        'secret' => 'a-signing-key',
        'route' => 'api/retention/licence',
    ]);
}

/**
 * School Monitor's shape: a row per branch, reached through the branch table,
 * with access capped by a date rather than named by a word.
 */
function withCeilingLicence(): void
{
    config()->set('retention-extractor.licence', [
        'table' => 'branch_licences',
        'via' => ['business_branch_id' => ['business_branches', 'id', 'business_id']],
        'primary_key' => 'id',
        'status' => null,
        'ceiling' => ['column' => 'license_expires_at'],
        'where' => ['deleted_at' => null],
        'secret' => 'a-signing-key',
        'route' => 'api/retention/licence',
    ]);
}

function licencePayload(array $overrides = []): array
{
    return [
        'external_id' => '1',
        'product' => 'poscream',
        'grants_access' => true,
        'status' => 'active',
        'end_date' => now()->addYear()->toDateString(),
        'effective_end_date' => now()->addYear()->toDateString(),
        'licence_version' => 1,
        ...$overrides,
    ];
}

function applier(): LicenceApplier
{
    return new LicenceApplier(config('retention-extractor.licence', []));
}

describe('writing a word, the way Clinic Plus does', function (): void {
    beforeEach(function (): void {
        withStatusLicence();
        DB::table('facilities')->insert(['id' => 1, 'name' => 'Kampala Clinic', 'status' => 'Active']);
    });

    it('switches a client off', function (): void {
        applier()->apply(LicenceState::fromPayload(licencePayload(['grants_access' => false])));

        expect(DB::table('facilities')->where('id', 1)->value('status'))->toBe('Suspend');
    });

    it('switches one back on', function (): void {
        DB::table('facilities')->where('id', 1)->update(['status' => 'Suspend']);

        applier()->apply(LicenceState::fromPayload(licencePayload(['grants_access' => true])));

        expect(DB::table('facilities')->where('id', 1)->value('status'))->toBe('Active');
    });

    it('leaves other clients alone', function (): void {
        DB::table('facilities')->insert(['id' => 2, 'name' => 'Jinja Clinic', 'status' => 'Active']);

        applier()->apply(LicenceState::fromPayload(licencePayload(['grants_access' => false])));

        expect(DB::table('facilities')->where('id', 2)->value('status'))->toBe('Active');
    });

    it('says nothing changed for a client this product has never heard of', function (): void {
        $changed = applier()->apply(LicenceState::fromPayload(licencePayload(['external_id' => '999'])));

        expect($changed)->toBe(0);
    });
});

describe('capping a date, the way School Monitor does', function (): void {
    beforeEach(function (): void {
        withCeilingLicence();

        $business = makeBusiness();
        $this->businessId = $business->id;

        foreach (['Main', 'Annexe'] as $name) {
            $branchId = makeBranch($business->id, $name);
            DB::table('branch_licences')->insert([
                'business_branch_id' => $branchId,
                'end_date' => now()->addYear()->toDateString(),
                'license_expires_at' => null,
            ]);
        }
    });

    it('caps every branch of the client, because a client is on or off and never half', function (): void {
        applier()->apply(LicenceState::fromPayload(licencePayload([
            'external_id' => (string) $this->businessId,
            'grants_access' => false,
        ])));

        $ceilings = DB::table('branch_licences')->pluck('license_expires_at');

        expect($ceilings)->toHaveCount(2)
            ->and($ceilings->every(fn ($date): bool => $date !== null))->toBeTrue();
    });

    it('caps to yesterday, so access ends rather than being extended', function (): void {
        applier()->apply(LicenceState::fromPayload(licencePayload([
            'external_id' => (string) $this->businessId,
            'grants_access' => false,
        ])));

        expect(DB::table('branch_licences')->value('license_expires_at'))
            ->toStartWith(now()->subDay()->toDateString());
    });

    it('clears the cap to restore access, rather than writing our own end date', function (): void {
        // Writing our date would let this package hand a school a term it was
        // never paid for. Clearing it puts the product's own term back in
        // charge, so a mistake here can only ever shorten access.
        DB::table('branch_licences')->update(['license_expires_at' => now()->subDay()->toDateString()]);

        applier()->apply(LicenceState::fromPayload(licencePayload([
            'external_id' => (string) $this->businessId,
            'grants_access' => true,
        ])));

        expect(DB::table('branch_licences')->value('license_expires_at'))->toBeNull()
            ->and(DB::table('branch_licences')->value('end_date'))
            ->toStartWith(now()->addYear()->toDateString());
    });
});

describe('a delivery that arrives out of order', function (): void {
    beforeEach(function (): void {
        withStatusLicence();
        DB::table('facilities')->insert(['id' => 1, 'name' => 'Kampala Clinic', 'status' => 'Active']);
    });

    it('is recognised as stale once a newer one has been applied', function (): void {
        applier()->apply(LicenceState::fromPayload(licencePayload(['licence_version' => 5, 'grants_access' => false])));

        expect(applier()->isStale(LicenceState::fromPayload(licencePayload(['licence_version' => 3]))))->toBeTrue();
    });

    it('accepts the same version again, so a retry of the current state still lands', function (): void {
        applier()->apply(LicenceState::fromPayload(licencePayload(['licence_version' => 5])));

        expect(applier()->isStale(LicenceState::fromPayload(licencePayload(['licence_version' => 5]))))->toBeFalse();
    });

    it('treats a client it has never applied as not stale', function (): void {
        expect(applier()->isStale(LicenceState::fromPayload(licencePayload(['licence_version' => 1]))))->toBeFalse();
    });
});

describe('the webhook', function (): void {
    beforeEach(function (): void {
        withStatusLicence();
        DB::table('facilities')->insert(['id' => 1, 'name' => 'Kampala Clinic', 'status' => 'Active']);
    });

    it('applies a correctly signed licence', function (): void {
        $body = (string) json_encode(licencePayload(['grants_access' => false]));

        postLicence($body, hash_hmac('sha256', $body, 'a-signing-key'))
            ->assertSuccessful()
            ->assertJsonPath('applied', true);

        expect(DB::table('facilities')->where('id', 1)->value('status'))->toBe('Suspend');
    });

    it('refuses one signed with the wrong secret', function (): void {
        $body = (string) json_encode(licencePayload(['grants_access' => false]));

        postLicence($body, hash_hmac('sha256', $body, 'not-the-key'))->assertUnauthorized();

        expect(DB::table('facilities')->where('id', 1)->value('status'))->toBe('Active');
    });

    it('refuses one with no signature at all', function (): void {
        $body = (string) json_encode(licencePayload());

        postLicence($body, null)->assertUnauthorized();
    });

    it('refuses a body that was tampered with after signing', function (): void {
        $signed = (string) json_encode(licencePayload(['grants_access' => false]));
        $signature = hash_hmac('sha256', $signed, 'a-signing-key');

        $tampered = (string) json_encode(licencePayload(['grants_access' => true]));

        postLicence($tampered, $signature)->assertUnauthorized();
    });

    it('reports back what it now believes, read out of its own tables', function (): void {
        $body = (string) json_encode(licencePayload(['grants_access' => false]));

        postLicence($body, hash_hmac('sha256', $body, 'a-signing-key'))
            ->assertSuccessful()
            ->assertJsonPath('grants_access', false);

        $body = (string) json_encode(licencePayload(['licence_version' => 2, 'grants_access' => true]));

        postLicence($body, hash_hmac('sha256', $body, 'a-signing-key'))
            ->assertSuccessful()
            ->assertJsonPath('grants_access', true);
    });

    it('reports no belief about a client it does not have', function (): void {
        $body = (string) json_encode(licencePayload(['external_id' => '404', 'grants_access' => false]));

        postLicence($body, hash_hmac('sha256', $body, 'a-signing-key'))
            ->assertSuccessful()
            ->assertJsonPath('rows_changed', 0)
            ->assertJsonPath('grants_access', null);
    });

    it('acknowledges a stale delivery rather than asking to be retried', function (): void {
        applier()->apply(LicenceState::fromPayload(licencePayload(['licence_version' => 9, 'grants_access' => false])));

        $body = (string) json_encode(licencePayload(['licence_version' => 2, 'grants_access' => true]));

        postLicence($body, hash_hmac('sha256', $body, 'a-signing-key'))
            ->assertSuccessful()
            ->assertJsonPath('applied', false)
            // Refused, and still says what it holds. The sender is provably
            // behind here, so this is the reading it most needs.
            ->assertJsonPath('grants_access', false);

        // The newer suspension stands.
        expect(DB::table('facilities')->where('id', 1)->value('status'))->toBe('Suspend');
    });
});

describe('asking for the current state', function (): void {
    beforeEach(function (): void {
        withStatusLicence();
        DB::table('facilities')->insert(['id' => 1, 'name' => 'Kampala Clinic', 'status' => 'Active']);
    });

    it('applies what NugsoftOS says, healing a webhook nobody received', function (): void {
        Http::fake(['*/api/v1/licence*' => Http::response([
            'product' => 'poscream',
            'licences' => [licencePayload(['grants_access' => false, 'licence_version' => 4])],
        ])]);

        test()->artisan('retention:sync-licence')->assertSuccessful();

        expect(DB::table('facilities')->where('id', 1)->value('status'))->toBe('Suspend');
    });

    it('applies even a version older than the last webhook, because it asked', function (): void {
        // A pull carries the current answer rather than a message, so there is
        // no such thing as a stale one.
        applier()->apply(LicenceState::fromPayload(licencePayload(['licence_version' => 9])));

        Http::fake(['*/api/v1/licence*' => Http::response([
            'licences' => [licencePayload(['grants_access' => false, 'licence_version' => 2])],
        ])]);

        test()->artisan('retention:sync-licence')->assertSuccessful();

        expect(DB::table('facilities')->where('id', 1)->value('status'))->toBe('Suspend');
    });

    it('writes nothing on a dry run', function (): void {
        Http::fake(['*/api/v1/licence*' => Http::response([
            'licences' => [licencePayload(['grants_access' => false])],
        ])]);

        test()->artisan('retention:sync-licence --dry-run')->assertSuccessful();

        expect(DB::table('facilities')->where('id', 1)->value('status'))->toBe('Active');
    });

    it('fails loudly when the product has not been configured', function (): void {
        config()->set('retention-extractor.licence.table', null);

        test()->artisan('retention:sync-licence')->assertFailed();
    });
});

describe('a branch that has renewed more than once', function (): void {
    beforeEach(function (): void {
        withCeilingLicence();

        $business = makeBusiness();
        $this->businessId = $business->id;
        $branchId = makeBranch($business->id, 'Main');

        // What a renewing product accumulates: a term that has ended, the one
        // running now, and one queued to start when it finishes. School
        // Monitor creates a row per renewal rather than editing the old one,
        // and Clinic Plus does the same — reading only one of these is how a
        // paid-up client gets switched off.
        DB::table('branch_licences')->insert([
            ['business_branch_id' => $branchId, 'end_date' => now()->subYear()->toDateString(), 'license_expires_at' => null],
            ['business_branch_id' => $branchId, 'end_date' => now()->addMonths(3)->toDateString(), 'license_expires_at' => null],
            ['business_branch_id' => $branchId, 'end_date' => now()->addMonths(15)->toDateString(), 'license_expires_at' => null],
        ]);
    });

    it('caps every term, including the one queued to start later', function (): void {
        // A suspension that left the queued renewal uncapped would switch the
        // client back on by itself the day the new term began.
        applier()->apply(LicenceState::fromPayload(licencePayload([
            'external_id' => (string) $this->businessId,
            'grants_access' => false,
        ])));

        $uncapped = DB::table('branch_licences')->whereNull('license_expires_at')->count();

        expect(DB::table('branch_licences')->count())->toBe(3)
            ->and($uncapped)->toBe(0);
    });

    it('clears every cap on the way back, so the queue resumes', function (): void {
        applier()->apply(LicenceState::fromPayload(licencePayload([
            'external_id' => (string) $this->businessId,
            'grants_access' => false,
        ])));

        applier()->apply(LicenceState::fromPayload(licencePayload([
            'external_id' => (string) $this->businessId,
            'grants_access' => true,
            'licence_version' => 2,
        ])));

        expect(DB::table('branch_licences')->whereNotNull('license_expires_at')->count())->toBe(0)
            ->and(DB::table('branch_licences')->orderByDesc('end_date')->value('end_date'))
            ->toStartWith(now()->addMonths(15)->toDateString());
    });
});

describe('reading back what this product currently allows', function (): void {
    beforeEach(function (): void {
        withStatusLicence();
        DB::table('facilities')->insert(['id' => 1, 'name' => 'Kampala Clinic', 'status' => 'Active']);
    });

    it('says a client may work when nothing has switched them off', function (): void {
        expect(applier()->grantsAccess('1'))->toBeTrue();
    });

    it('says they may not once they have been switched off', function (): void {
        applier()->apply(LicenceState::fromPayload(licencePayload(['grants_access' => false])));

        expect(applier()->grantsAccess('1'))->toBeFalse();
    });

    it('answers null for a client this product has never heard of', function (): void {
        // Not the same as "no". A caller guessing on a client's behalf is how
        // somebody gets switched off for being unknown.
        expect(applier()->grantsAccess('999'))->toBeNull();
    });
});

describe('reading back a capped term', function (): void {
    beforeEach(function (): void {
        withCeilingLicence();

        $business = makeBusiness();
        $this->businessId = (string) $business->id;

        foreach (['Main', 'Annexe'] as $name) {
            $branchId = makeBranch($business->id, $name);
            DB::table('branch_licences')->insert([
                'business_branch_id' => $branchId,
                'end_date' => now()->addYear()->toDateString(),
                'license_expires_at' => null,
            ]);
        }
    });

    it('says they may work while no branch is capped', function (): void {
        expect(applier()->grantsAccess($this->businessId))->toBeTrue();
    });

    it('ignores a row the product has deleted', function (): void {
        // Soft-deleted, so the product never reads it. Counting its stale cap
        // would report a restriction nobody is enforcing — a disagreement on
        // the register that no amount of re-sending could clear.
        DB::table('branch_licences')->insert([
            'business_branch_id' => makeBranch((int) $this->businessId, 'Closed campus'),
            'end_date' => now()->subYear()->toDateString(),
            'license_expires_at' => now()->subMonths(6)->toDateString(),
            'deleted_at' => now(),
        ]);

        expect(applier()->grantsAccess($this->businessId))->toBeTrue();
    });

    it('does not write to a row the product has deleted', function (): void {
        $deletedId = DB::table('branch_licences')->insertGetId([
            'business_branch_id' => makeBranch((int) $this->businessId, 'Closed campus'),
            'end_date' => now()->subYear()->toDateString(),
            'license_expires_at' => null,
            'deleted_at' => now(),
        ]);

        applier()->apply(LicenceState::fromPayload(licencePayload([
            'external_id' => $this->businessId,
            'grants_access' => false,
        ])));

        expect(DB::table('branch_licences')->where('id', $deletedId)->value('license_expires_at'))->toBeNull();
    });

    it('says they may not when the cap has been applied', function (): void {
        applier()->apply(LicenceState::fromPayload(licencePayload([
            'external_id' => $this->businessId,
            'grants_access' => false,
        ])));

        expect(applier()->grantsAccess($this->businessId))->toBeFalse();
    });

    /*
     * A client is on or off, never half. One branch capped means somebody
     * stopped this client working, whatever the others say.
     */
    it('says they may not when only one branch is capped', function (): void {
        DB::table('branch_licences')->limit(1)->update(['license_expires_at' => now()->subDay()->toDateString()]);

        expect(applier()->grantsAccess($this->businessId))->toBeFalse();
    });
});
