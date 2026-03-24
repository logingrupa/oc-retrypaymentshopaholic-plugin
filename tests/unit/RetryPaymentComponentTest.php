<?php

declare(strict_types=1);

use Logingrupa\RetrypaymentShopaholic\Components\RetryPayment;
use Logingrupa\RetrypaymentShopaholic\Tests\RetryPaymentTestCase;

uses(RetryPaymentTestCase::class);

test('it returns correct component details', function () {
    $obComponent = new RetryPayment();

    $arDetails = $obComponent->componentDetails();

    expect($arDetails)->toBeArray();
    expect($arDetails)->toHaveKey('name');
    expect($arDetails)->toHaveKey('description');
});

test('it returns empty properties array', function () {
    $obComponent = new RetryPayment();

    $arProperties = $obComponent->defineProperties();

    expect($arProperties)->toBeArray();
    expect($arProperties)->toBeEmpty();
});

test('it defaults bIsRetryable to false', function () {
    $obComponent = new RetryPayment();

    expect($obComponent->bIsRetryable)->toBeFalse();
});

test('it defaults iCurrentPaymentMethodId to zero', function () {
    $obComponent = new RetryPayment();

    expect($obComponent->iCurrentPaymentMethodId)->toBe(0);
});
