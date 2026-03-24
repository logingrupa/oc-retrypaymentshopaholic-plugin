<?php namespace Logingrupa\RetrypaymentShopaholic;

use Logingrupa\RetrypaymentShopaholic\Components\RetryPayment;
use System\Classes\PluginBase;

/**
 * Class Plugin
 * @package Logingrupa\RetrypaymentShopaholic
 * @author Logingrupa
 */
class Plugin extends PluginBase
{
    /**
     * Required plugins
     * @var list<string>
     */
    public $require = [
        'Lovata.OrdersShopaholic',
    ];

    /**
     * Returns information about this plugin
     * @return array<string, string>
     */
    #[\Override]
    public function pluginDetails(): array
    {
        return [
            'name'        => 'logingrupa.retrypaymentshopaholic::lang.plugin.name',
            'description' => 'logingrupa.retrypaymentshopaholic::lang.plugin.description',
            'author'      => 'Logingrupa',
            'icon'        => 'icon-refresh',
        ];
    }

    /**
     * Boot method, called right before the request route
     */
    public function boot(): void
    {
        // No model extensions needed
    }

    /**
     * Register components
     * @return array<class-string, string>
     */
    #[\Override]
    public function registerComponents(): array
    {
        return [
            RetryPayment::class => 'RetryPayment',
        ];
    }
}
