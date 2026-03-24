<?php namespace Logingrupa\RetrypaymentShopaholic;

use Event;
use Logingrupa\RetrypaymentShopaholic\Components\RetryPayment;
use Lovata\OmnipayShopaholic\Classes\Helper\PaymentGateway;
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
        $this->addPaymentGatewayRedirectListeners();
    }

    /**
     * Listen to Omnipay gateway cancel/return events and redirect
     * back to the order checkout page instead of homepage.
     */
    protected function addPaymentGatewayRedirectListeners(): void
    {
        $fnGetCheckoutURL = function ($obOrder) {
            if (empty($obOrder) || empty($obOrder->secret_key)) {
                return null;
            }

            return url('/checkout/' . $obOrder->secret_key);
        };

        Event::listen(
            PaymentGateway::EVENT_GET_PAYMENT_GATEWAY_CANCEL_URL,
            $fnGetCheckoutURL
        );

        Event::listen(
            PaymentGateway::EVENT_GET_PAYMENT_GATEWAY_RETURN_URL,
            $fnGetCheckoutURL
        );
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
