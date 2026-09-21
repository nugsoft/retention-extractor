<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

/**
 * One command in place of six manual checks.
 *
 * Each of those told you one thing and the order mattered, so somebody
 * following them under pressure found out about the third problem after fixing
 * the first two. What matters here is that every line is a fact or a stated
 * absence — nothing inferred from something adjacent, and nothing that reads as
 * working when it is not.
 */
function identifiedByNugsoftOS(): void
{
    Http::fake(['*/api/v1/metrics' => Http::response([
        'product' => ['code' => 'clinic_plus', 'name' => 'Clinic Plus'],
        'scored' => true,
        'required' => [],
        'accepted' => [],
        'components' => [],
        'hints' => [],
    ])]);
}

describe('reporting what is wired up', function (): void {
    it('says who NugsoftOS thinks this product is', function (): void {
        identifiedByNugsoftOS();

        $this->artisan('retention:status')
            ->expectsOutputToContain('Clinic Plus')
            ->assertSuccessful();
    });

    it('describes how a client is switched off here', function (): void {
        identifiedByNugsoftOS();
        withStatusLicence();

        $this->artisan('retention:status')
            ->expectsOutputToContain('facilities.status')
            ->assertSuccessful();
    });

    it('says plainly when nothing can switch a client off', function (): void {
        // The state an install used to end in, silently.
        identifiedByNugsoftOS();
        config()->set('retention-extractor.licence.table', null);

        $this->artisan('retention:status')
            ->expectsOutputToContain('OFF')
            ->assertSuccessful();
    });

    it('warns when there is no signing secret to be believed by', function (): void {
        identifiedByNugsoftOS();
        withStatusLicence();
        config()->set('retention-extractor.licence.secret', null);

        $this->artisan('retention:status')
            ->expectsOutputToContain('every delivery will be refused')
            ->assertSuccessful();
    });

    it('fails when the key is not recognised, because nothing below it means anything', function (): void {
        Http::fake(['*/api/v1/metrics' => Http::response('who are you', 401)]);

        $this->artisan('retention:status')
            ->expectsOutputToContain('could not be reached')
            ->assertFailed();
    });

    it('names which rows it will not touch', function (): void {
        // The soft-delete trap, made visible rather than left to be discovered
        // as a disagreement no amount of re-sending would clear.
        identifiedByNugsoftOS();
        withCeilingLicence();

        $this->artisan('retention:status')
            ->expectsOutputToContain('deleted_at')
            ->assertSuccessful();
    });
});

describe('the key, before NugsoftOS is asked about it', function (): void {
    it('says the key is the right shape', function (): void {
        identifiedByNugsoftOS();

        $this->artisan('retention:status')
            ->expectsOutputToContain('64 lowercase hex characters')
            ->assertSuccessful();
    });

    it('says so when the key could never authenticate, without printing it', function (): void {
        // The shape is checked here rather than guessed from a 401, which is
        // what NugsoftOS answers for a malformed key and for a revoked one
        // alike.
        config()->set('retention-extractor.api.key', str_repeat('a', 12));

        $this->artisan('retention:status')
            ->expectsOutputToContain('WRONG SHAPE')
            ->doesntExpectOutputToContain(str_repeat('a', 12))
            ->assertFailed();
    });

    it('says when there is no key at all', function (): void {
        config()->set('retention-extractor.api.key', null);

        $this->artisan('retention:status')
            ->expectsOutputToContain('not set')
            ->assertFailed();
    });
});
