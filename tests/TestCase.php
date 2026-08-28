<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nugsoft\RetentionExtractor\RetentionExtractorServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use InstallHarness;

    protected function tearDown(): void
    {
        $this->tearDownInstallHarness();

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [RetentionExtractorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('retention-extractor.api.url', 'https://retention.test');
        $app['config']->set('retention-extractor.api.key', str_repeat('a', 64));
        $app['config']->set('retention-extractor.product', 'poscream');
    }

    /**
     * A schema shaped like a real multi-tenant POS product, so the mapping is
     * exercised against something recognisable rather than a toy table.
     */
    protected function defineDatabaseMigrations(): void
    {
        Schema::create('businesses', function (Blueprint $table): void {
            $table->id();
            $table->string('business_name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Where a business's work actually happens. `sales` carries the branch
        // as well as the business, so both readings can be tested; `visits`
        // carries only the branch, which is the shape School Monitor is in.
        Schema::create('business_branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id');
            $table->string('name');
            // Where the client's details actually live, as in every product
            // here: the parent table has a name and little else.
            $table->string('address')->nullable();
            $table->string('main_contact', 30)->nullable();
            $table->string('email')->nullable();
        });

        Schema::create('visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_branch_id');
            $table->timestamps();
        });

        Schema::create('sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id');
            $table->foreignId('business_branch_id')->nullable();
            $table->decimal('total', 12, 2);
            $table->timestamps();
        });

        Schema::create('sale_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_id');
            $table->integer('quantity');
            $table->timestamps();
        });

        Schema::create('sessions_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id');
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id');
            $table->date('starts_at');
            $table->date('ends_at');
            $table->string('status');
            $table->timestamps();
        });

        // Laravel's own sessions table: no tenant column of its own, and
        // last_activity a unix integer rather than a datetime. This is the
        // table a product reaches for when asked to count logins, so the
        // mapping has to cope with both awkwardnesses.
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable();
            $table->integer('last_activity');
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id');
            $table->string('name');
        });

        // One table holding every kind of event — the shape School Monitor
        // keeps its audit trail in, where counting logins means naming which
        // action is one.
        Schema::create('audit_trail', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id');
            $table->foreignId('user_id');
            $table->string('action');
            $table->timestamps();
        });
    }
}
