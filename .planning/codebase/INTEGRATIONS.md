# External Integrations

**Analysis Date:** 2026-03-24

## Retry Payment Flow Overview

The plugin does not introduce any new external integrations. It orchestrates existing integrations already present in the Shopaholic ecosystem — specifically the payment gateway layer from `OmnipayShopaholic` — by reusing the same `PaymentGatewayInterface` contract and `PaymentMethod` model that `OrderPage` uses for the original checkout.

```
Browser form (data-request="RetryPayment::onRetryPayment")
  └── RetryPayment::onRetryPayment()          [components/RetryPayment.php]
        └── RetryPaymentHelper::retry()        [classes/helper/RetryPaymentHelper.php]
              ├── RetryableStatusListStore      [classes/store/RetryableStatusListStore.php]
              │     └── Status (DB query, cached)
              ├── PaymentMethod::isActive()->find($iPaymentMethodId)
              │     └── lovata/ordersshopaholic: PaymentMethod Eloquent model
              ├── $obPaymentMethod->gateway     (resolves concrete PaymentGatewayInterface)
              │     └── lovata/omnipayshopaholic: PaymentGateway (or VippsShopaholic, etc.)
              └── $obGateway->purchase($obOrder)
                    └── External payment provider (PayPal, Vipps, etc. via Omnipay)
```

## Payment Gateway Integration

**Interface:**
- `Lovata\OrdersShopaholic\Interfaces\PaymentGatewayInterface`
- Location: `plugins/lovata/ordersshopaholic/interfaces/PaymentGatewayInterface.php`
- Methods consumed by this plugin: `purchase(Order)`, `isRedirect()`, `getRedirectURL()`, `isSuccessful()`, `getMessage()`, `getResponse()`

**Concrete implementations (runtime, not declared as dependencies):**
- `Lovata\OmnipayShopaholic\Classes\Helper\PaymentGateway` — Omnipay bridge (PayPal Express, etc.)
  - Location: `plugins/lovata/omnipayshopaholic/classes/helper/PaymentGateway.php`
  - Uses `Omnipay\Omnipay::create($obPaymentMethod->gateway_id)` to instantiate the driver
  - Success/cancel callbacks routed to `shopaholic/omnipay/paypal/success/{secret_key}` and `shopaholic/omnipay/paypal/cancel/{secret_key}`
- `logingrupa/oc-vipps-shopaholic-plugin` — Vipps MobilePay (Norway only); same interface, different redirect URLs

**How the plugin invokes the gateway:**
```php
// RetryPaymentHelper::retry()
$obPaymentMethod = PaymentMethod::isActive()->find($iPaymentMethodId);
$obGateway = $obPaymentMethod->gateway;   // resolved via OmnipayShopaholic's model extension
$obOrder->payment_method_id = $iPaymentMethodId;
$obOrder->save();
$obGateway->purchase($obOrder);           // delegates all external comms to the gateway class
return $obGateway;
```

**What `purchase()` does internally (OmnipayShopaholic):**
1. Creates Omnipay gateway instance from `PaymentMethod::gateway_id`
2. Sets `returnUrl` / `cancelUrl` using order `secret_key`
3. Posts purchase request to external payment provider
4. Saves `payment_token`, `transaction_id`, `payment_data`, `payment_response` to `Order`
5. Sets order status to "waiting payment" or "success"

**Gateway response handling in RetryPayment component:**
```php
if ($obGateway->isRedirect()) {
    return Redirect::to($obGateway->getRedirectURL());   // sends customer to payment provider
}
if ($obGateway->isSuccessful()) {
    Result::setTrue($obGateway->getResponse());
} else {
    Result::setFalse($obGateway->getResponse());
}
return Result::setMessage($obGateway->getMessage())->get();
```

## Data Storage

**Databases:**
- MySQL/MariaDB (production) / SQLite in-memory (tests)
- No new tables introduced by this plugin — no migrations beyond `version.yaml 1.0.0`
- Tables read/written (all owned by OrdersShopaholic):
  - `lovata_orders_shopaholic_orders` — reads `status_id`, `transaction_id`, `payment_method_id`; writes `payment_method_id` on retry
  - `lovata_orders_shopaholic_statuses` — queried by `RetryableStatusListStore` to validate IDs 4, 6, 7 exist
  - `lovata_orders_shopaholic_payment_methods` — queried for active, gateway-configured methods

**Caching:**
- `RetryableStatusListStore` extends `Lovata\Toolbox\Classes\Store\AbstractStoreWithoutParam`
- Cache populated on first call to `RetryableStatusListStore::instance()->get()`
- Cache driver: configured by parent project (`CACHE_DRIVER` env var; tests use `array`)
- Cache invalidated: manually via `RetryableStatusListStore::instance()->clear()`. No automatic ModelHandler is registered — the retryable status list is considered near-static configuration (IDs 4, 6, 7).

## Authentication & Identity

**Auth Provider:**
- No direct auth checks in this plugin
- Order access is gated by `OrderPage` (sibling component on the same CMS page): it only exposes `$obElement` when the order's `secret_key` matches the URL slug and `user_id` matches the authenticated user. `RetryPayment` reads `$obOrderPage->obElement` — if it is null, the component silently disables itself (`$bIsRetryable = false`)

## AJAX / Frontend Communication

**Pattern:** October CMS Larajax (`data-request` attributes — no jQuery required)

**AJAX endpoint:**
- Handler: `RetryPayment::onRetryPayment`
- Trigger: `data-request="RetryPayment::onRetryPayment"` on button in `components/retrypayment/default.htm`
- Form data submitted: `payment_method_id` (radio input, integer)
- Success: returns `Result::get()` array (Kharanenka Result helper) or a `Redirect` response to the payment provider URL

**Template snippet:**
```twig
<button type="button"
        data-request="RetryPayment::onRetryPayment"
        data-request-form="#retry-payment-form"
        class="lezada-button lezada-button--medium">
    {{ 'Retry payment'|_ }}
</button>
```

No JavaScript files are bundled with this plugin. All interactivity is handled by October CMS's built-in `data-request` / Larajax system.

## Webhooks & Callbacks

**Incoming (to this plugin):** None. This plugin does not register any routes.

**Incoming (handled by OmnipayShopaholic after redirect):**
- `GET shopaholic/omnipay/paypal/success/{secret_key}` → `PaymentGateway::processSuccessRequest()`
- `GET shopaholic/omnipay/paypal/cancel/{secret_key}` → `PaymentGateway::processCancelRequest()`
- These routes are registered by `lovata/omnipayshopaholic` (`plugins/lovata/omnipayshopaholic/routes.php`), not by this plugin. The retry flow re-uses the same callback URLs as the original checkout.

**Outgoing:** Delegated entirely to the concrete gateway implementation (`OmnipayShopaholic`, `VippsShopaholic`). This plugin makes no direct HTTP calls.

## Events

**Fired by this plugin:** None.

**Consumed by this plugin:** None (no `Event::subscribe` or `Event::listen` calls in `Plugin::boot()`).

**Ecosystem events triggered downstream** (by the gateway implementation, not this plugin):
- `shopaholic.payment_method.omnipay.gateway.process_return_url`
- `shopaholic.payment_method.omnipay.gateway.process_cancel_url`
- `shopaholic.payment_method.omnipay.gateway.purchase_data`

## Multi-Site Considerations

**Currency:** The retry uses the order's existing currency and total as stored. The gateway's `PaymentGateway::preparePurchaseData()` reads `$obOrder->total_price_data->price_with_tax_value` and `$obPaymentMethod->gateway_currency` — already resolved at order creation time. No additional currency conversion logic is needed in this plugin.

**Site-specific gateways:** The `.no` deployment uses Vipps; `.lv`/`.lt` use PayPal/Omnipay. Both implement `PaymentGatewayInterface` identically. The retry flow works transparently across all three sites.

---

*Integration audit: 2026-03-24*
