<?php

namespace Logingrupa\RetrypaymentShopaholic\Tests;

use Backend\Classes\AuthManager;
use Illuminate\Foundation\Testing\TestCase;
use October\Rain\Database\Model as ActiveRecord;
use Schema;
use October\Rain\Database\Schema\Blueprint;

/**
 * PHPUnit 12 / Pest 4 compatible test case for October CMS plugins.
 *
 * October's PluginTestCase declares setUp() as public, which conflicts
 * with PHPUnit 12's protected setUp(). This class reimplements the same
 * bootstrap logic with correct visibility.
 */
abstract class RetryPaymentTestCase extends TestCase
{
    use \October\Tests\Concerns\InteractsWithAuthentication;
    use \October\Tests\Concerns\PerformsMigrations;
    use \October\Tests\Concerns\PerformsRegistrations;

    protected $autoMigrate = true;
    protected $autoRegister = true;

    public function createApplication()
    {
        $app = require __DIR__.'/../../../../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $app->singleton('auth', function ($app) {
            $app['auth.loaded'] = true;
            return AuthManager::instance();
        });

        return $app;
    }

    protected function setUp(): void
    {
        $this->pluginTestCaseMigratedPlugins = [];
        $this->pluginTestCaseLoadedPlugins = [];

        parent::setUp();

        // Safety: abort immediately if tests are not running on SQLite in-memory
        $sConnection = $this->app['db']->getDefaultConnection();
        $sDatabase = $this->app['config']->get("database.connections.{$sConnection}.database");
        if ($sConnection !== 'sqlite' || $sDatabase !== ':memory:') {
            $this->fail(
                "SAFETY: Tests must run on sqlite/:memory:, got {$sConnection}/{$sDatabase}. "
                . 'Check phpunit.xml has force="true" on DB_CONNECTION and DB_DATABASE env vars.'
            );
        }

        if ($this->autoRegister === true) {
            $this->loadCurrentPlugin();
        }

        if ($this->autoMigrate === true) {
            $this->migrateModules();
            $this->createTestTables();
            $this->migrateCurrentPlugin();
        }

        \Mail::pretend();
    }

    /**
     * Create minimal tables required for testing.
     *
     * Lovata plugin migrations contain MySQL-specific operations (drop column
     * with indexes) that fail on SQLite. Instead of running the full migration
     * chain, we create only the tables our tests need with all required columns.
     */
    protected function createTestTables(): void
    {
        if (!Schema::hasTable('lovata_orders_shopaholic_statuses')) {
            Schema::create('lovata_orders_shopaholic_statuses', function (Blueprint $obTable) {
                $obTable->increments('id');
                $obTable->string('name');
                $obTable->string('code');
                $obTable->integer('sort_order')->nullable();
                $obTable->boolean('is_user_show')->default(0);
                $obTable->integer('user_status_id')->nullable();
                $obTable->text('preview_text')->nullable();
                $obTable->string('color')->nullable();
                $obTable->timestamps();
                $obTable->index('code');
            });
        }

        if (!Schema::hasTable('lovata_orders_shopaholic_orders')) {
            Schema::create('lovata_orders_shopaholic_orders', function (Blueprint $obTable) {
                $obTable->increments('id');
                $obTable->integer('user_id')->nullable();
                $obTable->integer('status_id')->nullable();
                $obTable->string('order_number')->nullable();
                $obTable->string('secret_key')->nullable();
                $obTable->decimal('total_price', 15, 2)->nullable();
                $obTable->decimal('shipping_price', 15, 2)->nullable();
                $obTable->integer('shipping_type_id')->nullable();
                $obTable->integer('payment_method_id')->nullable();
                $obTable->string('transaction_id')->nullable();
                $obTable->text('payment_data')->nullable();
                $obTable->text('payment_response')->nullable();
                $obTable->integer('currency_id')->nullable();
                $obTable->decimal('shipping_tax_percent', 15, 2)->nullable();
                $obTable->integer('manager_id')->nullable();
                $obTable->integer('site_id')->nullable();
                $obTable->mediumText('property')->nullable();
                $obTable->timestamps();
            });
        }

        if (!Schema::hasTable('lovata_orders_shopaholic_payment_methods')) {
            Schema::create('lovata_orders_shopaholic_payment_methods', function (Blueprint $obTable) {
                $obTable->increments('id');
                $obTable->boolean('active')->default(0);
                $obTable->string('name');
                $obTable->string('code');
                $obTable->integer('sort_order')->nullable();
                $obTable->text('preview_text')->nullable();
                $obTable->integer('cancel_status_id')->nullable();
                $obTable->integer('fail_status_id')->nullable();
                $obTable->boolean('send_purchase_request')->default(0);
                $obTable->string('gateway_id')->nullable();
                $obTable->string('gateway_currency')->nullable();
                $obTable->text('gateway_property')->nullable();
                $obTable->integer('before_status_id')->nullable();
                $obTable->integer('after_status_id')->nullable();
                $obTable->boolean('restore_cart')->default(0);
                $obTable->timestamps();
                $obTable->index('code');
            });
        }
    }

    protected function tearDown(): void
    {
        $this->flushModelEventListeners();
        parent::tearDown();
        unset($this->app);
    }

    protected function flushModelEventListeners()
    {
        foreach (get_declared_classes() as $class) {
            if ($class == \October\Rain\Database\Pivot::class) {
                continue;
            }

            $reflectClass = new \ReflectionClass($class);
            if (
                !$reflectClass->isInstantiable() ||
                !$reflectClass->isSubclassOf(\October\Rain\Database\Model::class) ||
                $reflectClass->isSubclassOf(\October\Rain\Database\Pivot::class)
            ) {
                continue;
            }

            $class::flushEventListeners();
        }

        ActiveRecord::flushEventListeners();
    }

    protected function guessPluginCodeFromTest()
    {
        $reflect = new \ReflectionClass($this);
        $path = $reflect->getFilename();
        $pluginPath = $this->app->pluginsPath();

        if (strpos($path, $pluginPath) === 0) {
            $result = ltrim(str_replace('\\', '/', substr($path, strlen($pluginPath))), '/');
            $result = implode('.', array_slice(explode('/', $result), 0, 2));
            return $result;
        }

        return false;
    }

    protected function isAppCodeFromTest()
    {
        return false;
    }
}
