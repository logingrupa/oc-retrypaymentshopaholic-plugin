# Technology Stack

**Analysis Date:** 2026-03-24

## Languages

**Primary:**
- PHP 8.4 - All plugin logic (backend, components, stores, helpers)

**Template:**
- Twig - Component default partial: `components/retrypayment/default.htm`

## Runtime

**Environment:**
- PHP 8.4 (NTS) with OPcache. After edits: `sudo systemctl reload php8.4-fpm`

**Package Manager:**
- Composer (PSR-4 autoloader, declared in `composer.json`)
- Lockfile: managed by parent project at `/home/forge/nailscosmetics.lv/composer.lock`

## Frameworks

**Core:**
- October CMS v4 (`october/system ^4.0`) - Plugin infrastructure, component registration, Twig rendering
- October Rain v4 (`october/rain ^4.0`) - Database model base, collections, helpers

**Lovata Toolbox:**
- `lovata/toolbox-plugin ^2.2` - Mandatory backbone; provides `AbstractStoreWithoutParam` base class used by `RetryableStatusListStore`. All caching goes through Toolbox's `CCache` tag system.

**Shopaholic Ecosystem:**
- `lovata/ordersshopaholic-plugin ^1.33` - Direct dependency. Provides:
  - `Lovata\OrdersShopaholic\Models\Order` — the order Eloquent model
  - `Lovata\OrdersShopaholic\Models\Status` — order status model, queried by `RetryableStatusListStore`
  - `Lovata\OrdersShopaholic\Models\PaymentMethod` — gateway-configured payment methods
  - `Lovata\OrdersShopaholic\Interfaces\PaymentGatewayInterface` — contract for `purchase()`, `isRedirect()`, `isSuccessful()`, `getRedirectURL()`, `getMessage()`, `getResponse()`
  - `Lovata\OrdersShopaholic\Components\OrderPage` — sibling CMS component; `RetryPayment` reads `$obOrderPage->obElement` from it at `init()` time

**Testing:**
- Pest v4 (via `../../../vendor/bin/pest`) - Test runner, using Pest function syntax (`uses()`, `test()`, `expect()`, `beforeEach()`)
- PHPUnit 12 compatible bootstrap (`modules/system/tests/bootstrap.php`)
- Mockery - Used for gateway mock in `RetryPaymentHelperTest`

## Key Dependencies

**Critical:**
- `lovata/ordersshopaholic-plugin ^1.33` - Entire plugin purpose depends on Order, Status, PaymentMethod, and PaymentGatewayInterface from this package
- `lovata/toolbox-plugin ^2.2` - `AbstractStoreWithoutParam` base for `RetryableStatusListStore`; provides singleton + CCache caching
- `kharanenka/php-result-store` (transitive via toolbox/ordersshopaholic) - `Kharanenka\Helper\Result` used in `RetryPayment::onRetryPayment()` to structure AJAX responses

**Optional / Ecosystem:**
- `lovata/omnipayshopaholic-plugin` (not declared, runtime-present) - Implements `PaymentGatewayInterface` via `PaymentGateway extends AbstractPaymentGateway`. The `PaymentMethod::gateway` attribute resolves to this implementation at runtime. The plugin itself stays decoupled — it only calls the interface.
- `logingrupa/oc-vipps-shopaholic-plugin` (runtime-present) - Another concrete gateway implementation; accessed transparently through the same `PaymentGatewayInterface`

## Configuration

**Plugin registration:**
- `Plugin.php` - declares `$require = ['Lovata.OrdersShopaholic']`, registers `RetryPayment` component
- `updates/version.yaml` - version `1.0.0` with no DB migrations (no new tables)
- `lang/{en,lv,lt,nb,ru}/lang.php` - Translation keys for plugin name, component labels, and error messages

**No environment variables** required by this plugin directly. Gateway credentials are configured on `PaymentMethod` records in the backend, consumed by the gateway implementation at runtime.

## QA Toolchain

| Tool | Config | Command |
|------|--------|---------|
| Laravel Pint (code style) | `pint.json` (PSR-12 preset, alpha-sorted imports, no unused imports) | `composer pint` |
| PHPStan (static analysis) | `phpstan.neon` (level 10, larastan extension, baseline: `phpstan-baseline.neon`) | `composer analyse` |
| PHP Mess Detector | `phpmd.xml` (Lovata standard thresholds: cyclomatic ≤10, NPath ≤200, method ≤100 lines) | `composer phpmd` |
| Rector (automated refactoring) | `rector.php` (php84 set, deadCode + codeQuality + typeDeclarations) | `composer rector` |
| Pest test runner | `phpunit.xml` (SQLite in-memory, array cache/session, `APP_ENV=testing`) | `composer test` |
| Full QA suite | — | `composer qa` (runs pint-test → analyse → phpmd → test) |

## Platform Requirements

**Development:**
- PHP 8.3+ (8.4 in production)
- Installed as part of the parent October CMS project; no standalone install

**Production:**
- Three deployments: nailscosmetics.lv, nailscosmetics.lt, nailscosmetics.no
- Installed via Composer from private GitHub repo (`logingrupa/oc-retrypayment-plugin`)
- October CMS plugin identifier: `Logingrupa.RetrypaymentShopaholic`
- Installer name: `retrypaymentshopaholic`

---

*Stack analysis: 2026-03-24*
