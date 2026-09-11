<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/**
 * What an install carrying no mapping of its own still has to have at boot.
 *
 * The route and the schedule were both decided by reading the local `licence`
 * block, which on such an install is empty — so a product could opt into
 * licence sync and then quietly have none of it.
 *
 * The route is the worse half. No route means every delivery is a 404, and a
 * 404 is not retried: the client carries on working, and the only trace is a
 * failure on a panel nobody is watching for. That is the exact failure this
 * whole exercise exists to prevent, reintroduced one layer down.
 */
describe('a product told to ask where things live', function (): void {
    beforeEach(function (): void {
        $this->asRemoteInstall();
    });

    it('mounts the webhook even though nothing local names a table', function (): void {
        expect(Route::has('retention.licence.receive'))->toBeTrue();
    });

    it('refuses a delivery in a way that will be retried', function (): void {
        // Unreachable, so nothing is mapped and nothing is guessed.
        Http::fake(['*' => Http::response('down', 503)]);

        // 503 says "ask me again"; 404 says "never ask me again".
        $body = (string) json_encode(licencePayload());

        postLicence($body, hash_hmac('sha256', $body, 'a-signing-key'))->assertStatus(503);
    });

    it('still schedules the pull that recovers a missed delivery', function (): void {
        $scheduled = collect(app(Schedule::class)->events())
            ->contains(fn ($event): bool => str_contains((string) $event->command, 'retention:sync-licence'));

        expect($scheduled)->toBeTrue();
    });
});
