# PRODELYA M13-L Pre-M14 Regression Cleanup and Full-Suite Gate Report

Date: 2026-07-22
Branch: feature/master-restructure-phase-2-order-flow
Head: c7f2a80 checkpoint: production v1 closure

## Scope

Applied only Pre-M14 regression cleanup for:

- Settings seeded `tenant_accounts.panel_subdomain = demo` collision.
- Finance notification user-facing TRY/TL label truth.
- Graphic broad-filter Turkish history label regression surfaced during the requested gate.

No schema, global CSS, M14, staging, commit, tag, reset, restore, stash, or clean was performed.

## Settings Fixture Root Cause

| Layer | Existing behavior | Expected | Problem |
|---|---|---|---|
| Seeder | `DefaultTenantSeeder` creates the canonical seeded tenant with `panel_subdomain = demo`. | Keep seeded demo tenant and uniqueness intact. | Seeder is correct; duplicate creation in tests was the problem. |
| Test setUp | `TenantSettingsDomainReadinessTest` and `TenantSettingsLandingTest` called `$this->seed()` manually in `setUp()`. | Use Laravel `RefreshDatabase` seeding lifecycle once per refreshed database. | Broad `Settings` filter could already have seeded `demo`, then these tests attempted to seed `demo` again. |
| Tenant factory/helper | The domain readiness helper creates deterministic unique tenants such as `settings-domain-ready`; landing reuses the seeded tenant. | Reuse seeded tenant or deterministic unique tenants. | Helper data was already deterministic; manual reseeding broke uniqueness. |
| `panel_subdomain` unique | Unique constraint remains enforced. | Do not weaken uniqueness. | Preserved. |
| Test host | Tests use central host or deterministic tenant host. | Host resolution must remain stable. | Preserved. |

Strategy: replaced manual `$this->seed()` with `protected bool $seed = true;` in the two affected settings tests.

## Finance Notification Root Cause

| Layer | Existing behavior | Expected | Problem |
|---|---|---|---|
| Stored currency code | Payment input `TL` is normalized and stored as internal ISO `TRY`. | DB/internal value remains `TRY`. | Correct and preserved. |
| Notification formatter | `payment_currency` template variable used the stored code directly. | Turkish user-facing notification text displays `TL`. | User-facing preview rendered `1000 TRY`. |
| Test fixture | Creates a `TL` payment and expects notification copy to include `TL`. | Test should prove stored `TRY` plus rendered `TL`. | Added explicit stored-code and rendered-label assertions. |
| User-facing message | `Ödeme alındı... Tutar: 1000 TRY ...` | `Ödeme alındı... Tutar: 1000 TL ...` | Fixed through canonical display label service, not string replacement. |

Strategy: `NotificationVariableBuilder::buildForPayment()` now uses `TenantCurrencySettingsService::displayLabel($payment->currency)` for `payment_currency`. Internal payment currency remains `TRY`; USD/EUR labels are preserved by the canonical service.

## Graphic Gate Cleanup

During the requested `Graphic` broad filter, `GraphicShowHistoryTurkishTest` failed because the Graphic screen rendered `procurement_request_created` as `Tedarik kaydı oluşturuldu` where the Graphic history contract expects `Tedarik ihtiyacı oluşturuldu` while retaining the separate note `Tedarik kaydı oluşturuldu.`.

Applied a Graphic-only `historyLabel()` override in `GraphicModuleDataBuilder`; the shared `WorkFormActivityLabelResolver` was not changed.

## Changed Files

- `tests/Feature/TenantSettingsDomainReadinessTest.php`
- `tests/Feature/TenantSettingsLandingTest.php`
- `app/Services/Notifications/NotificationVariableBuilder.php`
- `tests/Feature/FinanceNotificationIntegrationTest.php`
- `app/Services/GraphicModuleDataBuilder.php`
- `docs/PRODELYA-M13-L-PRE-M14-REGRESSION-CLEANUP-FULL-SUITE-RAPORU-20260722.md`

Note: `app/Services/GraphicModuleDataBuilder.php` and many other files were already dirty in the worktree before this report; unrelated dirty work was preserved.

## Syntax Checks

- `php -l tests/Feature/TenantSettingsDomainReadinessTest.php`: PASS
- `php -l tests/Feature/TenantSettingsLandingTest.php`: PASS
- `php -l app/Services/Notifications/NotificationVariableBuilder.php`: PASS
- `php -l tests/Feature/FinanceNotificationIntegrationTest.php`: PASS
- `php -l app/Services/GraphicModuleDataBuilder.php`: PASS

## Targeted Tests

- `php artisan test --filter=TenantSettingsDomainReadinessTest --stop-on-failure`: PASS, 3 tests, 30 assertions
- `php artisan test --filter=TenantSettingsLandingTest --stop-on-failure`: PASS, 3 tests, 43 assertions
- `php artisan test --filter=FinanceNotificationIntegrationTest --stop-on-failure`: PASS, 1 test, 51 assertions
- `php artisan test --filter=GraphicShowHistoryTurkishTest --stop-on-failure`: PASS, 1 test, 10 assertions

## Broad Tests

- `php artisan view:clear`: PASS
- `php artisan view:cache`: PASS
- `php artisan test --filter=TenantCurrency --stop-on-failure`: PASS, 24 tests, 119 assertions
- `php artisan test --filter=Settings --stop-on-failure`: PASS, 74 tests, 696 assertions
- `php artisan test --filter=Finance --stop-on-failure`: PASS, 89 tests, 902 assertions
- `php artisan test --filter=ProcurementDraftPriceRefreshTest --stop-on-failure`: PASS, 4 tests, 33 assertions
- `php artisan test --filter=Procurement --stop-on-failure`: PASS, 131 tests, 1840 assertions
- `php artisan test --filter=Production --stop-on-failure`: PASS, 165 tests, 2273 assertions
- `php artisan test --filter=Graphic --stop-on-failure`: PASS, 114 tests, 1572 assertions
- `php artisan test --filter=Order --stop-on-failure`: PASS, 263 tests, 2371 assertions
- `php artisan test --filter=AdminSmokeTest --stop-on-failure`: PASS, 59 tests, 214 assertions

## Full-Suite Result

`php artisan test` was run twice. The second run wrote `.tmp/m13-l-full-suite.xml`.

Result: FAILED

- Tests: 2213
- Passed: 2193
- Failures: 17
- Errors: 3
- Assertions: 21095
- Duration: about 623 seconds on the logged run

## Remaining Full-Suite Failures and Errors

| Kind | Test | File |
|---|---|---|
| FAIL | `Tests.Feature.CompanyContactAddressActionsTest::test_company_detail_shows_active_contact_and_address_actions_with_clean_copy` | `tests/Feature/CompanyContactAddressActionsTest.php:43` |
| FAIL | `Tests.Feature.CompanyContactAddressActionsTest::test_contact_and_address_empty_states_are_user_friendly` | `tests/Feature/CompanyContactAddressActionsTest.php:108` |
| FAIL | `Tests.Feature.CompanySubcontractorPrintRoleUxTest::test_internal_assignment_allows_empty_company_and_external_assignment_keeps_company_id` | `tests/Feature/CompanySubcontractorPrintRoleUxTest.php:163` |
| FAIL | `Tests.Feature.FullOperationalFlowSmokeTest::test_full_operational_flow_smoke_covers_all_operation_modules_and_security` | `tests/Feature/FullOperationalFlowSmokeTest.php:57` |
| FAIL | `Tests.Feature.NotificationSecurityHardeningTest::test_notification_feature_guards_and_menu_visibility_follow_access_rules` | `tests/Feature/NotificationSecurityHardeningTest.php:251` |
| FAIL | `Tests.Feature.NotificationV1EndToEndSmokeTest::test_notification_v1_end_to_end_smoke_covers_domain_events_and_channels` | `tests/Feature/NotificationV1EndToEndSmokeTest.php:92` |
| FAIL | `Tests.Feature.PermanentCategoryBackboneLockTest::test_tenant_category_selection_shows_permanent_categories_and_hides_archived` | `tests/Feature/PermanentCategoryBackboneLockTest.php:83` |
| FAIL | `Tests.Feature.PermanentCategoryBackboneLockTest::test_product_data_hub_overview_shows_category_reset_metrics` | `tests/Feature/PermanentCategoryBackboneLockTest.php:131` |
| ERROR | `Tests.Feature.PermissionRelationDiagnosticTest::test_permission_relation_chain` | `tests/Feature/PermissionRelationDiagnosticTest.php:16` |
| FAIL | `Tests.Feature.ProductDataHubFinalUiCleanupTest::test_working_local_csv_import_remains_visible` | `tests/Feature/ProductDataHubFinalUiCleanupTest.php:63` |
| FAIL | `Tests.Feature.ProductHubFinalUiTerminologyRadiusTest::test_daily_product_hub_screens_use_abone_firma_language` | `tests/Feature/ProductHubFinalUiTerminologyRadiusTest.php:26` |
| FAIL | `Tests.Feature.ProductHubSupplierFlowStepperTest::test_supplier_flows_screen_renders_eight_step_stepper_and_stateful_actions` | `tests/Feature/ProductHubSupplierFlowStepperTest.php:46` |
| FAIL | `Tests.Feature.ProductHubTemplateCleanupTest::test_supplier_flow_cards_use_single_primary_cta_and_clear_catalog_message` | `tests/Feature/ProductHubTemplateCleanupTest.php:61` |
| FAIL | `Tests.Feature.ProductSelectionWarningDisplayTest::test_quote_workspace_source_contains_readonly_product_code_and_sku_dropdown_meta` | `tests/Feature/ProductSelectionWarningDisplayTest.php:1237` |
| FAIL | `Tests.Feature.PublicLinkScreensUxPolishTest::test_public_link_screens_use_customer_facing_copy_and_keep_security_boundaries` | `tests/Feature/PublicLinkScreensUxPolishTest.php:78` |
| FAIL | `Tests.Feature.QuickCustomerWhatsappPhoneTest::test_quote_quick_customer_modal_shows_whatsapp_phone_label_and_prefix` | `tests/Feature/QuickCustomerWhatsappPhoneTest.php:30` |
| ERROR | `Tests.Feature.QuotePrintDefaultPriceSuggestionTest::test_store_edit_and_conversion_preserve_manual_print_price_totals_and_keep_default_setup_cost_passive` | `tests/Feature/QuotePrintDefaultPriceSuggestionTest.php:87` |
| FAIL | `Tests.Feature.TenantAdvancedCatalogTest::test_tenant_can_edit_and_deactivate_local_product` | `tests/Feature/TenantAdvancedCatalogTest.php:373` |
| FAIL | `Tests.Feature.TenantAdvancedCatalogTest::test_supplier_purchase_uses_discount_calculation_and_manual_purchase_price` | `tests/Feature/TenantAdvancedCatalogTest.php:764` |
| ERROR | `Tests.Feature.TenantResolverDiagnosticTest::test_tenant_resolver_debug` | `tests/Feature/TenantResolverDiagnosticTest.php:42` |

Observed concrete messages from the runner include:

- `CompanyContactAddressActionsTest`: expected copy was not present in rendered company detail HTML.
- `TenantAdvancedCatalogTest::test_tenant_can_edit_and_deactivate_local_product`: expected `stock_quantity = 15`, found `50`.
- `TenantAdvancedCatalogTest::test_supplier_purchase_uses_discount_calculation_and_manual_purchase_price`: expected purchase entry row, but `tenant_supplier_purchase_entries` was empty.
- `PermissionRelationDiagnosticTest` and `TenantResolverDiagnosticTest`: duplicate `roles.key = admin` unique constraint errors.
- `QuotePrintDefaultPriceSuggestionTest`: missing `OrderItemPrintSetupRequirement` model row.

## Manual Smoke Assessment

Covered by automated route and integration tests in this phase:

- Settings landing/domain/currency permission surfaces passed through `Settings` and `TenantCurrency` filters.
- Finance notification path passed through `FinanceNotificationIntegrationTest` and `Finance` filter.
- Procurement 164.49, Production, Graphic, Order, and AdminSmoke filters passed.

No browser/manual click smoke was performed in this turn.

## Worktree, Staging, Commit

- `git diff --cached --name-only`: empty before full suite.
- No staging or commit was performed.
- No tag was created.
- Existing unrelated dirty worktree changes were preserved.
- Full-suite JUnit artifact: `.tmp/m13-l-full-suite.xml`.

## Final State

BLOCKED — FULL SUITE HAS REMAINING REGRESSIONS
