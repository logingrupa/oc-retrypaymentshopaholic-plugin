<?php

declare(strict_types=1);

use Logingrupa\RetrypaymentShopaholic\Classes\Store\RetryableStatusListStore;
use Logingrupa\RetrypaymentShopaholic\Tests\RetryPaymentTestCase;
use Lovata\OrdersShopaholic\Models\Status;

uses(RetryPaymentTestCase::class);

beforeEach(function () {
    // Ensure the retryable statuses exist in the database — use forceCreate to set specific IDs
    foreach (RetryableStatusListStore::RETRYABLE_STATUS_IDS as $iStatusId) {
        Status::forceCreate(
            ['id' => $iStatusId, 'name' => 'Test Status ' . $iStatusId, 'code' => 'test_status_' . $iStatusId]
        );
    }

    // Clear the store cache before each test
    RetryableStatusListStore::instance()->clear();
});

test('it returns retryable status IDs', function () {
    $arStatusIdList = RetryableStatusListStore::instance()->get();

    expect($arStatusIdList)->toBeArray();

    foreach (RetryableStatusListStore::RETRYABLE_STATUS_IDS as $iStatusId) {
        expect($arStatusIdList)->toContain($iStatusId);
    }
});

test('it caches the result on second call', function () {
    $arFirstCall = RetryableStatusListStore::instance()->get();
    $arSecondCall = RetryableStatusListStore::instance()->get();

    expect($arFirstCall)->toBe($arSecondCall);
});

test('it can be cleared', function () {
    // Populate cache
    RetryableStatusListStore::instance()->get();

    // Clear
    RetryableStatusListStore::instance()->clear();

    // Should still return correct results after clear (re-fetches from DB)
    $arStatusIdList = RetryableStatusListStore::instance()->get();

    expect($arStatusIdList)->toBeArray();
    expect($arStatusIdList)->not->toBeEmpty();
});

test('it only returns IDs that exist in database', function () {
    // Delete one status
    Status::where('id', 7)->delete();

    RetryableStatusListStore::instance()->clear();
    $arStatusIdList = RetryableStatusListStore::instance()->get();

    expect($arStatusIdList)->toContain(4);
    expect($arStatusIdList)->toContain(6);
    expect($arStatusIdList)->not->toContain(7);
});
