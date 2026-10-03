<?php namespace Logingrupa\RetrypaymentShopaholic\Classes\Store;

use Lovata\OrdersShopaholic\Models\Status;
use Lovata\Toolbox\Classes\Store\AbstractStoreWithoutParam;

/**
 * Class RetryableStatusListStore
 * @package Logingrupa\RetrypaymentShopaholic\Classes\Store
 *
 * Ids of the statuses an unpaid order can sit in: placed and waiting for a transfer or an
 * invoice, an online payment started, or one the gateway reported canceled or failed.
 * Status ids differ per shop, the codes do not. Canceled and paid orders are never here.
 */
class RetryableStatusListStore extends AbstractStoreWithoutParam
{
    public const RETRYABLE_STATUS_CODES = [
        'new',
        'in_progress',
        'payment-pending',
        'new-payment-canceled',
        'new-payment-error',
    ];

    /**
     * @return array
     */
    protected function getIDListFromDB(): array
    {
        return Status::whereIn('code', self::RETRYABLE_STATUS_CODES)
            ->pluck('id')
            ->map(fn ($iStatusId) => (int) $iStatusId)
            ->all();
    }
}
