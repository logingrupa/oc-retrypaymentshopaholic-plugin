# Codebase Concerns

**Analysis Date:** 2026-03-24

---

## Security Considerations

### [CRITICAL] Order Ownership Not Re-Validated in AJAX Handler

**Risk:** Any authenticated or anonymous user who knows (or guesses) the order secret key URL can call `RetryPayment::onRetryPayment` via a direct AJAX POST. The component reads `$this->obOrder` from `OrderPage->obElement` during `init()`, which is populated at page-load time. On a subsequent AJAX call the same page context is re-run, but there is no explicit assertion that the current session user still owns the order being retried.

**Files:** `components/RetryPayment.php` lines 55–62, 99–128

**Current mitigation:** `OrderPage::getElementObject()` (in `plugins/lovata/ordersshopaholic/components/OrderPage.php` line 117–124) already performs a user ownership check when the page loads via `secret_key` URL slug — it nulls out the order if `$obElement->user_id != $this->obUser->id`. However this protection only fires during the initial `onRun`. If the AJAX request skips a full re-render of OrderPage or arrives on a different page context, `$this->obOrder` would be `null` (caught), but the defense relies entirely on OrderPage being present on the same page and executing before `RetryPayment::init()`.

**Recommendations:**
- Add an explicit ownership re-check inside `onRetryPayment()` before calling `RetryPaymentHelper::retry()`:
  ```php
  $obUser = \Lovata\Buddies\Facades\AuthHelper::getUser();
  if ($obUser && $this->obOrder->user_id && $this->obOrder->user_id != $obUser->id) {
      throw new \RuntimeException(trans('...error_not_retryable'));
  }
  ```
- Document the dependency on `OrderPage` being present on the same CMS page — currently nothing enforces this.

---

### [CRITICAL] Payment Method Not Validated Against Gateway Ownership

**Risk:** The AJAX handler accepts `payment_method_id` as free user input. `RetryPaymentHelper::retry()` fetches `PaymentMethod::isActive()->find($iPaymentMethodId)`, which is safe against inactive methods. However it does not validate that the selected payment method is appropriate for the current site (multi-site deployment: .lv/.lt/.no all share one codebase). The `PaymentMethodListStore` in OrdersShopaholic has a `ListBySiteStore` which filters by site — the component bypasses this entirely with a raw `PaymentMethod::isActive()` query.

**Files:** `components/RetryPayment.php` lines 83–86, `classes/helper/RetryPaymentHelper.php` lines 59–64

**Current mitigation:** None — only `active` scope and `gateway_id` not-null are checked.

**Recommendations:**
- Use `PaymentMethodCollection::make()` with site filtering instead of a raw Eloquent query. This matches the Toolbox Store→Collection→Item pattern and respects multi-site scoping.
- Alternatively, filter against the IDs already returned by `obPaymentMethodList` set during `onRun()` to ensure the posted ID was actually presented to the user.

---

### [HIGH] `payment_method_id = 0` Bypass Not Blocked at Component Level

**Risk:** If no radio button is selected, `post('payment_method_id')` returns `null`, cast to `int` gives `0`. `PaymentMethod::isActive()->find(0)` will return `null` because no payment method has `id = 0`, triggering the "no gateway" exception — which is a user-facing error, not a silent failure. The risk is low in practice but the validation path relies on the gateway lookup failing rather than explicit input validation.

**Files:** `components/RetryPayment.php` line 108

**Fix approach:** Add an explicit guard:
```php
if ($iPaymentMethodId <= 0) {
    throw new \RuntimeException(trans('...error_no_gateway'));
}
```

---

## Caching Gaps

### [HIGH] `RetryableStatusListStore` Never Invalidated on Status Changes

**Risk:** `RetryableStatusListStore` uses `AbstractStoreWithoutParam` backed by CCache (persistent tag-based cache). When a `Status` model record is created, saved, or deleted via the backend, the upstream `StatusModelHandler` (in `plugins/lovata/ordersshopaholic/classes/event/status/StatusModelHandler.php`) clears `StatusListStore` and `StatusItem` caches — but has no knowledge of `RetryableStatusListStore`. If an admin adds or removes a status with one of the hardcoded IDs (4, 6, 7), the store cache will serve stale data until the cache is manually flushed.

**Files:** `classes/store/RetryableStatusListStore.php`, `Plugin.php` lines 39–42 (empty `boot()`)

**Impact:** Orders remain retryable (or non-retryable) based on stale status configuration after an admin change.

**Fix approach:** In `Plugin::boot()`, subscribe to `StatusModelHandler` events and call `RetryableStatusListStore::instance()->clear()`:
```php
public function boot(): void
{
    \Event::listen('eloquent.saved: ' . \Lovata\OrdersShopaholic\Models\Status::class, function () {
        \Logingrupa\RetrypaymentShopaholic\Classes\Store\RetryableStatusListStore::instance()->clear();
    });
    \Event::listen('eloquent.deleted: ' . \Lovata\OrdersShopaholic\Models\Status::class, function () {
        \Logingrupa\RetrypaymentShopaholic\Classes\Store\RetryableStatusListStore::instance()->clear();
    });
}
```

---

### [MEDIUM] Payment Method List Fetched via Raw Eloquent, Not Through Toolbox Store/Collection

**Risk:** `onRun()` calls `PaymentMethod::isActive()->whereNotNull('gateway_id')->where('gateway_id', '!=', '')->get()` — a live Eloquent query on every page load. The existing `PaymentMethodListStore` / `PaymentMethodCollection` in OrdersShopaholic provides the same data through cached stores that are already invalidated by `PaymentMethodModelHandler`. The raw query both bypasses the cache layer and adds a DB hit on every order status page render.

**Files:** `components/RetryPayment.php` lines 83–86

**Fix approach:** Replace with:
```php
$obPaymentMethodList = \Lovata\OrdersShopaholic\Classes\Collection\PaymentMethodCollection::make()
    ->active()
    ->withGateway(); // Add a filterByGateway() method, or filter IDs post-collection
```
If `filterByGateway()` does not exist in `PaymentMethodCollection`, use `PaymentMethodCollection::make()->active()` and then filter the resulting ID list against methods that have `gateway_id` set.

---

## Tech Debt

### [HIGH] Hardcoded Status IDs Are Magic Numbers Tied to a Specific DB Seeding

**Issue:** `RetryableStatusListStore::RETRYABLE_STATUS_IDS = [4, 6, 7]` assumes the three target statuses always have these specific integer primary keys. OrdersShopaholic statuses are seeded — their IDs match only if the seeder runs in a fixed order on a fresh database. On a database migrated from a different installation sequence, IDs 4, 6, 7 may map to completely different statuses.

**Files:** `classes/store/RetryableStatusListStore.php` lines 17–32

**Impact:** Incorrect status IDs silently make all orders non-retryable (or retryable when they should not be), with no visible error.

**Fix approach:** Look up statuses by `code` field rather than `id`. OrdersShopaholic statuses have a `code` column. Add constants for the codes and query by them:
```php
public const RETRYABLE_STATUS_CODES = ['cancelled', 'payment_cancel', 'payment_not_made'];

protected function getIDListFromDB(): array
{
    return Status::whereIn('code', self::RETRYABLE_STATUS_CODES)->pluck('id')->all();
}
```
Verify actual code values against the seeder in `plugins/lovata/ordersshopaholic/updates/`.

---

### [HIGH] `isRetryable()` Called Twice Per AJAX Retry Request (Redundant DB/Cache Read)

**Issue:** `onRetryPayment()` calls `RetryPaymentHelper::retry()`, which begins with `self::isRetryable()`. This is the second call to `isRetryable()` on the same request — the first was in `onRun()`. While `RetryableStatusListStore` caches the status ID list, the intent of the second call is a server-side guard against tampered requests, which is correct security practice. However, it is not documented as intentional, creating confusion about whether it is redundant.

**Files:** `classes/helper/RetryPaymentHelper.php` lines 51–57, `components/RetryPayment.php` lines 76 and 110

**Fix approach:** Add a docblock comment to `retry()` explaining that the second `isRetryable()` call is an intentional server-side guard, not a duplicate of the component-level check.

---

### [MEDIUM] `in_array()` Uses Non-Strict Comparison for Integer Status IDs

**Issue:** `in_array($obOrder->status_id, $arRetryableStatusIdList, false)` — the third argument `false` disables strict type comparison. Both `$obOrder->status_id` (Eloquent cast or raw DB value — may be `int` or `string`) and the store IDs (returned from `pluck('id')->all()` — typically integers) could differ in type on some database drivers. Using loose comparison (`false`) risks `"0"` matching `0`, or `"4"` matching `4` unexpectedly on string-returning drivers.

**Files:** `classes/helper/RetryPaymentHelper.php` line 30

**Fix approach:** Change to `in_array((int) $obOrder->status_id, $arRetryableStatusIdList, true)` to enforce strict integer comparison.

---

### [MEDIUM] Template Strings Not Using Language File Keys

**Issue:** The Twig template at `components/retrypayment/default.htm` uses the `|_` filter with raw string literals (`'Retry payment'`, `'Your payment was not completed...'`) rather than lang key references. The lang files define `component.heading`, `component.description_text`, and `component.button` keys that are never used in the template. This means the template is not translatable through October CMS's normal translation system — the `|_` filter will look up the raw string as a key and fall back to the literal, which means only the translation stored for that literal English string will be used.

**Files:** `components/retrypayment/default.htm` lines 3–4 and 23, `lang/en/lang.php` lines 9–11

**Impact:** Translations defined in `lang/lv/lang.php`, `lang/lt/lang.php`, `lang/nb/lang.php`, `lang/ru/lang.php` for `heading`, `description_text`, and `button` keys are dead code — never loaded.

**Fix approach:** Replace raw string usage in the template:
```twig
<h3>{{ 'logingrupa.retrypaymentshopaholic::lang.component.heading'|trans }}</h3>
<p>{{ 'logingrupa.retrypaymentshopaholic::lang.component.description_text'|trans }}</p>
```
Or expose the translated strings from the component's `onRun()` as page variables.

---

### [MEDIUM] Missing `data-attach-loading` on Submit Button

**Issue:** The retry button in `components/retrypayment/default.htm` uses `data-request` (October's AJAX framework) but does not include `data-attach-loading`. Without this, the button remains enabled during the gateway redirect/response cycle. On a slow gateway connection a user can submit multiple retry requests before the first completes, potentially creating duplicate payment attempts at the gateway level.

**Files:** `components/retrypayment/default.htm` lines 19–24

**Fix approach:** Add `data-attach-loading` to the button:
```twig
<button type="button"
        data-request="RetryPayment::onRetryPayment"
        data-request-form="#retry-payment-form"
        data-attach-loading
        class="lezada-button lezada-button--medium">
```

---

### [MEDIUM] Component Copies Data to Both Property and `$this->page[]` Redundantly

**Issue:** In `onRun()`, every variable is assigned both to `$this->property` (e.g., `$this->bIsRetryable`) and to `$this->page['bIsRetryable']`. Twig templates already have access to public component properties via the component alias — the `$this->page[]` assignments are therefore redundant for boolean and integer properties. The pattern inflates `onRun()` and diverges from how other Toolbox-based components work.

**Files:** `components/RetryPayment.php` lines 70–91

**Note:** For `obPaymentMethodList`, exposing via `$this->page[]` is needed only if the variable is consumed outside the component partial. If only used in `default.htm`, the property is sufficient.

---

### [LOW] `Plugin::boot()` Is Completely Empty

**Issue:** The `boot()` method contains only a comment ("No model extensions needed") and returns void. For a plugin of this scope this is expected, but it means the store cache invalidation wiring noted above has nowhere to live currently.

**Files:** `Plugin.php` lines 39–42

**Fix approach:** Remove the comment stub and add cache invalidation listeners (see caching gaps section above).

---

### [LOW] `obPaymentMethodList` Typed as `null` Instead of Using Proper Collection Type

**Issue:** `public $obPaymentMethodList = null;` has no type declaration. The docblock says `\October\Rain\Database\Collection<PaymentMethod>|null` but the property is untyped. This bypasses PHPStan's type enforcement for this property.

**Files:** `components/RetryPayment.php` line 25

**Fix approach:** Add a typed property declaration:
```php
public ?\October\Rain\Database\Collection $obPaymentMethodList = null;
```
Or, if migrating to the Toolbox Store/Collection pattern, type it as `?\Lovata\OrdersShopaholic\Classes\Collection\PaymentMethodCollection`.

---

## Test Coverage Gaps

### [HIGH] `onRetryPayment()` AJAX Handler Has Zero Test Coverage

**What's not tested:** The AJAX handler in `RetryPayment::onRetryPayment()` — including the redirect response path, the successful payment path, the failed payment path, the `obOrder === null` path, and the exception-to-Result mapping — has no tests at all. `RetryPaymentComponentTest.php` only tests property defaults and `componentDetails()` / `defineProperties()` return shapes.

**Files:** `tests/unit/RetryPaymentComponentTest.php`, `components/RetryPayment.php` lines 99–128

**Risk:** Any regression in the AJAX handler response format, redirect behavior, or error wrapping will go undetected.

**Priority:** High — this is the critical payment path.

---

### [HIGH] `RetryPaymentHelper::retry()` Gateway Success and Failure Paths Not Tested

**What's not tested:** The test at `tests/unit/RetryPaymentHelperTest.php` line 81 tests the redirect path only (`isRedirect() → true`). The success path (`isSuccessful() → true, isRedirect() → false`) and the failure path (`isSuccessful() → false, isRedirect() → false`) are not tested. The behavior of `RetryPaymentHelper::retry()` in these cases returns the gateway object back to the caller — but what the caller (the component's `onRetryPayment()`) does with `Result::setFalse` vs `Result::setTrue` is untested end-to-end.

**Files:** `tests/unit/RetryPaymentHelperTest.php`, `classes/helper/RetryPaymentHelper.php`

**Priority:** High.

---

### [MEDIUM] No Test for `RetryableStatusListStore` Returning Empty When All Statuses Missing

**What's not tested:** The case where none of `[4, 6, 7]` exist in the database. `getIDListFromDB()` would return an empty array, and `isRetryable()` would always return `false` for all orders. This is a silent misconfiguration that would be invisible without a test.

**Files:** `tests/unit/RetryableStatusListStoreTest.php`, `classes/store/RetryableStatusListStore.php`

**Priority:** Medium.

---

### [MEDIUM] No Test for `RetryPayment::init()` When `OrderPage` Component Is Missing

**What's not tested:** The case where `findComponentByName('OrderPage')` returns `null` (component not placed on the page). Currently `init()` handles this gracefully by leaving `$this->obOrder` as `null`, and `onRun()` early-returns with `bIsRetryable = false`. This is correct behavior but it is unverified by tests.

**Files:** `tests/unit/RetryPaymentComponentTest.php`, `components/RetryPayment.php` lines 55–62

**Priority:** Medium.

---

### [LOW] Test for `retry()` Does Not Verify `payment_method_id` DB Persistence

**What's not tested:** Although `RetryPaymentHelperTest` line 113 asserts `$obOrder->payment_method_id` after `refresh()`, this test uses a dynamically extended `PaymentMethod` model with a mock gateway attribute. The mock is applied via `PaymentMethod::extend()` which persists across the singleton lifetime. This can cause test pollution if other tests run in the same process and rely on the real `gateway` attribute resolution.

**Files:** `tests/unit/RetryPaymentHelperTest.php` lines 104–108

**Priority:** Low — current test suite is small and the risk is contained, but `PaymentMethod::extend()` in a test body is a fragile pattern.

---

## Fragile Areas

### [HIGH] Implicit Dependency on `OrderPage` Component Being Present on the Same CMS Page

**Files:** `components/RetryPayment.php` lines 55–62

**Why fragile:** `RetryPayment::init()` calls `$this->controller->findComponentByName('OrderPage')`. If the page author places `RetryPayment` on a page without `OrderPage` (or uses a different alias for `OrderPage`), the component silently renders nothing (`bIsRetryable = false`) with no warning or error. This is invisible to the CMS administrator.

**Safe modification:** Before adding any component properties or changing the alias used for `OrderPage`, verify the `findComponentByName('OrderPage')` call uses the exact registered alias.

**Fix approach:** Add a `defineProperties()` entry for a configurable `OrderPage` alias, defaulting to `'OrderPage'`:
```php
'orderPageAlias' => [
    'title' => 'Order Page component alias',
    'default' => 'OrderPage',
    'type' => 'string',
]
```
Then use `$this->property('orderPageAlias')` in `init()`.

---

### [MEDIUM] Status ID Constants Are Installation-Specific and Undocumented

**Files:** `classes/store/RetryableStatusListStore.php` line 17

**Why fragile:** Any developer deploying this plugin to a fresh October CMS installation with a differently-seeded database will find that the plugin silently does nothing (no orders are retryable) or the wrong orders become retryable. The seeder dependency is not documented anywhere in the plugin.

**Safe modification:** Do not change `RETRYABLE_STATUS_IDS` values without cross-referencing the `lovata/ordersshopaholic` seeder output on all three production databases (.lv, .lt, .no).

---

### [LOW] No `updates/` Migration — Plugin Has No Database Schema

**Files:** `updates/version.yaml`

**Why fragile:** The plugin has no migrations. This is fine architecturally (it uses no new tables), but `version.yaml` contains only `"Initialize plugin."` with no migration references. If a future version requires a migration (e.g., configurable retry statuses stored in DB), the migration chain starts from v1.0.0 with no prior schema to build on — which is correct but easy to get wrong when the first migration is added.

---

## Missing Critical Features

### [MEDIUM] No Rate Limiting on Retry Attempts

**Problem:** A customer (or attacker who can access the order page URL) can call `RetryPayment::onRetryPayment` repeatedly in a tight loop, triggering repeated `purchase()` calls to the payment gateway for the same order. Most gateways handle idempotency internally, but some charge per authorization attempt or flag accounts for suspicious activity.

**Blocks:** Proper production hardening.

**Fix approach:** Add a per-order retry attempt counter (session-based or DB-based) and enforce a maximum attempt limit (e.g., 3 retries per order per session) before returning an error.

---

### [LOW] No Backend Configuration for Retryable Statuses

**Problem:** The set of status IDs that qualify for retry is hardcoded in `RETRYABLE_STATUS_IDS`. There is no backend settings form allowing administrators to configure which statuses permit retry. This makes the plugin inflexible for stores with customized order workflows.

**Blocks:** Adapting the plugin to non-standard status configurations without a code change.

---

*Concerns audit: 2026-03-24*
