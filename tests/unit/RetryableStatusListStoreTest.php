<?php

declare(strict_types=1);

use Logingrupa\RetrypaymentShopaholic\Classes\Store\RetryableStatusListStore;
use Logingrupa\RetrypaymentShopaholic\Tests\RetryPaymentTestCase;
use Lovata\OrdersShopaholic\Models\Status;

uses(RetryPaymentTestCase::class);

beforeEach(function () {
    // Ids deliberately differ from the codes' usual ids: the store must read codes only
    foreach (RetryableStatusListStore::RETRYABLE_STATUS_CODES as $iIndex => $sCode) {
        Status::forceCreate(['id' => 20 + $iIndex, 'name' => 'Status '.$sCode, 'code' => $sCode]);
    }
    Status::forceCreate(['id' => 40, 'name' => 'Canceled', 'code' => 'canceled']);
    Status::forceCreate(['id' => 41, 'name' => 'Paid', 'code' => 'new-payment-received']);

    RetryableStatusListStore::instance()->clear();
});

test('it returns the ids of every unpaid status code', function () {
    expect(RetryableStatusListStore::instance()->get())->toEqualCanonicalizing([20, 21, 22, 23, 24]);
});

test('canceled and paid orders are not retryable', function () {
    $arStatusIdList = RetryableStatusListStore::instance()->get();

    expect($arStatusIdList)->not->toContain(40);
    expect($arStatusIdList)->not->toContain(41);
});

test('a shop without one of the codes simply has fewer ids', function () {
    Status::where('code', 'payment-pending')->delete();
    RetryableStatusListStore::instance()->clear();

    expect(RetryableStatusListStore::instance()->get())->toEqualCanonicalizing([20, 21, 23, 24]);
});

test('it caches the result on second call', function () {
    expect(RetryableStatusListStore::instance()->get())->toBe(RetryableStatusListStore::instance()->get());
});
