<?php namespace Logingrupa\RetrypaymentShopaholic\Components;

use Cms\Classes\ComponentBase;
use Kharanenka\Helper\Result;
use Logingrupa\RetrypaymentShopaholic\Classes\Helper\RetryPaymentHelper;
use Lovata\OrdersShopaholic\Components\OrderPage;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\PaymentMethod;
use Redirect;

/**
 * Class RetryPayment
 * @package Logingrupa\RetrypaymentShopaholic\Components
 * @author Logingrupa
 *
 * CMS component that renders a retry-payment form on the order status page
 * when the order is in a retryable status (cancelled / payment failed).
 */
class RetryPayment extends ComponentBase
{
    /** @var bool Whether the current order can be retried */
    public bool $bIsRetryable = false;

    /** @var \October\Rain\Database\Collection<PaymentMethod>|null Active gateway-backed payment methods */
    public $obPaymentMethodList = null;

    /** @var int Current payment method ID on the order */
    public int $iCurrentPaymentMethodId = 0;

    /** @var Order|null The order model from OrderPage */
    protected ?Order $obOrder = null;

    /**
     * @return array<string, string>
     */
    public function componentDetails(): array
    {
        return [
            'name'        => 'logingrupa.retrypaymentshopaholic::lang.component.name',
            'description' => 'logingrupa.retrypaymentshopaholic::lang.component.description',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function defineProperties(): array
    {
        return [];
    }

    /**
     * Init: find OrderPage component on the same page and read its order.
     */
    public function init(): void
    {
        /** @var OrderPage|null $obOrderPage */
        $obOrderPage = $this->controller->findComponentByName('OrderPage');
        if ($obOrderPage !== null && !empty($obOrderPage->obElement)) {
            $this->obOrder = $obOrderPage->obElement;
        }
    }

    /**
     * On run: determine retryability and load payment methods.
     */
    public function onRun(): void
    {
        if ($this->obOrder === null) {
            $this->bIsRetryable = false;
            $this->page['bIsRetryable'] = false;

            return;
        }

        $this->bIsRetryable = RetryPaymentHelper::isRetryable($this->obOrder);
        $this->page['bIsRetryable'] = $this->bIsRetryable;

        if (!$this->bIsRetryable) {
            return;
        }

        $this->obPaymentMethodList = PaymentMethod::isActive()
            ->whereNotNull('gateway_id')
            ->where('gateway_id', '!=', '')
            ->get();

        $this->iCurrentPaymentMethodId = (int) $this->obOrder->payment_method_id;

        $this->page['obPaymentMethodList'] = $this->obPaymentMethodList;
        $this->page['iCurrentPaymentMethodId'] = $this->iCurrentPaymentMethodId;
    }

    /**
     * AJAX handler: retry payment with selected payment method.
     *
     * @return \Illuminate\Http\RedirectResponse|array
     */
    public function onRetryPayment()
    {
        try {
            if ($this->obOrder === null) {
                throw new \RuntimeException(
                    trans('logingrupa.retrypaymentshopaholic::lang.component.error_not_retryable')
                );
            }

            $iPaymentMethodId = (int) post('payment_method_id');

            $obGateway = RetryPaymentHelper::retry($this->obOrder, $iPaymentMethodId);

            if ($obGateway->isRedirect()) {
                $sRedirectURL = $obGateway->getRedirectURL();

                return Redirect::to($sRedirectURL);
            }

            if ($obGateway->isSuccessful()) {
                Result::setTrue($obGateway->getResponse());
            } else {
                Result::setFalse($obGateway->getResponse());
            }

            return Result::setMessage($obGateway->getMessage())->get();
        } catch (\Exception $obException) {
            return Result::setFalse()->setMessage($obException->getMessage())->get();
        }
    }
}
