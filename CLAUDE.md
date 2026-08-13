# Logingrupa.RetrypaymentShopaholic

Lets a customer retry a failed or cancelled payment: redirects Omnipay gateway cancel/return
URLs back to the order checkout page (by order secret_key) instead of the homepage, and
provides a RetryPayment component for the order page. Namespace
Logingrupa\RetrypaymentShopaholic, composer package logingrupa/oc-retrypayment-plugin.
Requires Lovata.OrdersShopaholic (uses Lovata.OmnipayShopaholic PaymentGateway events).

## Environment

- Parent app: C:\laragon\www\nc.
- This plugin dir is its OWN git repo - commit here, not in the root repo.

## Architecture map

- Plugin.php           listens PaymentGateway::EVENT_GET_PAYMENT_GATEWAY_CANCEL_URL and
                       EVENT_GET_PAYMENT_GATEWAY_RETURN_URL; resolves the CMS page that
                       has BOTH OrderPage and RetryPayment components, falls back to the
                       first page with OrderPage only
- components/          RetryPayment (retry UI + handlers, retrypayment partials)
- classes/helper/      RetryPaymentHelper
- classes/store/       RetryableStatusListStore (which order statuses allow retry)
- lang/                en, lt, lv, nb, ru
- updates/             version.yaml only (no migrations)

## Quality gates

Own phpunit.xml (tests/unit + tests/fixtures/FakePaymentGateway, October system bootstrap,
SQLite in-memory). Also phpstan.neon (+ baseline), pint.json, rector.php, phpmd.xml -
same toolchain as campaignpricingshopaholic but WITHOUT a Makefile. From root
C:\laragon\www\nc:

```bash
vendor/bin/pest --configuration plugins/logingrupa/retrypaymentshopaholic/phpunit.xml
vendor/bin/phpstan analyse --configuration=plugins/logingrupa/retrypaymentshopaholic/phpstan.neon
```

composer lint does NOT cover this plugin (phpcs.xml scope excludes plugins/logingrupa) - fix
phpcs.xml scope or lint manually; `vendor/bin/phpcs --standard=phpcs.xml <plugin path>` won't
work either since the ruleset pins files; note as known gap.

## Ship

Ship via /nc-ship (root CLAUDE.md release flow); package logingrupa/oc-retrypayment-plugin.

## Conventions

Root CLAUDE.md governs: Hungarian notation, Store -> Collection -> Item read path, Tiger-Style.

## Gotchas

- The cancel/return URL closure returns null when the order has no secret_key - the
  gateway then falls back to its own default. Checkout URLs are built from
  Cms\Classes\Page::url with the secret_key as slug param.
- Page resolution scans ALL theme pages per event fire - keep the RetryPayment component
  placed on the order page so the first branch wins.
