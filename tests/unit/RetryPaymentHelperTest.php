<?php

declare(strict_types=1);

use Logingrupa\RetrypaymentShopaholic\Classes\Helper\RetryPaymentHelper;
use Logingrupa\RetrypaymentShopaholic\Classes\Store\RetryableStatusListStore;
use Logingrupa\RetrypaymentShopaholic\Tests\RetryPaymentTestCase;
use Logingrupa\RetrypaymentShopaholic\Tests\Fixtures\FakePaymentGateway;
use Lovata\OrdersShopaholic\Classes\Collection\PaymentMethodCollection;
use Lovata\OrdersShopaholic\Classes\Store\PaymentMethodListStore;
use Lovata\OrdersShopaholic\Interfaces\PaymentGatewayInterface;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\PaymentMethod;
use Lovata\OrdersShopaholic\Models\Status;
use October\Rain\Exception\ApplicationException;

uses(RetryPaymentTestCase::class);

const STATUS_NEW = 1;
const STATUS_COMPLETE = 3;
const STATUS_CANCELED = 4;
const STATUS_PAID = 5;
const STATUS_PAYMENT_FAILED = 7;
const STATUS_PAYMENT_PENDING = 9;

beforeEach(function () {
    Status::forceCreate(['id' => STATUS_NEW, 'name' => 'New', 'code' => 'new']);
    Status::forceCreate(['id' => STATUS_COMPLETE, 'name' => 'Complete', 'code' => 'complete']);
    Status::forceCreate(['id' => STATUS_CANCELED, 'name' => 'Canceled', 'code' => 'canceled']);
    Status::forceCreate(['id' => STATUS_PAID, 'name' => 'Paid', 'code' => 'new-payment-received']);
    Status::forceCreate(['id' => STATUS_PAYMENT_FAILED, 'name' => 'Failed', 'code' => 'new-payment-error']);
    Status::forceCreate(['id' => STATUS_PAYMENT_PENDING, 'name' => 'Pending', 'code' => 'payment-pending']);

    PaymentMethod::extend(function ($obModel) {
        $obModel->bindEvent('model.afterFetch', function () use ($obModel) {
            $obModel->addGatewayClass('test_fake_gateway', FakePaymentGateway::class);
        });
    });

    // Ids restart in every test while the store cache lives for the whole run
    RetryableStatusListStore::instance()->clear();
    PaymentMethodListStore::instance()->active->clear();
    FakePaymentGateway::$bPurchaseCalled = false;
});

function makeOrder(int $iStatusId, string $sSecretKey, ?int $iPaymentMethodId = null): Order
{
    return Order::create([
        'status_id' => $iStatusId,
        'payment_method_id' => $iPaymentMethodId,
        'secret_key' => $sSecretKey,
    ]);
}

function makePaymentMethod(string $sCode, string $sGatewayId, bool $bActive = true): PaymentMethod
{
    return PaymentMethod::create(['name' => $sCode, 'code' => $sCode, 'active' => $bActive, 'gateway_id' => $sGatewayId]);
}

test('unpaid orders are retryable, paid and canceled ones are not', function () {
    expect(RetryPaymentHelper::isRetryable(makeOrder(STATUS_NEW, 'k1')))->toBeTrue();
    expect(RetryPaymentHelper::isRetryable(makeOrder(STATUS_PAYMENT_PENDING, 'k2')))->toBeTrue();
    expect(RetryPaymentHelper::isRetryable(makeOrder(STATUS_PAYMENT_FAILED, 'k3')))->toBeTrue();
    expect(RetryPaymentHelper::isRetryable(makeOrder(STATUS_PAID, 'k4')))->toBeFalse();
    expect(RetryPaymentHelper::isRetryable(makeOrder(STATUS_COMPLETE, 'k5')))->toBeFalse();
    expect(RetryPaymentHelper::isRetryable(makeOrder(STATUS_CANCELED, 'k6')))->toBeFalse();
});

test('an order with a transaction id is not retryable', function () {
    $obOrder = makeOrder(STATUS_PAYMENT_FAILED, 'k7');
    $obOrder->transaction_id = 'TXN123';
    $obOrder->save();

    expect(RetryPaymentHelper::isRetryable($obOrder))->toBeFalse();
});

test('the method list holds active online and offline methods only', function () {
    $obOnline = makePaymentMethod('card', 'test_fake_gateway');
    $obBank = makePaymentMethod('bank', '');
    makePaymentMethod('switched_off', 'test_fake_gateway', false);

    expect(RetryPaymentHelper::getPaymentMethodList()->pluck('id')->all())
        ->toEqualCanonicalizing([$obOnline->id, $obBank->id]);
});

test('the method list follows a container binding of the payment method collection', function () {
    $obVisible = makePaymentMethod('card', 'test_fake_gateway');
    $obHidden = makePaymentMethod('test_mode_card', 'test_fake_gateway');

    app()->bind(PaymentMethodCollection::class, fn () => new class ($obHidden->id) extends PaymentMethodCollection {
        public function __construct(private int $iHiddenId)
        {
        }

        public function active()
        {
            parent::active();

            return $this->diff([$this->iHiddenId]);
        }
    });

    expect(RetryPaymentHelper::getPaymentMethodList()->pluck('id')->all())->toBe([$obVisible->id]);
});

test('retry switches the method and starts the gateway purchase', function () {
    $obPaymentMethod = makePaymentMethod('card', 'test_fake_gateway');
    $obOrder = makeOrder(STATUS_PAYMENT_FAILED, 'k8');

    $obGateway = RetryPaymentHelper::retry($obOrder, $obPaymentMethod->id);

    expect($obOrder->refresh()->payment_method_id)->toBe($obPaymentMethod->id);
    expect($obGateway)->toBeInstanceOf(PaymentGatewayInterface::class);
    expect(FakePaymentGateway::$bPurchaseCalled)->toBeTrue();
});

test('retry refuses a paid order', function () {
    RetryPaymentHelper::retry(makeOrder(STATUS_PAID, 'k9'), makePaymentMethod('card', 'test_fake_gateway')->id);
})->throws(ApplicationException::class);

test('retry refuses an inactive method', function () {
    RetryPaymentHelper::retry(makeOrder(STATUS_PAYMENT_FAILED, 'k10'), makePaymentMethod('off', 'test_fake_gateway', false)->id);
})->throws(ApplicationException::class);

test('switching to bank transfer moves the order back to new and fires the event', function () {
    $obBank = makePaymentMethod('bank', '');
    $obOrder = makeOrder(STATUS_PAYMENT_FAILED, 'k11', makePaymentMethod('card', 'test_fake_gateway')->id);
    $arFired = [];
    Event::listen(RetryPaymentHelper::EVENT_SWITCHED_TO_OFFLINE, function ($obFiredOrder) use (&$arFired) {
        $arFired[] = $obFiredOrder->id;
    });

    RetryPaymentHelper::switchToOffline($obOrder, $obBank->id);

    $obOrder->refresh();
    expect($obOrder->payment_method_id)->toBe($obBank->id);
    expect($obOrder->status_id)->toBe(STATUS_NEW);
    expect($arFired)->toBe([$obOrder->id]);
    expect(FakePaymentGateway::$bPurchaseCalled)->toBeFalse();
});

test('switchToOffline refuses a method with a gateway', function () {
    RetryPaymentHelper::switchToOffline(makeOrder(STATUS_PAYMENT_FAILED, 'k12'), makePaymentMethod('card', 'test_fake_gateway')->id);
})->throws(InvalidArgumentException::class);

test('cancel moves an unpaid order to canceled and fires the event', function () {
    $obOrder = makeOrder(STATUS_PAYMENT_PENDING, 'k13');
    $arFired = [];
    Event::listen(RetryPaymentHelper::EVENT_CANCELED_BY_CUSTOMER, function ($obFiredOrder) use (&$arFired) {
        $arFired[] = $obFiredOrder->id;
    });

    RetryPaymentHelper::cancel($obOrder);

    expect($obOrder->refresh()->status_id)->toBe(STATUS_CANCELED);
    expect($arFired)->toBe([$obOrder->id]);
});

test('cancel refuses a paid order', function () {
    RetryPaymentHelper::cancel(makeOrder(STATUS_PAID, 'k14'));
})->throws(ApplicationException::class);
