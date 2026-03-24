<?php namespace Logingrupa\RetrypaymentShopaholic\Classes\Store;

use Lovata\OrdersShopaholic\Models\Status;
use Lovata\Toolbox\Classes\Store\AbstractStoreWithoutParam;

/**
 * Class RetryableStatusListStore
 * @package Logingrupa\RetrypaymentShopaholic\Classes\Store
 * @author Logingrupa
 *
 * Returns cached array of status IDs for which payment retry is allowed.
 * 4 = Order Cancelled, 6 = Payment CANCELLED, 7 = Payment NOT MADE
 */
class RetryableStatusListStore extends AbstractStoreWithoutParam
{
    /** @var list<int> Status IDs eligible for payment retry */
    public const RETRYABLE_STATUS_IDS = [4, 6, 7];

    /**
     * Get the list of retryable status IDs from the database.
     * Only returns IDs that actually exist in the statuses table.
     *
     * @return list<int>
     */
    protected function getIDListFromDB(): array
    {
        return Status::whereIn('id', self::RETRYABLE_STATUS_IDS)
            ->pluck('id')
            ->all();
    }
}
