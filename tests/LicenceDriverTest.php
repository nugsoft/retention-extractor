<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Nugsoft\RetentionExtractor\Exceptions\ConfigurationException;
use Nugsoft\RetentionExtractor\Licensing\LicenceApplier;
use Nugsoft\RetentionExtractor\Licensing\LicenceState;
use Nugsoft\RetentionExtractor\Support\ProductMapping;
use Nugsoft\RetentionExtractor\Tests\Fixtures\RecordingLicenceDriver;

/*
 * A product whose licence state no single column can say — PosCream keeps it
 * on every branch and on the business — names a class of its own instead.
 */

beforeEach(function (): void {
    RecordingLicenceDriver::$access = ['1' => true];
});

it('mounts the webhook for a product with only a driver, and applies through it', function (): void {
    $this->asDriverInstall(RecordingLicenceDriver::class);
    RecordingLicenceDriver::$access = ['1' => true];

    $body = (string) json_encode(licencePayload(['grants_access' => false]));

    postLicence($body, hash_hmac('sha256', $body, 'a-signing-key'))
        ->assertSuccessful()
        ->assertJsonPath('applied', true)
        ->assertJsonPath('grants_access', false);

    expect(RecordingLicenceDriver::$access['1'])->toBeFalse()
        ->and(DB::table('retention_licences')->where('external_id', '1')->value('licence_version'))->toBe(1);
});

it('records nothing as applied for a client the driver does not have', function (): void {
    $applier = new LicenceApplier(['driver' => RecordingLicenceDriver::class]);

    $written = $applier->apply(LicenceState::fromPayload(licencePayload(['external_id' => '99'])));

    expect($written)->toBe(0)
        ->and(DB::table('retention_licences')->count())->toBe(0);
});

it('reads access back through the driver', function (): void {
    $applier = new LicenceApplier(['driver' => RecordingLicenceDriver::class]);

    expect($applier->isConfigured())->toBeTrue()
        ->and($applier->grantsAccess('1'))->toBeTrue()
        ->and($applier->grantsAccess('99'))->toBeNull();
});

it('refuses a class that is not a licence driver, rather than guessing', function (string $class): void {
    (new LicenceApplier(['driver' => $class]))->grantsAccess('1');
})->with([
    'missing' => 'App\\Retention\\NotWrittenYet',
    'not a driver' => stdClass::class,
])->throws(ConfigurationException::class);

/**
 * The driver is this product's code. NugsoftOS describes tables; it cannot
 * name a class here, and its table mapping must not be used instead.
 */
it('keeps its own driver when the rest of the mapping comes from NugsoftOS', function (): void {
    remoteMapping([
        'product' => ['code' => 'poscream', 'name' => 'POScream'],
        'licence' => ['table' => 'facilities', 'via' => 'id', 'status' => ['column' => 'status', 'granted' => 'Active', 'revoked' => 'Suspend']],
    ]);
    config()->set('retention-extractor.licence.driver', RecordingLicenceDriver::class);

    $licence = app(ProductMapping::class)->licence();

    expect($licence['driver'])->toBe(RecordingLicenceDriver::class)
        ->and($licence)->not->toHaveKey('table');
});
