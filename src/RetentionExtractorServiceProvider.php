<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use Nugsoft\RetentionExtractor\Console\InstallCommand;
use Nugsoft\RetentionExtractor\Console\PushCommand;
use Nugsoft\RetentionExtractor\Console\SyncLicenceCommand;
use Nugsoft\RetentionExtractor\Extraction\ClientResolver;
use Nugsoft\RetentionExtractor\Extraction\MetricCollector;
use Nugsoft\RetentionExtractor\Extraction\SnapshotBuilder;
use Nugsoft\RetentionExtractor\Http\RetentionClient;
use Nugsoft\RetentionExtractor\Licensing\LicenceApplier;
use Nugsoft\RetentionExtractor\Support\SchemaInspector;

class RetentionExtractorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/retention-extractor.php', 'retention-extractor');

        $this->app->bind(ClientResolver::class, fn (): ClientResolver => new ClientResolver(
            config('retention-extractor.clients', []),
        ));

        $this->app->bind(MetricCollector::class, fn (): MetricCollector => new MetricCollector(
            config('retention-extractor.metrics', []),
            (int) config('retention-extractor.window_days', 7),
        ));

        $this->app->bind(SnapshotBuilder::class, fn ($app): SnapshotBuilder => new SnapshotBuilder(
            $app->make(MetricCollector::class),
            (string) config('retention-extractor.product'),
        ));

        $this->app->bind(RetentionClient::class, fn ($app): RetentionClient => new RetentionClient(
            $app->make(Factory::class),
        ));

        $this->app->bind(SchemaInspector::class, fn (): SchemaInspector => new SchemaInspector);

        $this->app->bind(LicenceApplier::class, fn (): LicenceApplier => new LicenceApplier(
            config('retention-extractor.licence', []),
        ));
    }

    public function boot(): void
    {
        // Registered before the console guard: the webhook is served on a web
        // request, which is exactly the case that returns early below. Leaving
        // it inside meant the route existed only when nobody could reach it.
        $this->registerLicenceRoute();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/retention-extractor.php' => config_path('retention-extractor.php'),
        ], 'retention-extractor-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'retention-extractor-migrations');

        $this->commands([
            InstallCommand::class,
            PushCommand::class,
            SyncLicenceCommand::class,
        ]);

        $this->scheduleDailyPush();
        $this->scheduleLicencePull();
    }

    /**
     * The webhook, mounted only where a product has said it wants one.
     *
     * A product that has not filled in the `licence` block gets no route at
     * all rather than one that answers unhelpfully to anybody who finds it.
     */
    private function registerLicenceRoute(): void
    {
        if (blank(config('retention-extractor.licence.table'))) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/licence.php');
    }

    /**
     * Asks for the current licences on a schedule, so a webhook nobody
     * received stops meaning a suspended client keeps working.
     */
    private function scheduleLicencePull(): void
    {
        $frequency = config('retention-extractor.licence.pull_at');

        if (blank($frequency) || blank(config('retention-extractor.licence.table'))) {
            return;
        }

        $this->app->booted(function () use ($frequency): void {
            $event = $this->app->make(Schedule::class)
                ->command('retention:sync-licence')
                ->withoutOverlapping()
                ->runInBackground();

            // `hourly` is the sensible default and the only word most products
            // will use; anything else is read as a time of day.
            $frequency === 'hourly'
                ? $event->hourly()
                : $event->dailyAt((string) $frequency);
        });
    }

    /**
     * Registers the daily push so a product team never has to remember to.
     *
     * Skipped when the product has not been configured yet, and when `time` is
     * set to null for teams who prefer to schedule it themselves.
     */
    private function scheduleDailyPush(): void
    {
        $time = config('retention-extractor.schedule.time');

        if (blank($time) || blank(config('retention-extractor.product'))) {
            return;
        }

        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)
                ->command('retention:push')
                ->dailyAt((string) config('retention-extractor.schedule.time'))
                ->withoutOverlapping()
                ->runInBackground();
        });
    }
}
