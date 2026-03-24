# Architecture

**Analysis Date:** 2026-03-24

## Pattern Overview

**Overall:** Minimal CMS component plugin with a helper layer — no models, no collections, no event handlers. The plugin consumes the Lovata.Toolbox Store pattern for one narrowly scoped concern (retryable status ID resolution) and delegates all Shopaholic order lifecycle work to `Lovata.OrdersShopaholic` without extending its models.

**Key Characteristics:**
- Read-only plugin: no DB migrations beyond `updates/version.yaml`, no new tables
- No `Event::subscribe()` registrations — `Plugin::boot()` is intentionally empty
- Component co-locates on the same CMS page as `OrderPage` and reads `$obElement` from it via `findComponentByName()`
- Business logic is fully extracted into a static helper (`RetryPaymentHelper`) — the component itself contains no eligibility or gateway logic
- Store pattern used only for the status-ID whitelist, giving it cache-backed DB validation

## Layers

**Store (status whitelist cache):**
- Purpose: Return the array of order status IDs for which retry is permitted, backed by DB and Toolbox cache
- Location: `classes/store/RetryableStatusListStore.php`
- Extends: `Lovata\Toolbox\Classes\Store\AbstractStoreWithoutParam`
- Returns: `list<int>` — IDs that exist in `lovata_orders_shopaholic_statuses` for the hardcoded whitelist `[4, 6, 7]`
- Cache key: Toolbox manages automatically via singleton pattern; cleared by calling `RetryableStatusListStore::instance()->clear()`
- Note: No `ModelHandler` clears this store automatically — callers must clear manually if status rows are deleted

**Helper (business logic):**
- Purpose: Own the two pure business operations: eligibility check and gateway invocation
- Location: `classes/helper/RetryPaymentHelper.php`
- All methods are `public static` — stateless utility, no constructor injection
- `isRetryable(Order $obOrder): bool` — checks `status_id` against store, then guards on non-empty `transaction_id`
- `retry(Order $obOrder, int $iPaymentMethodId): PaymentGatewayInterface` — validates, updates `payment_method_id`, calls `$obGateway->purchase($obOrder)`
- Throws `\RuntimeException` (translated message) on invalid state — never returns null on failure

**Component (CMS bridge):**
- Purpose: Expose retry eligibility and payment method list to Twig; handle the AJAX retry submission
- Location: `components/RetryPayment.php`
- Extends: `Cms\Classes\ComponentBase`
- Registered alias: `RetryPayment`
- Reads the `Order` model from a sibling `OrderPage` component via `$this->controller->findComponentByName('OrderPage')->obElement`
- Public page variables set in `onRun()`: `$bIsRetryable`, `$obPaymentMethodList`, `$iCurrentPaymentMethodId`
- AJAX handler: `onRetryPayment()` — calls `RetryPaymentHelper::retry()`, then either redirects (gateway redirect flow) or returns a `Result` array (Larajax response)
- Error handling: `try/catch(\Exception $obException)` with `Result::setFalse()->setMessage()`

**Template (Twig partial):**
- Purpose: Render the retry payment form conditionally
- Location: `components/retrypayment/default.htm`
- Guard: `{% if bIsRetryable %}` — renders nothing when order is not retryable
- AJAX trigger: `data-request="RetryPayment::onRetryPayment"` + `data-request-form="#retry-payment-form"` (Larajax/October AJAX framework — no jQuery)
- Payment methods: iterated from `obPaymentMethodList`, rendered as radio inputs with `iCurrentPaymentMethodId` pre-selected

**Plugin manifest:**
- Location: `Plugin.php`
- Namespace: `Logingrupa\RetrypaymentShopaholic`
- Hard dependency declared: `Lovata.OrdersShopaholic`
- Registers: `RetryPayment::class => 'RetryPayment'` in `registerComponents()`
- `boot()`: empty, intentional — no model extensions, no event subscriptions

## Data Flow

**Page load (onRun):**

1. CMS initialises all components on the page; `RetryPayment::init()` runs before `onRun()`
2. `init()` calls `$this->controller->findComponentByName('OrderPage')` — reads `$obElement` (the `Order` Eloquent model) from the sibling component
3. `onRun()` calls `RetryPaymentHelper::isRetryable($obOrder)`:
   - `RetryableStatusListStore::instance()->get()` returns cached `list<int>` of retryable status IDs
   - Guards: `status_id` must be in list AND `transaction_id` must be empty
4. If retryable: queries `PaymentMethod::isActive()->whereNotNull('gateway_id')` — direct Eloquent query (no Store/Collection wrapper, acceptable since payment methods are not a high-frequency cached resource in this plugin)
5. Page variables `bIsRetryable`, `obPaymentMethodList`, `iCurrentPaymentMethodId` written to `$this->page[...]`
6. Twig renders `default.htm`; form only visible when `bIsRetryable` is true

**AJAX retry submission (onRetryPayment):**

1. User clicks button; October AJAX framework POSTs `payment_method_id` to `RetryPayment::onRetryPayment`
2. Component reads `post('payment_method_id')` cast to `(int)`
3. Calls `RetryPaymentHelper::retry($obOrder, $iPaymentMethodId)`:
   - Re-validates retryability (guard against stale form)
   - Loads `PaymentMethod` by ID via `isActive()->find()`
   - Resolves `$obPaymentMethod->gateway` (the `PaymentGatewayInterface` instance from OmnipayShopaholic)
   - Updates `$obOrder->payment_method_id` and saves
   - Calls `$obGateway->purchase($obOrder)` — gateway handles redirect URL generation
4. Component inspects `$obGateway->isRedirect()`:
   - `true` → `Redirect::to($obGateway->getRedirectURL())` — browser redirect to payment gateway
   - `false` + successful → `Result::setTrue()` response
   - `false` + failed → `Result::setFalse()` response
5. Exceptions caught and returned as `Result::setFalse()->setMessage()` JSON

**State Management:**
- No client-side state — form is standard HTML radio group, no JS state manager
- No plugin-owned cache invalidation — `RetryableStatusListStore` is cleared only in tests; production relies on Toolbox TTL expiry

## Key Abstractions

**RetryableStatusListStore:**
- Purpose: Cache-backed whitelist of order status IDs eligible for retry
- Location: `classes/store/RetryableStatusListStore.php`
- Pattern: `AbstractStoreWithoutParam` singleton; `RETRYABLE_STATUS_IDS = [4, 6, 7]` constant defines the source-of-truth whitelist; `getIDListFromDB()` validates against actual DB rows
- To clear: `RetryableStatusListStore::instance()->clear()`

**RetryPaymentHelper:**
- Purpose: Decouple retry business logic from the component — single responsibility, independently testable
- Location: `classes/helper/RetryPaymentHelper.php`
- Pattern: Pure static utility class; all dependencies passed as parameters; throws on invalid state rather than returning null
- Extension point: New eligibility conditions go into `isRetryable()`; new post-retry side-effects go after `$obGateway->purchase()`

**PaymentGatewayInterface:**
- Not owned by this plugin — from `Lovata\OrdersShopaholic\Interfaces\PaymentGatewayInterface`
- Accessed via `$obPaymentMethod->gateway` accessor (OmnipayShopaholic provides concrete implementations)
- Methods used: `purchase(Order)`, `isRedirect()`, `isSuccessful()`, `getRedirectURL()`, `getResponse()`, `getMessage()`

## Entry Points

**Component `init()` (page initialisation):**
- Location: `components/RetryPayment.php::init()`
- Triggers: October CMS component initialisation on every page request
- Responsibilities: Resolve sibling `OrderPage` component; read the `Order` model reference

**Component `onRun()` (page data):**
- Location: `components/RetryPayment.php::onRun()`
- Triggers: October CMS page run phase after `init()`
- Responsibilities: Determine retryability; load payment methods; populate `$this->page` variables for Twig

**AJAX handler `onRetryPayment()` (form submission):**
- Location: `components/RetryPayment.php::onRetryPayment()`
- Triggers: `data-request="RetryPayment::onRetryPayment"` from the Twig form button
- Responsibilities: Validate, delegate to `RetryPaymentHelper::retry()`, return redirect or Result JSON

## How It Extends the Shopaholic Order Lifecycle

The plugin does **not** extend OrdersShopaholic via events or model extension. Instead:

- It **reads** the order lifecycle state (`status_id`, `transaction_id`) to determine if a retry is valid
- It **writes** `payment_method_id` back to the order before calling the gateway (the only mutation)
- It **invokes** the existing OmnipayShopaholic gateway interface (`purchase()`) — the same interface MakeOrder uses for initial payment
- It relies on the **gateway's own redirect/callback flow** to update order status post-payment; no custom status transitions are performed by this plugin

To add a status transition after a successful non-redirect payment, add it inside the `$obGateway->isSuccessful()` branch in `onRetryPayment()`.

## Error Handling

**Strategy:** Throw `\RuntimeException` from helper on invalid state; catch at component boundary with `Result::setFalse()->setMessage()`

**Patterns:**
- `RetryPaymentHelper::isRetryable()` — returns `bool`, never throws; safe to call without try/catch
- `RetryPaymentHelper::retry()` — throws `\RuntimeException` with translated message on two conditions: order not retryable, or payment method missing/has no gateway
- `onRetryPayment()` — wraps the entire execution in `try/catch(\Exception $obException)` per Logingrupa convention; always returns a `Result` array on non-redirect paths
- Null guard in `onRun()`: if `$obOrder === null`, sets `bIsRetryable = false` and returns early — template renders nothing, no exception thrown

---

*Architecture analysis: 2026-03-24*
