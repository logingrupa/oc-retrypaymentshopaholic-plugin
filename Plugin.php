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

            // Find CMS page with both OrderPage and RetryPayment components
            $sPageName = $this->findOrderPageWithRetry();

            if (!empty($sPageName)) {
                return \Cms\Classes\Page::url($sPageName, ['slug' => $obOrder->secret_key]);
            }

            return null;
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
     * Find the CMS page that has both OrderPage and RetryPayment components.
     * Falls back to first page with OrderPage if RetryPayment not placed yet.
     *
     * @return string|null CMS page file name
     */
    protected function findOrderPageWithRetry(): ?string
    {
        $obTheme = \Cms\Classes\Theme::getActiveTheme();
        $arPages = \Cms\Classes\Page::listInTheme($obTheme);

        $sOrderPageFallback = null;

        foreach ($arPages as $obPage) {
            $arComponents = $obPage->settings['components'] ?? [];

            if (!isset($arComponents['OrderPage'])) {
                continue;
            }

            // Prefer page with both OrderPage and RetryPayment
            if (isset($arComponents['RetryPayment'])) {
                return $obPage->getBaseFileName();
            }

            // Remember first OrderPage as fallback
            if ($sOrderPageFallback === null) {
                $sOrderPageFallback = $obPage->getBaseFileName();
            }
        }

        return $sOrderPageFallback;
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
