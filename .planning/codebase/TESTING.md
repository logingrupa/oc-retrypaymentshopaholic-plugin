# Testing Patterns

**Analysis Date:** 2026-03-24

## Test Framework

**Runner:** Pest PHP (driven via PHPUnit XML configuration)
- Config: `phpunit.xml` (plugin root)
- Pest is invoked as: `../../../vendor/bin/pest --configuration phpunit.xml`
- Composer script: `composer test`

**Base test case:** `tests/RetryPaymentTestCase.php`
- Extends `Illuminate\Foundation\Testing\TestCase` (NOT `October\Tests\PluginTestCase`)
- Reason for custom base: PHPUnit 12 changed `setUp()` visibility to `protected`; October's `PluginTestCase` declares it `public` — incompatible. The custom base reimplements the same bootstrap logic with correct visibility.
- Traits used: `InteractsWithAuthentication`, `PerformsMigrations`, `PerformsRegistrations`
- Auto-migrates SQLite in-memory DB on each test class (`$autoMigrate = true`)
- Auto-registers the plugin under test (`$autoRegister = true`)
- Flushes all model event listeners in `tearDown()` to prevent test bleed

**PHPUnit configuration (phpunit.xml):**
```xml
DB_CONNECTION=sqlite
DB_DATABASE=:memory:
CACHE_DRIVER=array
SESSION_DRIVER=array
APP_ENV=testing
```

**Assertion library:** Pest's built-in `expect()` API (not PHPUnit `$this->assert*`)

**Run commands:**
```bash
composer test                                    # Run all tests via Pest
../../../vendor/bin/pest --configuration phpunit.xml  # Direct invocation
../../../vendor/bin/pest --configuration phpunit.xml --coverage  # With coverage (requires Xdebug/pcov)
```

---

## Test File Organization

**Location:** Co-located under `tests/` within the plugin directory.

**Structure:**
```
tests/
├── RetryPaymentTestCase.php      # Abstract base class shared by all test files
└── unit/
    ├── RetryableStatusListStoreTest.php   # Store caching + DB isolation
    ├── RetryPaymentHelperTest.php         # Business logic + mocking
    └── RetryPaymentComponentTest.php      # Component property defaults
```

**Naming:** `{ClassName}Test.php` — PascalCase matching the class under test.

**Pest style:** All tests use `uses(RetryPaymentTestCase::class)` at file top, then `test('description', fn() => ...)` syntax. No class-based test files.

**Note:** The `tests/` directory is excluded from PHPStan, Rector, and PHPMD analysis (correct — tooling should not lint test code the same way as production code).

---

## Test Structure

**Suite organization (Pest `uses()` pattern):**
```php
// File top
declare(strict_types=1);

uses(RetryPaymentTestCase::class);

// Setup shared state
beforeEach(function () {
    // Seed DB fixtures or clear Store cache
    RetryableStatusListStore::instance()->clear();
});

// Individual test
test('descriptive sentence covering one behavior', function () {
    // Arrange
    $obOrder = Order::create([...]);

    // Act / Assert
    expect(RetryPaymentHelper::isRetryable($obOrder))->toBeTrue();
});
```

**Data setup:** Uses `Model::firstOrCreate()` or `Model::create()` against SQLite in-memory DB. No database factories or seeders exist yet.

**Store cache management:** `RetryableStatusListStore::instance()->clear()` called in `beforeEach` to prevent cache from leaking across tests.

---

## Current Test Coverage

### `RetryableStatusListStoreTest.php` — 4 tests

| Test | Behavior Verified |
|------|-------------------|
| `it returns retryable status IDs` | Store returns all 3 configured IDs when DB rows exist |
| `it caches the result on second call` | Two `get()` calls return identical reference |
| `it can be cleared` | After `clear()`, `get()` re-fetches from DB and still works |
| `it only returns IDs that exist in database` | Missing DB row is excluded from returned list |

**Coverage gaps for the Store:**
- No test for behavior when ALL status rows are absent (empty DB → empty array)
- No test for the singleton pattern: `instance()` returns the same object across calls
- No test asserting the cache tag used (CCache tag invalidation not verified)

### `RetryPaymentHelperTest.php` — 6 tests

| Test | Behavior Verified |
|------|-------------------|
| `it returns true for retryable status and no transaction` | Happy path `isRetryable()` |
| `it returns false for non-retryable status` | Status filter |
| `it returns false for order with transaction_id set` | Transaction guard |
| `it returns false for completed order` | Another non-retryable status |
| `it throws when retrying non-retryable order` | `retry()` guards via `isRetryable()` |
| `it updates payment method and calls gateway on retry` | Full `retry()` happy path with Mockery |

**Coverage gaps for the Helper:**
- No test for `retry()` when `PaymentMethod::isActive()->find()` returns null (invalid method ID → RuntimeException)
- No test for `retry()` when `$obPaymentMethod->gateway` is null (gateway not configured → RuntimeException)
- No test for `retry()` when gateway's `isSuccessful()` returns true (non-redirect success path)
- No test for `retry()` when gateway is neither redirect nor successful (failure path)
- No test for `isRetryable()` with `transaction_id` set to empty string `''` (boundary: `empty('')` is true, same as null — confirm behavior is identical)
- No test for all three retryable status IDs individually (only status ID 6 tested in happy path; IDs 4 and 7 not covered)

### `RetryPaymentComponentTest.php` — 4 tests

| Test | Behavior Verified |
|------|-------------------|
| `it returns correct component details` | `componentDetails()` has `name` and `description` keys |
| `it returns empty properties array` | `defineProperties()` returns empty array |
| `it defaults bIsRetryable to false` | Default property state |
| `it defaults iCurrentPaymentMethodId to zero` | Default property state |

**Coverage gaps for the Component (significant):**
- `onRun()` is completely untested — the core page-rendering logic has zero test coverage
- `init()` sibling-component lookup is untested
- `onRetryPayment()` AJAX handler is untested
- No test for `onRun()` when `$obOrder === null` (early return path)
- No test for `onRun()` when order is not retryable (sets `bIsRetryable = false`)
- No test for `onRun()` when order IS retryable (loads payment methods, sets page vars)
- No test for `onRetryPayment()` redirect path
- No test for `onRetryPayment()` success path
- No test for `onRetryPayment()` failure path
- No test for `onRetryPayment()` exception handling

---

## Current vs Target Coverage

| Layer | Current | Target (Level 10) |
|-------|---------|-------------------|
| Store (`RetryableStatusListStore`) | ~70% | 100% |
| Helper (`RetryPaymentHelper`) | ~60% | 100% |
| Component core properties | ~40% | 100% |
| Component `onRun()` | 0% | 100% |
| Component `onRetryPayment()` | 0% | 100% |
| Component `init()` | 0% | 100% |
| Template (Twig) | 0% | N/A (manual or E2E) |

---

## Mocking

**Framework:** Mockery (available via `vendor/bin/pest`; `Mockery::mock()` available in Pest context)

**Singleton injection pattern (used in CampaignPricingShopaholic, needed here too):**
```php
// Override a singleton via reflection (for classes extending October's Singleton)
$obMockGateway = Mockery::mock(PaymentGatewayInterface::class);

// Inject via PaymentMethod::extend() dynamic method override
PaymentMethod::extend(function ($obModel) use ($obMockGateway) {
    $obModel->addDynamicMethod('getGatewayAttribute', function () use ($obMockGateway) {
        return $obMockGateway;
    });
});
```

**What to mock:**
- `PaymentGatewayInterface` — external gateway; never make real HTTP calls in tests
- `PaymentMethod::gateway` attribute — injected via `addDynamicMethod` as shown above
- CurrencyHelper singleton — via reflection property injection (`$obProperty->setValue(null, $obMock)`) when currency conversion is involved (pattern from CampaignPricingShopaholic)

**What NOT to mock:**
- `Order` model — use real SQLite in-memory DB; `Order::create([...])` is fast and tests real query behavior
- `Status` model — same; seed with `Status::firstOrCreate()`
- `RetryableStatusListStore` — test the real store; use `->clear()` between tests
- `PaymentMethod` model — use real DB creation; only mock the `gateway` attribute

---

## Fixtures and Factories

**Current state:** No factories or seeders. Test data is created inline in `beforeEach` and individual tests using `Model::firstOrCreate()` / `Model::create()`.

**Recommended pattern for level-10 testing:**

```php
// In tests/Factories/OrderFactory.php (to be created)
class OrderFactory
{
    public static function retryableOrder(array $arOverrides = []): Order
    {
        return Order::create(array_merge([
            'status_id'     => 6,
            'transaction_id' => null,
            'secret_key'     => 'test-secret-' . uniqid(),
        ], $arOverrides));
    }

    public static function completedOrder(array $arOverrides = []): Order
    {
        return Order::create(array_merge([
            'status_id'     => 3,
            'transaction_id' => 'TXN-COMPLETE-' . uniqid(),
            'secret_key'     => 'test-secret-' . uniqid(),
        ], $arOverrides));
    }
}
```

**Location for factories:** `tests/Factories/` (to be created)

---

## Coverage Configuration

**Requirements:** Not yet enforced (no `<coverage>` section in `phpunit.xml`).

**To enable coverage reporting, add to `phpunit.xml`:**
```xml
<coverage>
    <include>
        <directory suffix=".php">./classes</directory>
        <directory suffix=".php">./components</directory>
        <file>./Plugin.php</file>
    </include>
    <report>
        <html outputDirectory="./coverage" lowUpperBound="50" highLowerBound="90"/>
        <text outputFile="php://stdout" showUncoveredFiles="true"/>
    </report>
</coverage>
```

**Run with coverage:**
```bash
../../../vendor/bin/pest --configuration phpunit.xml --coverage
```
Requires Xdebug (`ext-xdebug`) or PCOV (`ext-pcov`) in the PHP CLI environment.

---

## Test Types

### Unit Tests (current)
- **Scope:** Single class in isolation; DB interactions via in-memory SQLite
- **Location:** `tests/unit/`
- **Pattern:** Pest `test()` + `expect()`, Mockery for external dependencies

### Integration Tests (missing — add for level 10)
- **Scope:** Component + Helper + Store working together end-to-end without HTTP
- **What to add:** Test that `RetryPayment::onRun()` correctly chains `RetryPaymentHelper::isRetryable()` with the Store, and populates `$this->page` variables correctly
- **Location:** `tests/integration/` (to be created)

### Component Tests (partially covered)
- **Scope:** Component method behavior with mocked CMS context
- **Current gap:** `init()`, `onRun()`, `onRetryPayment()` completely untested
- **Challenge:** `$this->controller->findComponentByName()` requires CMS context — mock the controller or use a partial mock of the component

### E2E / Browser Tests (not in scope for this plugin)
- The retry flow ends with a gateway redirect — E2E would require a payment gateway sandbox
- Not recommended; cover the redirect-URL-return logic in unit tests via gateway mock instead

---

## Recommendations for Level-10 Coverage

### Priority 1 — Close critical gaps in existing test files

**`RetryPaymentHelperTest.php` — add these tests:**

```php
test('it throws when payment method id does not exist', function () {
    $obOrder = Order::create([
        'status_id'     => 6,
        'transaction_id' => null,
        'secret_key'     => 'test-secret-pm-null',
    ]);

    RetryPaymentHelper::retry($obOrder, 99999);
})->throws(RuntimeException::class);

test('it throws when payment method has no gateway', function () {
    $obOrder = Order::create([
        'status_id'     => 6,
        'transaction_id' => null,
        'secret_key'     => 'test-secret-no-gw',
    ]);

    // Create active payment method WITH no gateway
    $obPaymentMethod = PaymentMethod::create([
        'name'       => 'No Gateway Method',
        'code'       => 'no_gateway',
        'active'     => true,
        'gateway_id' => null,
    ]);

    // Ensure gateway attribute returns null
    PaymentMethod::extend(function ($obModel) {
        $obModel->addDynamicMethod('getGatewayAttribute', fn() => null);
    });

    RetryPaymentHelper::retry($obOrder, $obPaymentMethod->id);
})->throws(RuntimeException::class);

test('all three retryable status IDs are accepted', function () {
    foreach (RetryableStatusListStore::RETRYABLE_STATUS_IDS as $iStatusId) {
        $obOrder = Order::create([
            'status_id'     => $iStatusId,
            'transaction_id' => null,
            'secret_key'     => 'test-secret-' . $iStatusId,
        ]);

        expect(RetryPaymentHelper::isRetryable($obOrder))->toBeTrue();
    }
});
```

**`RetryableStatusListStoreTest.php` — add these tests:**

```php
test('it returns empty array when no retryable statuses exist in DB', function () {
    Status::whereIn('id', RetryableStatusListStore::RETRYABLE_STATUS_IDS)->delete();
    RetryableStatusListStore::instance()->clear();

    $arStatusIdList = RetryableStatusListStore::instance()->get();

    expect($arStatusIdList)->toBeArray();
    expect($arStatusIdList)->toBeEmpty();
});

test('instance returns the same singleton object', function () {
    $obInstance1 = RetryableStatusListStore::instance();
    $obInstance2 = RetryableStatusListStore::instance();

    expect($obInstance1)->toBe($obInstance2);
});
```

### Priority 2 — Add component behavior tests

**`RetryPaymentComponentTest.php` — add `onRun()` and `onRetryPayment()` tests:**

The challenge is that `onRun()` calls `$this->page['key']` (requires a CMS Page object) and `init()` calls `$this->controller->findComponentByName()`. Use a partial mock approach:

```php
test('onRun sets bIsRetryable false when obOrder is null', function () {
    // Arrange: component with no order (init not called, obOrder stays null)
    $obComponent = Mockery::mock(RetryPayment::class)->makePartial();
    $obComponent->shouldReceive('page')->andReturn(new stdClass());

    // Directly invoke without CMS context via reflection
    $obReflection = new ReflectionClass($obComponent);
    $obProperty = $obReflection->getProperty('obOrder');
    $obProperty->setAccessible(true);
    $obProperty->setValue($obComponent, null);

    // Replace $this->page with an array-accessible mock
    // ... (see pattern below)

    expect($obComponent->bIsRetryable)->toBeFalse();
});
```

**Simpler approach — test via property state after direct method call:**

```php
test('onRun bIsRetryable true for retryable order', function () {
    // Create retryable order in DB
    $obOrder = Order::create([
        'status_id'     => 6,
        'transaction_id' => null,
        'secret_key'     => 'test-secret-run-001',
    ]);

    // Build component and inject order via reflection
    $obComponent = new RetryPayment();
    $obReflection = new ReflectionClass($obComponent);

    $obOrderProp = $obReflection->getProperty('obOrder');
    $obOrderProp->setAccessible(true);
    $obOrderProp->setValue($obComponent, $obOrder);

    // Mock $this->page to avoid CMS context error
    $obPageProp = $obReflection->getProperty('page');
    // Note: 'page' is a magic property on ComponentBase — may need to mock controller
    // See CampaignPricingShopaholic's component tests for the full pattern

    $obComponent->onRun();

    expect($obComponent->bIsRetryable)->toBeTrue();
    expect($obComponent->iCurrentPaymentMethodId)->toBe(0); // no payment_method_id set
});
```

**`onRetryPayment()` test pattern:**

```php
test('onRetryPayment returns redirect response on gateway redirect', function () {
    $obOrder = Order::create([
        'status_id'     => 6,
        'transaction_id' => null,
        'secret_key'     => 'test-secret-ajax-001',
    ]);

    $obMockGateway = Mockery::mock(PaymentGatewayInterface::class);
    $obMockGateway->shouldReceive('purchase')->once()->with(Mockery::type(Order::class));
    $obMockGateway->shouldReceive('isRedirect')->andReturn(true);
    $obMockGateway->shouldReceive('getRedirectURL')->andReturn('https://gateway.example.com/pay');

    $obPaymentMethod = PaymentMethod::create([
        'name' => 'Test Gateway', 'code' => 'tg', 'active' => true, 'gateway_id' => 'tg',
    ]);
    PaymentMethod::extend(fn($m) => $m->addDynamicMethod('getGatewayAttribute', fn() => $obMockGateway));

    // POST data simulation
    $_POST['payment_method_id'] = $obPaymentMethod->id;

    $obComponent = new RetryPayment();
    $obReflection = new ReflectionClass($obComponent);
    $obOrderProp = $obReflection->getProperty('obOrder');
    $obOrderProp->setAccessible(true);
    $obOrderProp->setValue($obComponent, $obOrder);

    $obResponse = $obComponent->onRetryPayment();

    expect($obResponse)->toBeInstanceOf(\Illuminate\Http\RedirectResponse::class);
});

test('onRetryPayment returns failure result when order is null', function () {
    $obComponent = new RetryPayment();
    // obOrder stays null (default)

    $arResult = $obComponent->onRetryPayment();

    expect($arResult)->toBeArray();
    expect($arResult['status'])->toBeFalse();
});
```

### Priority 3 — Extract and test PaymentMethod query

Create `ActiveGatewayPaymentMethodListStore` to cache the gateway payment method query from `RetryPayment::onRun()`:

```php
// tests/unit/ActiveGatewayPaymentMethodListStoreTest.php (to be created)
test('it returns only active methods with gateway_id set', function () { ... });
test('it excludes inactive payment methods', function () { ... });
test('it excludes methods with null gateway_id', function () { ... });
test('it excludes methods with empty string gateway_id', function () { ... });
```

### Priority 4 — QA toolchain verification

All QA tools are configured and passing. Run the full suite before any PR:

```bash
composer qa   # runs: pint-test → analyse (PHPStan lvl 10) → phpmd → test (Pest)
```

Each tool independently:
```bash
composer pint-test    # Format check (no changes)
composer analyse      # PHPStan level 10
composer phpmd        # Complexity / naming rules
composer test         # Pest unit tests
composer rector-dry   # PHP 8.4 upgrade suggestions (dry run)
```

---

## Testing Anti-Patterns to Avoid

1. **Do NOT test framework behavior** — don't test that `ComponentBase::componentDetails()` exists; test your implementation's return values
2. **Do NOT use `$this->assert*`** — use Pest's `expect()` API exclusively for consistency with the existing test files
3. **Do NOT share mutable state between tests** — always `->clear()` Store singletons in `beforeEach`; always `flushEventListeners()` (done by base class tearDown)
4. **Do NOT make real HTTP requests** — mock `PaymentGatewayInterface`; never call live gateway endpoints
5. **Do NOT hardcode status IDs** — use `RetryableStatusListStore::RETRYABLE_STATUS_IDS` constant in test fixtures, not magic numbers

---

## Convention Comparison: Storeextender vs CampaignPricing vs RetryPayment

| Practice | StoreExtender | CampaignPricing | RetryPayment |
|----------|---------------|-----------------|--------------|
| Test base class | Custom PHPUnit TestCase | Custom PHPUnit TestCase (identical pattern) | Custom PHPUnit TestCase (same) |
| Test style | PHPUnit class-based (`testXxx`) | Pest functional (`test()`) | Pest functional (`test()`) |
| Mockery | Not used | Used (CurrencyHelper singleton) | Used (PaymentGatewayInterface) |
| DB fixtures | Inline `->create()` | Inline `->makeFromData()` (no DB) | Inline `->create()` |
| Store cache clear | N/A | N/A | `->clear()` in beforeEach |

StoreExtender still uses the older PHPUnit class-based test style. **RetryPayment and CampaignPricing use the preferred Pest style** — use Pest for all new test files.

---

*Testing analysis: 2026-03-24*
