<?php namespace Logingrupa\RetrypaymentShopaholic\Classes\Helper;

use Event;
use Illuminate\Support\Collection;
use Logingrupa\RetrypaymentShopaholic\Classes\Store\RetryableStatusListStore;
use Lovata\OrdersShopaholic\Classes\Collection\PaymentMethodCollection;
use Lovata\OrdersShopaholic\Interfaces\PaymentGatewayInterface;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\PaymentMethod;
use Lovata\OrdersShopaholic\Models\Status;
use October\Rain\Exception\ApplicationException;

/**
 * Class RetryPaymentHelper
 * @package Logingrupa\RetrypaymentShopaholic\Classes\Helper
 *
 * What a customer can do with an unpaid order: pay online again with any visible method,
 * switch to a method without a gateway (bank transfer), or cancel the order.
 */
class RetryPaymentHelper
{
    /** Fired with [Order] after the customer moved an order to a method without a gateway */
    public const EVENT_SWITCHED_TO_OFFLINE = 'logingrupa.retrypayment.order.switched_to_offline';

    /** Fired with [Order] after the customer canceled an unpaid order */
    public const EVENT_CANCELED_BY_CUSTOMER = 'logingrupa.retrypayment.order.canceled_by_customer';

    public const STATUS_CODE_NEW = 'new';

    public const STATUS_CODE_CANCELED = 'canceled';

    /**
     * @param Order $obOrder
     * @return bool
     */
    public static function isRetryable(Order $obOrder): bool
    {
        $arRetryableStatusIdList = RetryableStatusListStore::instance()->get();

        if (!in_array((int) $obOrder->status_id, $arRetryableStatusIdList, true)) {
            return false;
        }

        return empty($obOrder->transaction_id);
    }

    /**
     * Active methods this visitor may use. Lovata collections resolve through the
     * container, so a plugin that hides test-mode methods hides them here as well.
     * @return Collection<PaymentMethod>
     */
    public static function getPaymentMethodList(): Collection
    {
        $arActiveIdList = PaymentMethodCollection::make()->active()->getIDList();

        return PaymentMethod::whereIn('id', $arActiveIdList)->orderBy('sort_order')->get();
    }

    /**
     * @param Order $obOrder
     * @param int $iPaymentMethodId
     * @return PaymentGatewayInterface the gateway after its purchase request
     * @throws ApplicationException
     */
    public static function retry(Order $obOrder, int $iPaymentMethodId): PaymentGatewayInterface
    {
        $obPaymentMethod = self::findAllowedPaymentMethod($obOrder, $iPaymentMethodId);

        $obGateway = $obPaymentMethod->gateway;
        if (empty($obGateway)) {
            throw new ApplicationException(trans('logingrupa.retrypaymentshopaholic::lang.component.error_no_gateway'));
        }

        $obOrder->payment_method_id = $obPaymentMethod->id;
        $obOrder->save();

        $obGateway->purchase($obOrder);

        return $obGateway;
    }

    /**
     * @param Order $obOrder
     * @param int $iPaymentMethodId a method without a gateway
     * @return void
     * @throws ApplicationException
     */
    public static function switchToOffline(Order $obOrder, int $iPaymentMethodId)
    {
        $obPaymentMethod = self::findAllowedPaymentMethod($obOrder, $iPaymentMethodId);
        if (!empty($obPaymentMethod->gateway_id)) {
            throw new \InvalidArgumentException("Payment method {$iPaymentMethodId} has a gateway, use retry()");
        }

        $obOrder->payment_method_id = $obPaymentMethod->id;
        $obOrder->status_id = self::getStatusId(self::STATUS_CODE_NEW);
        $obOrder->save();

        Event::fire(self::EVENT_SWITCHED_TO_OFFLINE, [$obOrder]);
    }

    /**
     * @param Order $obOrder
     * @return void
     * @throws ApplicationException
     */
    public static function cancel(Order $obOrder)
    {
        if (!self::isRetryable($obOrder)) {
            throw new ApplicationException(trans('logingrupa.retrypaymentshopaholic::lang.component.error_not_cancelable'));
        }

        $obOrder->status_id = self::getStatusId(self::STATUS_CODE_CANCELED);
        $obOrder->save();

        Event::fire(self::EVENT_CANCELED_BY_CUSTOMER, [$obOrder]);
    }

    /**
     * @param Order $obOrder
     * @param int $iPaymentMethodId
     * @return PaymentMethod
     * @throws ApplicationException
     */
    protected static function findAllowedPaymentMethod(Order $obOrder, int $iPaymentMethodId): PaymentMethod
    {
        if (!self::isRetryable($obOrder)) {
            throw new ApplicationException(trans('logingrupa.retrypaymentshopaholic::lang.component.error_not_retryable'));
        }

        $obPaymentMethod = self::getPaymentMethodList()->firstWhere('id', $iPaymentMethodId);
        if (empty($obPaymentMethod)) {
            throw new ApplicationException(trans('logingrupa.retrypaymentshopaholic::lang.component.error_payment_method'));
        }

        return $obPaymentMethod;
    }

    /**
     * @param string $sCode
     * @return int
     */
    protected static function getStatusId(string $sCode): int
    {
        $obStatus = Status::getByCode($sCode)->first();
        if (empty($obStatus)) {
            throw new \RuntimeException("Order status \"{$sCode}\" does not exist on this shop");
        }

        return (int) $obStatus->id;
    }
}
