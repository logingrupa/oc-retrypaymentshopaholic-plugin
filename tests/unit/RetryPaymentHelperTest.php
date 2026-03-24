<?php

declare(strict_types=1);

use Logingrupa\RetrypaymentShopaholic\Classes\Helper\RetryPaymentHelper;
use Logingrupa\RetrypaymentShopaholic\Classes\Store\RetryableStatusListStore;
use Logingrupa\RetrypaymentShopaholic\Tests\RetryPaymentTestCase;
use Logingrupa\RetrypaymentShopaholic\Tests\Fixtures\FakePaymentGateway;
use Lovata\OrdersShopaholic\Interfaces\PaymentGatewayInterface;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\PaymentMethod;
use Lovata\OrdersShopaholic\Models\Status;

uses(RetryPaymentTestCase::class);

beforeEach(function () {
    // Create retryable statuses — use forceCreate to set specific IDs
    foreach (RetryableStatusListStore::RETRYABLE_STATUS_IDS as $iStatusId) {
        Status::forceCreate(
            ['id' => $iStatusId, 'name' => 'Test Status ' . $iStatusId, 'code' => 'test_status_' . $iStatusId]
        );
    }

    // Create non-retryable statuses
    Status::forceCreate(['id' => 3, 'name' => 'Order Complete', 'code' => 'complete']);
    Status::forceCreate(['id' => 5, 'name' => 'Payment Received', 'code' => 'payment_received']);

    RetryableStatusListStore::instance()->clear();
    FakePaymentGateway::$bPurchaseCalled = false;
});

test('it returns true for order with retryable status and no transaction', function () {
    $obOrder = Order::create([
        'status_id' => 6,
        'transaction_id' => null,
        'secret_key' => 'test-secret-001',
    ]);

    expect(RetryPaymentHelper::isRetryable($obOrder))->toBeTrue();
});

test('it returns false for order with non-retryable status', function () {
    $obOrder = Order::create([
        'status_id' => 5,
        'transaction_id' => null,
        'secret_key' => 'test-secret-002',
    ]);

    expect(RetryPaymentHelper::isRetryable($obOrder))->toBeFalse();
});

test('it returns false for order with transaction_id set', function () {
    $obOrder = Order::create([
        'status_id' => 6,
        'secret_key' => 'test-secret-003',
    ]);

    // transaction_id is not fillable, so set it directly and save
    $obOrder->transaction_id = 'TXN123';
    $obOrder->save();

    expect(RetryPaymentHelper::isRetryable($obOrder))->toBeFalse();
});

test('it returns false for completed order', function () {
    $obOrder = Order::create([
        'status_id' => 3,
        'transaction_id' => null,
        'secret_key' => 'test-secret-004',
    ]);

    expect(RetryPaymentHelper::isRetryable($obOrder))->toBeFalse();
});

test('it throws when retrying non-retryable order', function () {
    $obOrder = Order::create([
        'status_id' => 5,
        'transaction_id' => null,
        'secret_key' => 'test-secret-005',
    ]);

    RetryPaymentHelper::retry($obOrder, 1);
})->throws(RuntimeException::class);

test('it updates payment method and calls gateway on retry', function () {
    $obOrder = Order::create([
        'status_id' => 6,
        'transaction_id' => null,
        'payment_method_id' => 1,
        'secret_key' => 'test-secret-006',
    ]);

    // Register fake gateway on every PaymentMethod instance via boot event
    PaymentMethod::extend(function ($obModel) {
        $obModel->bindEvent('model.afterFetch', function () use ($obModel) {
            $obModel->addGatewayClass('test_fake_gateway', FakePaymentGateway::class);
        });
    });

    // Create payment method with a test gateway_id
    $obPaymentMethod = PaymentMethod::create([
        'name' => 'Test Gateway',
        'code' => 'test_gateway',
        'active' => true,
        'gateway_id' => 'test_fake_gateway',
    ]);

    $obGateway = RetryPaymentHelper::retry($obOrder, $obPaymentMethod->id);

    // Verify payment_method_id was updated
    $obOrder->refresh();
    expect($obOrder->payment_method_id)->toBe($obPaymentMethod->id);

    // Verify gateway is the right type
    expect($obGateway)->toBeInstanceOf(PaymentGatewayInterface::class);
    expect(FakePaymentGateway::$bPurchaseCalled)->toBeTrue();
});
