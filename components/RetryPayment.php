<?php namespace Logingrupa\RetrypaymentShopaholic\Components;

use Cms\Classes\ComponentBase;
use Flash;
use Logingrupa\RetrypaymentShopaholic\Classes\Helper\RetryPaymentHelper;
use Lovata\OrdersShopaholic\Models\Order;
use October\Rain\Exception\ApplicationException;
use Redirect;

/**
 * Class RetryPayment
 * @package Logingrupa\RetrypaymentShopaholic\Components
 * @author Logingrupa
 *
 * On the order page of an unpaid order: pay again with any visible payment method, switch
 * to a method without a gateway such as bank transfer, or cancel the order. ?cancel=1 in
 * the page address opens the cancel confirmation, the link the last payment reminder uses.
 */
class RetryPayment extends ComponentBase
{
    public const CANCEL_QUERY_FLAG = 'cancel';

    /** @var bool Whether the current order is unpaid and can be paid or canceled */
    public bool $bIsRetryable = false;

    /** @var \Illuminate\Support\Collection|null Active payment methods visible to this visitor */
    public $obPaymentMethodList = null;

    /** @var int Current payment method ID on the order */
    public int $iCurrentPaymentMethodId = 0;

    /** @var bool The page was opened from the cancel link */
    public bool $bCancelRequested = false;

    /** @var Order|null The order model from OrderPage */
    protected ?Order $obOrder = null;

    /**
     * @return array
     */
    public function componentDetails(): array
    {
        return [
            'name'        => 'logingrupa.retrypaymentshopaholic::lang.component.name',
            'description' => 'logingrupa.retrypaymentshopaholic::lang.component.description',
        ];
    }

    /**
     * @return array
     */
    public function defineProperties(): array
    {
        return [];
    }

    /**
     * Resolve the order from the OrderPage component on the same page.
     */
    public function init(): void
    {
        $obOrderPage = $this->controller->findComponentByName('OrderPage');
        if ($obOrderPage === null) {
            return;
        }

        $obOrderItem = $obOrderPage->get();
        if (empty($obOrderItem) || empty($obOrderItem->id)) {
            return;
        }

        $this->obOrder = Order::find($obOrderItem->id);
    }

    /**
     * Expose the retry state and the method list to the page.
     */
    public function onRun(): void
    {
        $this->bIsRetryable = $this->obOrder !== null && RetryPaymentHelper::isRetryable($this->obOrder);
        $this->page['bIsRetryable'] = $this->bIsRetryable;

        if (!$this->bIsRetryable) {
            return;
        }

        $this->obPaymentMethodList = RetryPaymentHelper::getPaymentMethodList();
        $this->iCurrentPaymentMethodId = (int) $this->obOrder->payment_method_id;
        $this->bCancelRequested = get(self::CANCEL_QUERY_FLAG) === '1';

        $this->page['obPaymentMethodList'] = $this->obPaymentMethodList;
        $this->page['iCurrentPaymentMethodId'] = $this->iCurrentPaymentMethodId;
        $this->page['bCancelRequested'] = $this->bCancelRequested;
    }

    /**
     * Pay with the chosen method: online methods redirect to their gateway, a method
     * without a gateway (bank transfer) is switched on and the page reloads with its details.
     * @return mixed
     */
    public function onRetryPayment()
    {
        try {
            $obOrder = $this->getOrderOrFail();
            $iPaymentMethodId = (int) post('retry_payment_method_id');

            if ($this->isOfflineMethod($iPaymentMethodId)) {
                RetryPaymentHelper::switchToOffline($obOrder, $iPaymentMethodId);
                Flash::success(trans('logingrupa.retrypaymentshopaholic::lang.component.switched_to_offline'));

                return Redirect::refresh();
            }

            $obGateway = RetryPaymentHelper::retry($obOrder, $iPaymentMethodId);
            if ($obGateway->isRedirect()) {
                return Redirect::to($obGateway->getRedirectURL());
            }

            if ($obGateway->isSuccessful()) {
                Flash::success(trans('logingrupa.retrypaymentshopaholic::lang.component.success'));

                return Redirect::refresh();
            }

            Flash::error($obGateway->getMessage());
        } catch (ApplicationException $obException) {
            Flash::error($obException->getMessage());
        }

        return null;
    }

    /**
     * @return mixed
     */
    public function onCancelOrder()
    {
        try {
            RetryPaymentHelper::cancel($this->getOrderOrFail());
            Flash::success(trans('logingrupa.retrypaymentshopaholic::lang.component.canceled'));

            return Redirect::to($this->currentPageUrl([]));
        } catch (ApplicationException $obException) {
            Flash::error($obException->getMessage());
        }

        return null;
    }

    /**
     * @return Order
     * @throws ApplicationException
     */
    protected function getOrderOrFail(): Order
    {
        if ($this->obOrder === null) {
            throw new ApplicationException(trans('logingrupa.retrypaymentshopaholic::lang.component.error_not_retryable'));
        }

        return $this->obOrder;
    }

    /**
     * @param int $iPaymentMethodId
     * @return bool
     */
    protected function isOfflineMethod(int $iPaymentMethodId): bool
    {
        $obPaymentMethod = RetryPaymentHelper::getPaymentMethodList()->firstWhere('id', $iPaymentMethodId);

        return $obPaymentMethod !== null && empty($obPaymentMethod->gateway_id);
    }
}
