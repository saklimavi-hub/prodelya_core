# PRODELYA M13-K — Tenant Currency Settings Permission Truth Hotfix Raporu — 2026-07-22

## Kapsam
- Dar kapsam: `/admin/settings/currency` izin gerçeği, menü görünürlüğü ve güvenli diagnostic test.
- Dokunulmadı: authorization guard gevşetme, schema, global CSS, M14, Production V1 davranışı, staging/commit/tag.
- Staged alan kullanılmadı; mevcut dirty worktree korunmuştur.

## Reproduction
İlk koşu:
- `php artisan test --filter=TenantCurrencySettingsDiagnosticTest --stop-on-failure`
- Sonuç: FAIL, `TenantCurrencySettingsDiagnosticTest::test_currency_settings_permission_debug`, expected 200, actual 403.

Kanonik sınıf kontrolü:
- `php artisan test --filter=TenantCurrencySettingsTest --stop-on-failure`
- Sonuç: PASS, 18 tests / 99 assertions.

## Kanıtlanan Permission Truth
Route sözleşmesi:
- GET `/admin/settings/currency` -> `admin.settings.currency`
- PUT `/admin/settings/currency` -> `admin.settings.currency.update`
- POST `/admin/settings/currency/refresh-rates` -> `admin.settings.currency.refresh-rates`
- Route middleware: `module.enabled:tenant_settings` + `module.enabled:multi_currency`.

Controller sözleşmesi:
- `SettingsController::currency`, `updateCurrency`, `refreshCurrencyRates` aynı dar guard'ı kullanır: `authorizeCurrencySettingsAccess()`.
- Guard kaldırılmadı veya gevşetilmedi.
- Canonical allow: `manage_users` veya `User::canViewFinancialData($tenant->id)`.
- Canonical deny: permission yoksa 403.

Fixture truth:
- Diagnostic fixture önce tenant host/module setup olmadan debug var_dump ile çalışıyordu; local/testing central-host fallback yüzünden 403/200 sinyali gerçek tenant route sözleşmesini kanıtlamıyordu.
- Yeni diagnostic test tenant subdomain URL'si ve explicit `tenant_settings` + `multi_currency` module setup kullanır.
- Debug payload/secret/path/role dump kaldırıldı.

Menu truth:
- Controller finance-permission kullanıcıya GET izni verirken menu sadece `manage_users` arıyordu.
- `config/admin_menu.php` currency item `permission_any` ile controller truth'a hizalandı: `manage_users` veya finance visibility permissions.
- Operator role menüde currency link görmez ve route 403 alır.

## Matrix
| İşlem | Kullanıcı | Beklenen | Durum |
|---|---|---:|---|
| GET currency settings | tenant admin `manage_users` | 200 | PASS |
| GET currency settings | finance-authorized admin | 200 | PASS |
| GET currency settings | sıradan tenant user | 403 | PASS |
| PUT currency settings | authorized admin | redirect | PASS |
| PUT currency settings | unauthorized user | 403 | PASS |
| POST refresh | unauthorized user | 403 | PASS |
| foreign tenant | başka tenant admin on main tenant host | 403 | PASS |
| menu visibility | route guard ile aynı truth | visible/hidden | PASS |
| diagnostic/debug | secret-free | safe | PASS |

## Değişen Dosyalar
- `config/admin_menu.php`: currency settings menu permission truth, controller guard ile hizalandı.
- `tests/Feature/TenantCurrencySettingsDiagnosticTest.php`: unsafe var_dump diagnostic yerine host/module/permission/menu/tenant-isolation assertions.
- `tests/Feature/SettingsNotificationTemplateCssPolishTest.php`: stale active-sidebar assertion güncellendi; eski ve yeni sidebar item sınıfları toplamında tam 1 active state arar.

## Test Sonuçları
- PASS: `php artisan test --filter=TenantCurrencySettingsDiagnosticTest --stop-on-failure` — 2 tests, 15 assertions.
- PASS: `php artisan test --filter=TenantCurrencySettingsTest --stop-on-failure` — 18 tests, 99 assertions.
- PASS: `php artisan test --filter=TenantCurrency --stop-on-failure` — 24 tests, 119 assertions.
- PASS: `php artisan view:clear`.
- PASS: `php artisan view:cache`.
- PASS: `php artisan test --filter=CurrencySettings --stop-on-failure` — 20 tests, 114 assertions.
- OUT OF SCOPE FAIL: `php artisan test --filter=Settings --stop-on-failure` progressed past the active-sidebar assertion, then stopped on seeded fixture collisions in `TenantSettingsDomainReadinessTest` / `TenantSettingsLandingTest`: duplicate `tenant_accounts.panel_subdomain = demo`.
- OUT OF SCOPE FAIL: `php artisan test --filter=Finance --stop-on-failure` stopped at `FinanceNotificationIntegrationTest`, notification rendered `1000 TRY` while the test expects `TL`.
- PASS: `php artisan test --filter=ProcurementDraftPriceRefreshTest --stop-on-failure` — 4 tests, 33 assertions.
- PASS: `php artisan test --filter=Procurement --stop-on-failure` — 131 tests, 1840 assertions.
- PASS: `php artisan test --filter=Production --stop-on-failure` — 165 tests, 2273 assertions.
- PASS: `php artisan test --filter=Order --stop-on-failure` — 263 tests, 2371 assertions.
- PASS: `php artisan test --filter=AdminSmokeTest --stop-on-failure` — 59 tests, 214 assertions.

## Git Safety
- No staging, commit, tag, reset, restore, stash or clean performed.
- `git diff --cached --name-only` returned empty.
- Existing unrelated dirty worktree changes were preserved.

READY — TENANT CURRENCY SETTINGS PERMISSION TRUTH HOTFIX — MANUAL SMOKE REQUIRED
