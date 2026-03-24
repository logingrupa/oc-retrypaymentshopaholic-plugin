# Codebase Structure

**Analysis Date:** 2026-03-24

## Directory Layout

```
plugins/logingrupa/retrypaymentshopaholic/
├── Plugin.php                          # Plugin manifest, component registration
├── composer.json                       # Package definition, QA scripts
├── phpunit.xml                         # Pest/PHPUnit config (SQLite in-memory)
├── phpstan.neon                        # PHPStan level 10 config
├── phpstan-baseline.neon               # PHPStan suppressed findings
├── phpmd.xml                           # PHP Mess Detector rules
├── pint.json                           # Laravel Pint (PSR-12) formatter config
├── rector.php                          # Rector PHP 8.4 upgrade rules
│
├── classes/
│   ├── helper/
│   │   └── RetryPaymentHelper.php      # Static business logic: isRetryable(), retry()
│   └── store/
│       └── RetryableStatusListStore.php # Toolbox Store: cached retryable status ID list
│
├── components/
│   ├── RetryPayment.php                # CMS component: page data + AJAX handler
│   └── retrypayment/
│       └── default.htm                 # Twig partial: retry payment form
│
├── lang/
│   ├── en/lang.php                     # English strings (canonical)
│   ├── lv/lang.php                     # Latvian
│   ├── lt/lang.php                     # Lithuanian
│   ├── nb/lang.php                     # Norwegian Bokmål
│   └── ru/lang.php                     # Russian
│
├── tests/
│   ├── RetryPaymentTestCase.php        # Base test case (Laravel 12 / PHPUnit 12 compatible)
│   └── unit/
│       ├── RetryableStatusListStoreTest.php  # Store: caching, DB validation, clear
│       ├── RetryPaymentHelperTest.php         # Helper: eligibility, gateway call, mutation
│       └── RetryPaymentComponentTest.php      # Component: metadata, defaults
│
├── updates/
│   └── version.yaml                    # Plugin version history (no migration scripts)
│
└── .planning/
    └── codebase/                       # GSD mapping documents
        ├── ARCHITECTURE.md
        └── STRUCTURE.md
```

## Directory Purposes

**`classes/helper/`:**
- Purpose: Stateless utility classes with pure business logic
- Convention: PascalCase filename ending in `Helper` (e.g., `RetryPaymentHelper.php`)
- Rule: No constructor, all `public static` methods, dependencies passed as parameters
- Tests: Corresponding file in `tests/unit/` with same name + `Test` suffix

**`classes/store/`:**
- Purpose: Lovata.Toolbox Store singletons — cache-backed ID list providers
- Convention: PascalCase filename ending in `Store` (e.g., `RetryableStatusListStore.php`)
- Base class required: `Lovata\Toolbox\Classes\Store\AbstractStoreWithoutParam` (no parameter), `AbstractStoreWithParam` (one parameter), `AbstractStoreWithTwoParam` (two parameters)
- Each store must implement `getIDListFromDB(): array`
- Tests: Verify `get()`, `clear()`, and DB-validation behaviour

**`components/`:**
- Purpose: CMS component PHP class + Twig partial directory
- Convention: Component class is PascalCase at `components/ClassName.php`; partials live in `components/classname/` (lowercase folder matching alias)
- Default template: `components/classname/default.htm` — auto-loaded unless overridden in CMS page
- Component alias registered in `Plugin::registerComponents()` — alias must match folder name

**`lang/`:**
- Purpose: Translation strings consumed via `trans()` in PHP and `|_` filter in Twig
- Convention: `lang/{locale}/lang.php` returns a nested PHP array
- Key format: `logingrupa.retrypaymentshopaholic::lang.{group}.{key}`
- Groups in use: `plugin` (name, description), `component` (labels, errors)
- `en/lang.php` is canonical; all other locales mirror the same key structure

**`tests/`:**
- Purpose: Pest test suites using SQLite in-memory DB
- `RetryPaymentTestCase.php`: Custom base class replacing October's `PluginTestCase` for PHPUnit 12 visibility compatibility — all plugin test classes use this as their base
- Test files use Pest syntax (`uses(RetryPaymentTestCase::class)`, `test()`, `beforeEach()`)
- Unit tests in `tests/unit/` — no separate integration or feature test directories currently

**`updates/`:**
- Purpose: Plugin version history and migration scripts
- `version.yaml`: Lists semver versions with description strings
- Migration PHP files go here when DB changes are needed (none currently)
- Convention: migration filename is snake_case descriptive, e.g., `table_create_my_table.php`

## Key File Locations

**Entry Points:**
- `Plugin.php`: Component registration, dependency declaration (`Lovata.OrdersShopaholic` required)
- `components/RetryPayment.php`: All runtime logic — `init()`, `onRun()`, `onRetryPayment()`

**Business Logic:**
- `classes/helper/RetryPaymentHelper.php`: `isRetryable()` and `retry()` — the only two domain operations
- `classes/store/RetryableStatusListStore.php`: Status whitelist constant `RETRYABLE_STATUS_IDS = [4, 6, 7]`

**Templates:**
- `components/retrypayment/default.htm`: Twig form partial; references `bIsRetryable`, `obPaymentMethodList`, `iCurrentPaymentMethodId`

**Tests:**
- `tests/RetryPaymentTestCase.php`: Base class for all tests
- `tests/unit/RetryPaymentHelperTest.php`: Covers all `isRetryable` branches + gateway mutation
- `tests/unit/RetryableStatusListStoreTest.php`: Covers cache, clear, DB-validation
- `tests/unit/RetryPaymentComponentTest.php`: Covers component metadata and public property defaults

**Configuration:**
- `phpunit.xml`: Test runner config; bootstrap path `../../../modules/system/tests/bootstrap.php`
- `phpstan.neon`: Static analysis at level 10; paths `classes`, `components`, `Plugin.php`
- `pint.json`: PSR-12 preset; alphabetically sorted imports; `no_unused_imports` enforced
- `rector.php`: PHP 8.4 target; `deadCode`, `codeQuality`, `typeDeclarations` prepared sets

## Naming Conventions

**Files:**
- Component classes: PascalCase, no suffix — `RetryPayment.php`
- Store classes: PascalCase + `Store` suffix — `RetryableStatusListStore.php`
- Helper classes: PascalCase + `Helper` suffix — `RetryPaymentHelper.php`
- Test files: Mirror source name + `Test` suffix — `RetryPaymentHelperTest.php`
- Twig partials: lowercase folder matching component alias — `retrypayment/default.htm`

**PHP Identifiers (Hungarian notation — mandatory):**
- `$ob` prefix: object, model, component instance — `$obOrder`, `$obPaymentMethod`, `$obGateway`
- `$ar` prefix: array — `$arRetryableStatusIdList`
- `$i` prefix: integer — `$iPaymentMethodId`, `$iCurrentPaymentMethodId`
- `$s` prefix: string — `$sRedirectURL`
- `$b` prefix: boolean — `$bIsRetryable`
- Exception variables: `$obException` (object prefix)
- Constants: `UPPER_SNAKE_CASE` — `RETRYABLE_STATUS_IDS`

**Namespace:**
- Plugin root: `Logingrupa\RetrypaymentShopaholic`
- Components: `Logingrupa\RetrypaymentShopaholic\Components`
- Stores: `Logingrupa\RetrypaymentShopaholic\Classes\Store`
- Helpers: `Logingrupa\RetrypaymentShopaholic\Classes\Helper`
- Tests: `Logingrupa\RetrypaymentShopaholic\Tests`

## Where to Add New Code

**New eligibility condition:**
- Edit `classes/helper/RetryPaymentHelper.php::isRetryable()` — add guard before `return true`
- Add corresponding test case to `tests/unit/RetryPaymentHelperTest.php`

**New AJAX handler on the component:**
- Add `public function onHandlerName()` to `components/RetryPayment.php`
- Reference in Twig as `data-request="RetryPayment::onHandlerName"`
- Keep handler thin: delegate to a helper or inline only if trivial (< ~10 lines)

**New cached data source:**
- Create `classes/store/XxxListStore.php` extending the appropriate `AbstractStoreWith*` base
- Call from helper or component via `XxxListStore::instance()->get()`

**New helper:**
- Create `classes/helper/XxxHelper.php` — static methods, no constructor
- Keep each helper focused on one domain concern (SRP)

**New lang key:**
- Add to `lang/en/lang.php` first, then mirror key in all other locales under `lang/`
- Consume in PHP: `trans('logingrupa.retrypaymentshopaholic::lang.group.key')`
- Consume in Twig: `'String'|_` (RainLab.Translate filter) or explicit key `'key'|trans`

**New DB migration:**
- Create `updates/migration_description.php` with `Schema::` calls
- Register in `updates/version.yaml` under the next semver version
- Run `php artisan october:migrate` or install via plugin manager

**New test:**
- Add test file to `tests/unit/`
- Start with `uses(RetryPaymentTestCase::class);`
- Use Pest syntax: `test('...', function () { ... })`; `beforeEach()` for shared setup

## Special Directories

**`.planning/`:**
- Purpose: GSD workflow planning artifacts and codebase analysis documents
- Generated: By GSD map-codebase and plan-phase commands
- Committed: Yes — documents are part of the plugin repository

**`updates/`:**
- Purpose: Migration scripts and version manifest
- Generated: No — hand-authored
- Committed: Yes

**`.git/`:**
- Purpose: This plugin is its own git repository (separate from the monorepo)
- Committed: N/A

---

*Structure analysis: 2026-03-24*
