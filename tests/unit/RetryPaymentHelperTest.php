<?php

declare(strict_types=1);

use Logingrupa\RetrypaymentShopaholic\Classes\Helper\RetryPaymentHelper;
use Logingrupa\RetrypaymentShopaholic\Classes\Store\RetryableStatusListStore;
use Logingrupa\RetrypaymentShopaholic\Tests\RetryPaymentTestCase;
use Lovata\OrdersShopaholic\Interfaces\PaymentGatewayInterface;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\PaymentMethod;
use Lovata\OrdersShopaholic\Models\Status;

uses(RetryPaymentTestCase::class);

beforeEach(function () {
    // Create retryable statuses
    foreach (RetryableStatusListStore::RETRYABLE_STATUS_IDS as $iStatusId) {
        Status::firstOrCreate(
            ['id' => $iStatusId],
            ['name' => 'Test Status ' . $iStatusId, 'code' => 'test_status_' . $iStatusId]
        );
    }

    // Create non-retryable statuses
    Status::firstOrCreate(['id' => 3], ['name' => 'Order Complete', 'code' => 'complete']);
    Status::firstOrCreate(['id' => 5], ['name' => 'Payment Received', 'code' => 'payment_received']);

    RetryableStatusListStore::instance()->clear();
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
        'transaction_id' => 'TXN123',
        'secret_key' => 'test-secret-003',
    ]);

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

    // Create a mock gateway
    $obMockGateway = Mockery::mock(PaymentGatewayInterface::class);
    $obMockGateway->shouldReceive('purchase')->once()->with(Mockery::type(Order::class));
    $obMockGateway->shouldReceive('isRedirect')->andReturn(true);
    $obMockGateway->shouldReceive('getRedirectURL')->andReturn('https://gateway.example.com/pay');

    // Create payment method and inject mock gateway
    $obPaymentMethod = PaymentMethod::create([
        'name' => 'Test Gateway',
        'code' => 'test_gateway',
        'active' => true,
        'gateway_id' => 'test_gateway',
    ]);

    // Mock the gateway attribute on the payment method
    PaymentMethod::extend(function ($obModel) use ($obMockGateway) {
        $obModel->addDynamicMethod('getGatewayAttribute', function () use ($obMockGateway) {
            return $obMockGateway;
        });
    });

    $obGateway = RetryPaymentHelper::retry($obOrder, $obPaymentMethod->id);

    // Verify payment_method_id was updated
    $obOrder->refresh();
    expect($obOrder->payment_method_id)->toBe($obPaymentMethod->id);

    // Verify gateway was called
    expect($obGateway)->toBe($obMockGateway);
});
