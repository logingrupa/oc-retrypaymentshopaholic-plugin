<?php namespace Logingrupa\RetrypaymentShopaholic\Classes\Helper;

use Logingrupa\RetrypaymentShopaholic\Classes\Store\RetryableStatusListStore;
use Lovata\OrdersShopaholic\Interfaces\PaymentGatewayInterface;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\PaymentMethod;

/**
 * Class RetryPaymentHelper
 * @package Logingrupa\RetrypaymentShopaholic\Classes\Helper
 * @author Logingrupa
 *
 * Static utility for determining order retry eligibility and initiating gateway purchase.
 */
class RetryPaymentHelper
{
    /**
     * Check whether the given order is eligible for payment retry.
     * An order is retryable when:
     *   1. Its status_id is in the retryable status list (cancelled/payment failed)
     *   2. It has no successful transaction recorded (transaction_id is empty)
     *
     * @param Order $obOrder
     * @return bool
     */
    public static function isRetryable(Order $obOrder): bool
    {
        $arRetryableStatusIdList = RetryableStatusListStore::instance()->get();

        if (!in_array($obOrder->status_id, $arRetryableStatusIdList, false)) {
            return false;
        }

        if (!empty($obOrder->transaction_id)) {
            return false;
        }

        return true;
    }

    /**
     * Retry payment on the given order with a (possibly different) payment method.
     * Updates the order's payment_method_id, then initiates a gateway purchase.
     *
     * @param Order $obOrder
     * @param int   $iPaymentMethodId
     * @return PaymentGatewayInterface The gateway object (caller checks isRedirect/isSuccessful)
     *
     * @throws \RuntimeException When order is not retryable or payment method is invalid
     */
    public static function retry(Order $obOrder, int $iPaymentMethodId): PaymentGatewayInterface
    {
        if (!self::isRetryable($obOrder)) {
            throw new \RuntimeException(
                trans('logingrupa.retrypaymentshopaholic::lang.component.error_not_retryable')
            );
        }

        $obPaymentMethod = PaymentMethod::isActive()->find($iPaymentMethodId);
        if (empty($obPaymentMethod)) {
            throw new \RuntimeException(
                trans('logingrupa.retrypaymentshopaholic::lang.component.error_no_gateway')
            );
        }

        /** @var PaymentGatewayInterface|null $obGateway */
        $obGateway = $obPaymentMethod->gateway;
        if (empty($obGateway)) {
            throw new \RuntimeException(
                trans('logingrupa.retrypaymentshopaholic::lang.component.error_no_gateway')
            );
        }

        // Update order to use the selected payment method
        $obOrder->payment_method_id = $iPaymentMethodId;
        $obOrder->save();

        // Initiate gateway purchase
        $obGateway->purchase($obOrder);

        return $obGateway;
    }
}
