<?php namespace Logingrupa\RetrypaymentShopaholic\Tests\Fixtures;

use Lovata\OrdersShopaholic\Interfaces\PaymentGatewayInterface;

class FakePaymentGateway implements PaymentGatewayInterface
{
    public static bool $bPurchaseCalled = false;

    public function purchase($obOrder)
    {
        self::$bPurchaseCalled = true;
    }

    public function isRedirect(): bool
    {
        return true;
    }

    public function isSuccessful(): bool
    {
        return false;
    }

    public function getRedirectURL(): string
    {
        return 'https://gateway.example.com/pay';
    }

    public function getMessage(): string
    {
        return '';
    }

    public function getResponse(): array
    {
        return [];
    }
}
