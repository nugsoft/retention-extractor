<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use Nugsoft\RetentionExtractor\Console\InstallCommand;
use Nugsoft\RetentionExtractor\Console\PushCommand;
use Nugsoft\RetentionExtractor\Extraction\ClientResolver;
use Nugsoft\RetentionExtractor\Extraction\MetricCollector;
use Nugsoft\RetentionExtractor\Extraction\SnapshotBuilder;
use Nugsoft\RetentionExtractor\Http\RetentionClient;
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
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/retention-extractor.php' => config_path('retention-extractor.php'),
        ], 'retention-extractor-config');

        $this->commands([
            InstallCommand::class,
            PushCommand::class,
        ]);

        $this->scheduleDailyPush();
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
