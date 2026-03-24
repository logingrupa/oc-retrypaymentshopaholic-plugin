# Coding Conventions

**Analysis Date:** 2026-03-24

## Summary Assessment

The plugin is small (4 PHP source files, 1 Twig template) and largely well-formed. All mandatory conventions are met. A few gaps exist relative to strict Lovata.Toolbox best practices and the project's DRY/SRP requirements — documented in detail below.

---

## Hungarian Notation

**Status: COMPLIANT**

All variables consistently use the correct Hungarian prefix.

| Prefix | Observed Usage | Example |
|--------|----------------|---------|
| `$ob`  | Model/object instances | `$obOrder`, `$obGateway`, `$obPaymentMethod`, `$obOrderPage` |
| `$ar`  | Arrays | `$arRetryableStatusIdList` |
| `$i`   | Integers | `$iPaymentMethodId`, `$iCurrentPaymentMethodId` |
| `$s`   | Strings | `$sRedirectURL` |
| `$b`   | Booleans | `$bIsRetryable` |

Public component properties follow the same convention: `$bIsRetryable`, `$obPaymentMethodList`, `$iCurrentPaymentMethodId`.

**One deviation:** `$obPaymentMethodList` is typed as `\October\Rain\Database\Collection<PaymentMethod>|null` — this is acceptable (Eloquent Collection is an object), but future code should use a Toolbox `ElementCollection` subclass when a Store-backed approach is warranted.

---

## Naming Patterns

**Files:**
- PascalCase for all PHP class files: `RetryPayment.php`, `RetryPaymentHelper.php`, `RetryableStatusListStore.php`
- Component template directory mirrors component class name in snake_case: `components/retrypayment/default.htm`

**Classes:**
- PascalCase: `RetryPayment`, `RetryPaymentHelper`, `RetryableStatusListStore`
- Component names descriptive of function, not domain entity (correct — it's an action, not a model)
- Helper suffix for static utility classes: `RetryPaymentHelper` (correct pattern)
- Store suffix for store classes: `RetryableStatusListStore` (correct pattern)

**Methods:**
- camelCase: `isRetryable()`, `retry()`, `onRetryPayment()`, `onRun()`, `init()`
- AJAX handlers prefixed with `on`: `onRetryPayment()` (correct October CMS convention)
- `get` prefix for Toolbox store method: `getIDListFromDB()` (correct)

**Constants:**
- UPPER_SNAKE_CASE: `RETRYABLE_STATUS_IDS` (correct)

**Namespace:**
- `Logingrupa\RetrypaymentShopaholic` — uses `Logingrupa` (lowercase 'g'), consistent with established convention in this codebase. Do NOT use `LoginGrupa`.

---

## Code Style

**Formatter:** Laravel Pint (`pint.json`) using PSR-12 preset.

**Key rules in `pint.json`:**
- `blank_line_after_opening_tag`: disabled (no blank line after `<?php`)
- `blank_lines_before_namespace`: disabled
- `no_unused_imports`: enforced
- `ordered_imports`: alphabetical

**Linting tools configured:**
- PHPStan at level 10 (`phpstan.neon`) — zero errors, clean baseline (`phpstan-baseline.neon` is empty)
- PHPMD (`phpmd.xml`) — Lovata standard thresholds (cyclomatic complexity ≤ 10, method length ≤ 100 lines)
- Rector (`rector.php`) — PHP 8.4 sets, dead code, code quality, type declarations

**`declare(strict_types=1)`** — used in test files but NOT in production source files. This is a known inconsistency. Production PHP files under `classes/` and `components/` do not declare strict types.

---

## Import Organization (PHP)

**Pattern observed:** Grouped by category, alphabetical within each group, separated by blank lines.

```php
// Vendor framework
use Cms\Classes\ComponentBase;
use Kharanenka\Helper\Result;

// Plugin-local
use Logingrupa\RetrypaymentShopaholic\Classes\Helper\RetryPaymentHelper;

// OrdersShopaholic
use Lovata\OrdersShopaholic\Components\OrderPage;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\PaymentMethod;

// Laravel facades (no use statement — resolved via global alias)
use Redirect;
```

`Redirect` is imported as a global alias rather than `Illuminate\Support\Facades\Redirect`. Either approach is acceptable in October CMS, but be consistent within a file.

---

## Error Handling

**Pattern:** `try/catch (\Exception $obException)` with `$obException` naming (Hungarian `$ob` for exception object).

```php
// Correct pattern (from RetryPayment::onRetryPayment)
try {
    // ...
} catch (\Exception $obException) {
    return Result::setFalse()->setMessage($obException->getMessage())->get();
}
```

**Business logic errors:** `\RuntimeException` is thrown from `RetryPaymentHelper` with translated messages via `trans()`. The component catches and converts to `Result` failure response.

**Null guards:** Early-return pattern used consistently when `$obOrder === null`.

**`Kharanenka\Helper\Result`** — the Lovata standard response helper for AJAX handlers. Used correctly: `Result::setTrue()`, `Result::setFalse()`, `Result::setMessage()`, `->get()`.

---

## Docblocks

**Status: COMPLIANT**

All public and protected methods have docblocks. Format:
```php
/**
 * Brief one-line description.
 * Longer explanation when needed.
 *
 * @param Type $varName   Description
 * @return ReturnType
 * @throws \ExceptionClass When condition
 */
```

Class-level docblocks include `@package` and `@author`. PHPStan `@var` annotations used on class properties.

---

## Lovata.Toolbox Patterns

### Store: `RetryableStatusListStore` — COMPLIANT
- Extends `AbstractStoreWithoutParam` (correct for a parameter-free list)
- Implements `getIDListFromDB()` only — does not override `get()` or cache logic directly
- Uses `Status::whereIn()->pluck()->all()` — DB query isolated to store, not leaking into components
- Exposes `RETRYABLE_STATUS_IDS` constant for use by tests and the store itself
- `protected static string $instance = self::class` — required singleton registration

### Helper: `RetryPaymentHelper` — ACCEPTABLE, with SRP concern
- Static utility class (no Toolbox base class, appropriate for a helper with no state)
- `isRetryable()` and `retry()` are two distinct responsibilities that currently co-reside. For level-10 SRP compliance these could be split into `RetryEligibilityChecker` and `RetryPaymentExecutor`, but at the current plugin scope the single helper class is pragmatically acceptable.
- **DRY concern:** `isRetryable()` is called at the top of `retry()` to gate execution, AND called again from the component in `onRun()`. This is correct — a guard call in `retry()` is a proper defensive check, not duplication.

### Component: `RetryPayment` — MOSTLY COMPLIANT, gaps noted

**Correct patterns:**
- Extends `Cms\Classes\ComponentBase`
- `init()` + `onRun()` separation: `init()` resolves sibling component reference, `onRun()` populates page variables
- Public page variables exposed via `$this->page['key']` AND as public properties (both patterns used — this is correct for CMS components)
- AJAX handler follows `on` prefix convention

**Gap 1 — Direct Eloquent query in component:**
```php
// In RetryPayment::onRun() — lines 83–87
$this->obPaymentMethodList = PaymentMethod::isActive()
    ->whereNotNull('gateway_id')
    ->where('gateway_id', '!=', '')
    ->get();
```
Direct Eloquent in a component violates the Store → Collection → Item pattern. This query has no caching. For a high-traffic order page, this hits the DB on every page load for every retryable order. A `ActiveGatewayPaymentMethodListStore` would be the correct Toolbox approach.

**Gap 2 — No `ElementPage` base class:**
The component does not use `ElementPage` because it does not own the order lookup (it delegates to `OrderPage`). This delegation pattern is intentional and correct — do not change it.

**Gap 3 — `$obPaymentMethodList` is an Eloquent Collection, not a Toolbox Item collection:**
Acceptable given the simplicity, but Twig templates iterate it directly via model attributes rather than Item properties. If PaymentMethod gains cached Item support, templates would need updating.

---

## Template Conventions (`components/retrypayment/default.htm`)

**Status: COMPLIANT**

- Larajax `data-request` attribute on button (no jQuery, no inline JS)
- `data-request-form` scopes form serialization correctly
- `|_` Twig filter for translations (RainLab Translate convention)
- Conditional guard `{% if bIsRetryable %}` wraps entire output — no empty DOM nodes rendered for non-retryable orders
- No hardcoded strings (uses translation filter)
- CSS class names use BEM-like conventions: `retry-payment-container`, `retry-payment-methods`, `retry-payment-method`

**One note:** Strings `'Retry payment'` and `'Your payment was not completed...'` are hardcoded in the template using `|_` filter. These strings also exist in `lang/en/lang.php`. The template should reference the lang keys via `'logingrupa.retrypaymentshopaholic::lang.component.button'|_` rather than bare English strings to ensure Translate plugin picks them up reliably. Currently the `|_` filter on a bare string works only if Translate has a registered translation for that exact string.

---

## DRY / SRP Assessment

**DRY: COMPLIANT**
- `isRetryable()` logic lives in exactly one place (`RetryPaymentHelper`)
- Status IDs defined once as `RETRYABLE_STATUS_IDS` constant, referenced by both the store and tests
- No logic duplication observed across classes

**SRP: MOSTLY COMPLIANT**
- `RetryPaymentHelper` has two public static methods (`isRetryable`, `retry`) covering two related concerns. Acceptable at current scale.
- `RetryPayment::onRun()` does two things: determines retryability AND loads payment methods. Could be extracted to two private methods (`determineRetryability()`, `loadPaymentMethods()`) for clarity, but complexity is low.

---

## What is Missing / What to Add

When extending this plugin, follow these conventions:

1. **New stores** — extend `AbstractStoreWithoutParam` or `AbstractStoreWithParam`, implement only `getIDListFromDB()`, register cache clear in a `ModelHandler`
2. **New AJAX handlers** — prefix with `on`, catch `\Exception $obException`, return `Result::get()`
3. **New variables** — always apply Hungarian notation prefix
4. **New Eloquent queries** — never in components or items; isolate to Store classes
5. **Translations** — always use lang key strings (`'plugin::lang.section.key'|_`) not bare English strings with `|_`
6. **`declare(strict_types=1)`** — add to new production PHP files (align with test files)

---

*Convention analysis: 2026-03-24*
