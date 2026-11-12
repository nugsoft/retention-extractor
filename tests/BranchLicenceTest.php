<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Nugsoft\RetentionExtractor\Exceptions\ConfigurationException;
use Nugsoft\RetentionExtractor\Extraction\ClientResolver;
use Nugsoft\RetentionExtractor\Extraction\SnapshotBuilder;
use Nugsoft\RetentionExtractor\Licensing\LicenceApplier;
use Nugsoft\RetentionExtractor\Licensing\LicenceState;
use Nugsoft\RetentionExtractor\Tests\Fixtures\RecordingLicenceDriver;

/*
 * A product that bills each branch on its own. PosCream keeps a licence row
 * per branch with its own end date, so NugsoftOS keeps one licence per branch,
 * and both directions of the sync have to name the branch.
 */

beforeEach(function (): void {
    RecordingLicenceDriver::$access = [];
});

describe('applying a branch licence', function (): void {
    beforeEach(function (): void {
        $this->asDriverInstall(RecordingLicenceDriver::class);
    });

    it('switches the one branch it names, and answers for that branch', function (): void {
        RecordingLicenceDriver::$access = ['1/10' => true, '1/11' => true];

        $body = (string) json_encode(licencePayload(['branch_external_id' => '10', 'grants_access' => false]));

        postLicence($body, hash_hmac('sha256', $body, 'a-signing-key'))
            ->assertSuccessful()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('grants_access', false);

        expect(RecordingLicenceDriver::$access)->toBe(['1/10' => false, '1/11' => true]);
    });

    it('keeps each branch\'s version apart from its neighbour\'s and the client\'s', function (): void {
        $applier = new LicenceApplier(['driver' => RecordingLicenceDriver::class]);

        $applier->apply(LicenceState::fromPayload(licencePayload(['licence_version' => 7])));
        $applier->apply(LicenceState::fromPayload(licencePayload(['branch_external_id' => '10', 'licence_version' => 4])));

        $neighbour = LicenceState::fromPayload(licencePayload(['branch_external_id' => '11', 'licence_version' => 1]));
        $older = LicenceState::fromPayload(licencePayload(['branch_external_id' => '10', 'licence_version' => 3]));

        expect($applier->isStale($neighbour))->toBeFalse()
            ->and($applier->isStale($older))->toBeTrue()
            ->and(DB::table('retention_licences')->where('external_id', '1')->count())->toBe(2);
    });

    it('says the branch is not here, rather than applied, when the driver does not have it', function (): void {
        $body = (string) json_encode(licencePayload(['branch_external_id' => '99']));

        postLicence($body, hash_hmac('sha256', $body, 'a-signing-key'))
            ->assertSuccessful()
            ->assertJsonPath('rows_written', 0)
            ->assertJsonPath('message', 'No matching branch here.');

        expect(DB::table('retention_licences')->count())->toBe(0);
    });
});

describe('a branch licence without a driver', function (): void {
    beforeEach(function (): void {
        $this->business = makeBusiness();

        $this->applier = new LicenceApplier([
            'table' => 'businesses',
            'via' => 'id',
            'status' => ['column' => 'is_active', 'granted' => 1, 'revoked' => 0],
        ]);
    });

    it('refuses it, because the table mapping would switch off every branch', function (): void {
        $this->applier->apply(LicenceState::fromPayload(licencePayload([
            'external_id' => (string) $this->business->id,
            'branch_external_id' => '10',
            'grants_access' => false,
        ])));
    })->throws(ConfigurationException::class);

    it('leaves the client alone when it refuses', function (): void {
        try {
            $this->applier->apply(LicenceState::fromPayload(licencePayload([
                'external_id' => (string) $this->business->id,
                'branch_external_id' => '10',
                'grants_access' => false,
            ])));
        } catch (ConfigurationException) {
        }

        expect((bool) DB::table('businesses')->value('is_active'))->toBeTrue();
    });

    it('cannot say whether a branch may work', function (): void {
        expect($this->applier->grantsAccess((string) $this->business->id, '10'))->toBeNull()
            ->and($this->applier->grantsAccess((string) $this->business->id))->toBeTrue();
    });
});

describe('reporting each branch\'s term', function (): void {
    beforeEach(function (): void {
        multiTenantConfig();
        withBranches();

        $this->business = makeBusiness();
        $this->kyanja = makeBranch($this->business->id, 'Kyanja');
        $this->ntinda = makeBranch($this->business->id, 'Ntinda');

        config()->set('retention-extractor.subscription', [
            'table' => 'branch_licences',
            'via' => ['business_branch_id' => ['business_branches', 'id', 'business_id']],
            'branch' => 'business_branch_id',
            'start' => 'start_date',
            'end' => 'end_date',
        ]);
    });

    function branchTerm(int $branchId, int $endsInDays, int $startedDaysAgo = 300): void
    {
        DB::table('branch_licences')->insert([
            'business_branch_id' => $branchId,
            'start_date' => now()->subDays($startedDaysAgo)->toDateString(),
            'end_date' => now()->addDays($endsInDays)->toDateString(),
        ]);
    }

    function branchPayloads(): array
    {
        $client = iterator_to_array(app(ClientResolver::class)->all())[0];

        return collect(app(SnapshotBuilder::class)->subscriptionPayloads($client))
            ->keyBy('branch_external_id')
            ->all();
    }

    it('reports every branch with its own term, the latest of its own rows', function (): void {
        branchTerm($this->kyanja, endsInDays: -400, startedDaysAgo: 800);
        branchTerm($this->kyanja, endsInDays: 120);
        branchTerm($this->ntinda, endsInDays: -30);

        $payloads = branchPayloads();

        expect($payloads)->toHaveCount(2)
            ->and($payloads[(string) $this->kyanja]['end_date'])->toBe(now()->addDays(120)->toDateString())
            ->and($payloads[(string) $this->kyanja]['status'])->toBe('active')
            ->and($payloads[(string) $this->ntinda]['end_date'])->toBe(now()->subDays(30)->toDateString())
            ->and($payloads[(string) $this->ntinda]['status'])->toBe('expired')
            ->and($payloads[(string) $this->ntinda]['external_id'])->toBe((string) $this->business->id);
    });

    it('leaves out a branch with no term recorded', function (): void {
        branchTerm($this->kyanja, endsInDays: 120);

        expect(array_column(branchPayloads(), 'branch_external_id'))->toBe([(string) $this->kyanja]);
    });

    it('calls a branch switched off with time left suspended, and one past its term expired', function (): void {
        config()->set('retention-extractor.licence', ['driver' => RecordingLicenceDriver::class]);

        branchTerm($this->kyanja, endsInDays: 120);
        branchTerm($this->ntinda, endsInDays: -30);

        $client = (string) $this->business->id;
        RecordingLicenceDriver::$access = [
            RecordingLicenceDriver::key($client, (string) $this->kyanja) => false,
            RecordingLicenceDriver::key($client, (string) $this->ntinda) => false,
        ];

        $payloads = branchPayloads();

        expect($payloads[(string) $this->kyanja]['status'])->toBe('suspended')
            ->and($payloads[(string) $this->ntinda]['status'])->toBe('expired');
    });

    it('sends every branch\'s term even when one of them is refused', function (): void {
        branchTerm($this->kyanja, endsInDays: 120);
        branchTerm($this->ntinda, endsInDays: 60);

        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/api/v1/subscription') && $request['branch_external_id'] === (string) $this->kyanja) {
                return Http::response(['message' => 'The end date field must be a date after start date.'], 422);
            }

            return Http::response(['message' => 'ok']);
        });

        // The client is reported as failed, for the branch that was refused.
        $this->artisan('retention:push')->assertExitCode(1);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/v1/subscription')
            && $request['branch_external_id'] === (string) $this->ntinda);
    });
});
