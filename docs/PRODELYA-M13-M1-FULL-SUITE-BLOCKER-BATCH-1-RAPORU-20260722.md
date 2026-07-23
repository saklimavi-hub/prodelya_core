# PRODELYA M13-M1 Full-Suite Blocker Batch 1 Report

Date: 2026-07-22
Branch: feature/master-restructure-phase-2-order-flow

## Scope

Applied only Full-Suite Blocker Batch 1 cleanup for:

- duplicate seeded `roles.key = admin` diagnostic errors,
- `QuotePrintDefaultPriceSuggestionTest` passive setup-requirement error,
- `CompanySubcontractorPrintRoleUxTest` assignment guard/redirect expectation,
- Notification security and E2E failures,
- `FullOperationalFlowSmokeTest` attribution.

No Product Hub/catalog/UI failures were touched in this batch. No schema, global CSS, M14, staging, commit, tag, reset, restore, stash, or clean was performed.

## Duplicate Seeded Admin Role Diagnostics

Root cause: diagnostic tests created an `admin` role while the refreshed test database was already seeded with the canonical `roles.key = admin` row. The unique constraint and seed data were correct; the tests were duplicating canonical fixture data.

Fix:

- `PermissionRelationDiagnosticTest` now uses `RefreshDatabase` seeding and reuses the seeded tenant and seeded `admin` role.
- `TenantResolverDiagnosticTest` now uses `RefreshDatabase` seeding, reuses the seeded `admin` role, and makes the tenant host deterministic for resolver coverage.
- Diagnostic dump output was removed from both tests.

The `roles.key` uniqueness constraint and seeded admin role remain intact.

## Quote Print Passive Setup Policy

Root cause: the failing test expected an `OrderItemPrintSetupRequirement` row even though the V1 passive intermediate/setup policy keeps setup requirement generation inactive by default.

Fix:

- The test still proves manual print-price totals survive quote edit and order conversion.
- The fixture now asserts that no setup requirement row is generated under the passive default policy.
- No setup row is forced just to pass the test.

Existing active-policy coverage remains responsible for proving setup requirement generation when the feature flag is explicitly enabled.

## Production Assignment Truth

Root cause: production assignment/status routes were mixing canonical workflow return behavior with tests that explicitly expected the generic production show page.

Fix:

- `ProductionController` now honors explicit `return_to = show` and `return_to = index`.
- Workflow-specific stale return targets are normalized through the canonical production route, preserving internal vs outsourced truth.
- `CompanySubcontractorPrintRoleUxTest` and `FullOperationalFlowSmokeTest` now state their generic show redirect intent explicitly with `return_to = show`.

Internal assignment still allows an empty `production_company_id`; outsourced assignment still preserves the selected eligible production company.

## Notification Security And E2E

Root cause:

- The security test asserted a stale standalone menu label instead of the canonical settings menu entry that exposes notification settings.
- The E2E smoke attempted to start an internal production without first assigning an operator, so the expected production-started notification path was never reached.

Fix:

- Menu visibility assertions now use `Sistem Ayarları` while route/menu permission checks remain intact.
- Disabled notification features still return explicit `403` and disappear from the settings page route list.
- Notification E2E now performs a canonical internal assignment before starting production.

Notification route, menu, and permission authorization were not weakened.

## Full Operational Flow Attribution

Root cause: the smoke failure was attributable to the same production return-route contract mismatch, not to a broken operational flow.

Fix:

- The smoke now passes explicit `return_to = show` for the production steps where it asserts the generic show route.
- Canonical default behavior remains covered by production integration tests.

## Changed Files

- `app/Http/Controllers/Admin/ProductionController.php`
- `tests/Feature/CompanySubcontractorPrintRoleUxTest.php`
- `tests/Feature/FullOperationalFlowSmokeTest.php`
- `tests/Feature/NotificationSecurityHardeningTest.php`
- `tests/Feature/NotificationV1EndToEndSmokeTest.php`
- `tests/Feature/QuotePrintDefaultPriceSuggestionTest.php`
- `tests/Feature/PermissionRelationDiagnosticTest.php`
- `tests/Feature/TenantResolverDiagnosticTest.php`
- `docs/PRODELYA-M13-M1-FULL-SUITE-BLOCKER-BATCH-1-RAPORU-20260722.md`

Note: `PermissionRelationDiagnosticTest.php` and `TenantResolverDiagnosticTest.php` are present as untracked files in this worktree state. They were not staged.

## Syntax Checks

- `php -l app\Http\Controllers\Admin\ProductionController.php`: PASS
- `php -l tests\Feature\PermissionRelationDiagnosticTest.php`: PASS
- `php -l tests\Feature\TenantResolverDiagnosticTest.php`: PASS
- `php -l tests\Feature\QuotePrintDefaultPriceSuggestionTest.php`: PASS
- `php -l tests\Feature\NotificationSecurityHardeningTest.php`: PASS
- `php -l tests\Feature\NotificationV1EndToEndSmokeTest.php`: PASS
- `php -l tests\Feature\CompanySubcontractorPrintRoleUxTest.php`: PASS
- `php -l tests\Feature\FullOperationalFlowSmokeTest.php`: PASS

## Targeted Tests

- `php artisan test --filter=PermissionRelationDiagnosticTest --stop-on-failure`: PASS, 1 test, 2 assertions
- `php artisan test --filter=TenantResolverDiagnosticTest --stop-on-failure`: PASS, 1 test, 3 assertions
- `php artisan test --filter=QuotePrintDefaultPriceSuggestionTest --stop-on-failure`: PASS, 3 tests, 24 assertions
- `php artisan test --filter=CompanySubcontractorPrintRoleUxTest --stop-on-failure`: PASS, 5 tests, 30 assertions
- `php artisan test --filter=NotificationSecurityHardeningTest --stop-on-failure`: PASS, 2 tests, 54 assertions
- `php artisan test --filter=NotificationV1EndToEndSmokeTest --stop-on-failure`: PASS, 1 test, 97 assertions
- `php artisan test --filter=FullOperationalFlowSmokeTest --stop-on-failure`: PASS, 2 tests, 431 assertions

## Broad Tests

- `php artisan test --filter=Permission --stop-on-failure`: PASS, 46 tests, 231 assertions
- `php artisan test --filter=TenantResolver --stop-on-failure`: PASS, 1 test, 3 assertions
- `php artisan test --filter=QuotePrint --stop-on-failure`: PASS, 11 tests, 106 assertions
- `php artisan test --filter=ProductionNotificationIntegrationTest --stop-on-failure`: PASS, 1 test, 121 assertions
- `php artisan test --filter=Notification --stop-on-failure`: PASS, 53 tests, 1289 assertions
- `php artisan test --filter=ProductionLegacyRouteCleanupTest --stop-on-failure`: PASS, 6 tests, 43 assertions
- `php artisan test --filter=Production --stop-on-failure`: PASS, 165 tests, 2273 assertions
- `php artisan test --filter=Order --stop-on-failure`: PASS, 263 tests, 2371 assertions
- `php artisan test --filter=AdminSmokeTest --stop-on-failure`: PASS, 59 tests, 214 assertions
- `php artisan view:clear`: PASS
- `php artisan view:cache`: PASS

## Full Suite Recount

Command:

`php artisan test --log-junit .tmp\m13-m1-full-suite.xml`

Result:

- Before Batch 1 baseline: 2213 tests, 17 failures, 3 errors.
- After Batch 1: 2213 tests, 13 failures, 0 errors.
- Reduction: 4 failures cleared and 3 errors cleared.

All Batch 1 blocker tests are absent from the remaining full-suite failure/error list.

## Remaining Full-Suite Failures Outside Batch 1 Scope

- FAIL `Tests.Feature.CompanyContactAddressActionsTest::test_company_detail_shows_active_contact_and_address_actions_with_clean_copy` at `tests/Feature/CompanyContactAddressActionsTest.php:43`
- FAIL `Tests.Feature.CompanyContactAddressActionsTest::test_contact_and_address_empty_states_are_user_friendly` at `tests/Feature/CompanyContactAddressActionsTest.php:108`
- FAIL `Tests.Feature.PermanentCategoryBackboneLockTest::test_tenant_category_selection_shows_permanent_categories_and_hides_archived` at `tests/Feature/PermanentCategoryBackboneLockTest.php:83`
- FAIL `Tests.Feature.PermanentCategoryBackboneLockTest::test_product_data_hub_overview_shows_category_reset_metrics` at `tests/Feature/PermanentCategoryBackboneLockTest.php:131`
- FAIL `Tests.Feature.ProductDataHubFinalUiCleanupTest::test_working_local_csv_import_remains_visible` at `tests/Feature/ProductDataHubFinalUiCleanupTest.php:63`
- FAIL `Tests.Feature.ProductHubFinalUiTerminologyRadiusTest::test_daily_product_hub_screens_use_abone_firma_language` at `tests/Feature/ProductHubFinalUiTerminologyRadiusTest.php:26`
- FAIL `Tests.Feature.ProductHubSupplierFlowStepperTest::test_supplier_flows_screen_renders_eight_step_stepper_and_stateful_actions` at `tests/Feature/ProductHubSupplierFlowStepperTest.php:46`
- FAIL `Tests.Feature.ProductHubTemplateCleanupTest::test_supplier_flow_cards_use_single_primary_cta_and_clear_catalog_message` at `tests/Feature/ProductHubTemplateCleanupTest.php:61`
- FAIL `Tests.Feature.ProductSelectionWarningDisplayTest::test_quote_workspace_source_contains_readonly_product_code_and_sku_dropdown_meta` at `tests/Feature/ProductSelectionWarningDisplayTest.php:1237`
- FAIL `Tests.Feature.PublicLinkScreensUxPolishTest::test_public_link_screens_use_customer_facing_copy_and_keep_security_boundaries` at `tests/Feature/PublicLinkScreensUxPolishTest.php:78`
- FAIL `Tests.Feature.QuickCustomerWhatsappPhoneTest::test_quote_quick_customer_modal_shows_whatsapp_phone_label_and_prefix` at `tests/Feature/QuickCustomerWhatsappPhoneTest.php:30`
- FAIL `Tests.Feature.TenantAdvancedCatalogTest::test_tenant_can_edit_and_deactivate_local_product` at `tests/Feature/TenantAdvancedCatalogTest.php:373`
- FAIL `Tests.Feature.TenantAdvancedCatalogTest::test_supplier_purchase_uses_discount_calculation_and_manual_purchase_price` at `tests/Feature/TenantAdvancedCatalogTest.php:764`

These remaining failures are the UI/catalog/Product Hub group explicitly excluded from Batch 1.

## Worktree And Staging

- Staged area check: empty.
- No commit was created.
- Unrelated dirty worktree changes were preserved.

## Final Status

READY -- FULL-SUITE BLOCKER BATCH 1 CLEARED -- REMAINING UI/CATALOG REGRESSIONS RECORDED
