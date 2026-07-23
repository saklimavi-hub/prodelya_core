# PRODELYA M13-N2.3 Product Data Hub UI Shell Dependency Isolation Report - 2026-07-23

Status: BLOCKED - PRODUCT DATA HUB UI SHELL DEPENDENCY ISOLATED, CHECKPOINT NOT APPROVED

## Main Repo Safety

- Main repo: `C:\laragon\www\prodelya_core`
- Main HEAD: `c7f2a80`
- Main staged area: empty at start and final verification
- Main staging/commit/tag/reset/restore/stash/clean: not used
- Dirty worktree: preserved
- Whole dirty worktree copy / robocopy: not used

## Canonical Copy Decision

Decision: `Tedarikçi Listesi` is stale for the Product Data Hub supplier sources shell test. The canonical source-list UI copy is `Tedarikçi Akışları` plus the source-card/list surface copy `Kaynak Kartları`.

Evidence:

- Current dirty main `SuperAdminProductDataHubUiShellTest` expects `Tedarikçi Akışları`, `Kaynak Kartları`, and `pd-kpi-strip` for `admin.super.product-data-hub.sources.index`.
- V2 snapshot failed only because the copied stale shell test expected `Tedarikçi Listesi`.
- V2 rendered Product Data Hub sources HTML already contained `Tedarikçi Akışları`, `Kaynak Kartları`, `Tedarikçi Kaynakları`, and the shared `pd-kpi-strip`; the missing text was not a missing route/controller/view dependency.
- `config/admin_menu.php` canonical menu copy uses `Tedarikçi Akışları`, `Tedarikçi Kaynakları`, `Ürün Havuzu`, `Kategori Eşleme`, `Senkron / Raporlar`, and `Senkron ve Raporlar`.
- `resources/views/super-admin/product-data-hub/sources/index.blade.php` canonical source view copy includes `Tedarikçi Kaynakları ve İlk Aktarım`, `Tedarikçi Akışları`, `Kaynak Listesi ve Filtreler`, and `Kaynak Kartları`.
- Existing Product Data Hub/Product Hub tests already assert `Tedarikçi Akışları` and `Kaynak Kartları`, including `ProductDataHubScheduledSyncCommandTest`, `ProductHubSourceOnboardingProductionUiTest`, `ProductDataHubFinalUiCleanupTest`, and `ProductDataHubSidebarAccordionTest`.
- `docs/PRODELYA-M13-M3-FULL-SUITE-BLOCKER-BATCH-3-RAPORU-20260722.md` documents the canonical supplier-source flow and source-card simplification; it does not preserve a separate `Tedarikçi Listesi` shell contract.

No view/controller copy was hardcoded to hide a missing dependency.

## Route, Controller, View, Shell Trace

- Route: `routes/web.php` Product Data Hub super-admin group exposes `admin.super.product-data-hub.sources.index`.
- Controller: `App\Http\Controllers\SuperAdmin\SuperAdminSupplierSourceController::index` provides the supplier source page data.
- Main Product Data Hub shell route: `App\Http\Controllers\SuperAdmin\SuperAdminProductDataHubController` renders the hub overview surfaces.
- Views:
  - `resources/views/super-admin/product-data-hub/index.blade.php`
  - `resources/views/super-admin/product-data-hub/sources/index.blade.php`
  - `resources/views/super-admin/product-data-hub/sources/_source-detail-card.blade.php`
- Shared shell/menu dependency: `config/admin_menu.php`
- Shared Product Hub presenter dependency: `app/Services/ProductDataHub/ProductHubSourceOnboardingPresenter.php`

The only new N2.3 dependency is a narrow shell test contract patch replacing stale `Tedarikçi Listesi` with canonical `Kaynak Kartları`.

## Product Data Hub Shell Route Matrix

Verified by `SuperAdminProductDataHubUiShellTest`:

- `admin.super.product-data-hub.index`: `Senkron Sonuç Merkezi`, `Bugün aksiyon gereken ürün var mı?`, `pd-kpi-strip`
- `admin.super.product-data-hub.category-mappings.index?mode=advanced`: `Filtre ve Kuyruk`, `Manuel Review Listesi`, `pd-kpi-strip`
- `admin.super.standard-categories.index`: `Standart Kategori Ağacı`, `Kategori Notları`, `pd-kpi-strip`
- `admin.super.product-data-hub.sources.index`: `Tedarikçi Akışları`, `Kaynak Kartları`, `pd-kpi-strip`
- `admin.super.product-data-hub.catalog-output.index`: `Abone Katalog Yayını`, `Aboneye Yansıma Durumu`, `pd-kpi-strip`
- `admin.super.product-data-hub.standard-products.index`: `Teknik Standart Ürünler`, `Standart Ürün Listesi`, `pd-kpi-strip`
- `admin.super.product-data-hub.pipeline.index`: `Akış Kontrol`, `Teknik Akış Özeti`, `pd-kpi-strip`
- `admin.super.product-data-hub.profile-comparison.index`: `Tedarikçi Profil Karşılaştırma`, `Standart Veri Akışı`, `pd-kpi-strip`

## Temp Clone

- Path: `C:\laragon\www\_prodelya_checkpoints\pre_m14_exact_snapshot_v3_20260723`
- Base: clean clone from `c7f2a80`
- Applied source: prior approved A/B/C manifest, N2.2 local-product patches, and only the new N2.3 shell test contract patch
- Runtime dependency: `vendor` junction only
- Manifest-external changed files: none found
- Full suite: not run because targeted gates failed outside Product Data Hub shell scope

## New N2.3 Patch Artifact

Patch directory: `.tmp/pre-m14-selective-patches-v3`

- `product-data-hub-shell-test-contract.patch` - one assertion replacement, `Tedarikçi Listesi` to `Kaynak Kartları`; SHA256 `451004B0284E39786FD1C23F49E4DC4C136A08B1B43053923B77CF596F8133D8`

## Prior N2.2 Patch Artifacts Carried Into V3

Patch directory: `.tmp/pre-m14-selective-patches-v2`

- `local-products-routes.patch`; SHA256 `AB549E291EF4042A963AA8C389830BF7EB0B65BAC206072C047E265893D69AEE`
- `local-products-supplier-stock-controller.patch`; SHA256 `DE627C4B27D17704EA6B61AB30595224999999B6C29571C80BF469BB41E14EDF`
- `tenant-advanced-supplier-stock-contract.patch`; SHA256 `FA36AF108D1816640DDC20CF92EFC37CD02D53CC6F673B53F9F344FB7A236FB1`
- `local-products-search-contract.patch`; SHA256 `92E705D94AB72531FD88CEA9BACE996331D6948FD4B891565E7D61185A70C481`

## Targeted Gate Results

Passed:

```text
php artisan test --filter="SuperAdminProductDataHubUiShellTest::test_product_data_hub_screens_render_shared_shell_blocks" --stop-on-failure
PASS, 1 test, 32 assertions

php artisan view:clear
PASS

php artisan view:cache
PASS

php artisan test --filter=SuperAdminProductDataHubUiShellTest --stop-on-failure
PASS, 1 test, 32 assertions

php artisan test --filter=ProductDataHub --stop-on-failure
PASS, 269 tests, 1804 assertions

php artisan test --filter=ProductHub --stop-on-failure
PASS, 89 tests, 585 assertions

php artisan test --filter=TenantAdvancedCatalogTest --stop-on-failure
PASS, 17 tests, 96 assertions

php artisan test --filter=TenantCatalog --stop-on-failure
PASS, 12 tests, 87 assertions

php artisan test --filter=PermanentCategoryBackboneLockTest --stop-on-failure
PASS, 6 tests, 35 assertions

php artisan test --filter=ProcurementDraftPriceRefreshTest --stop-on-failure
PASS, 4 tests, 33 assertions

php artisan test --filter=TenantCurrency --stop-on-failure
PASS, 24 tests, 119 assertions
```

Failed:

```text
php artisan test --filter=Notification --stop-on-failure
FAIL, Tests\Feature\AdminGraphicCustomerApprovalActionTest::test_send_action_creates_request_cancels_previous_open_request_and_emits_notification
Failed asserting that the session has key [success].
```

Attribution:

- The same exact notification test passes in dirty main with 1 test and 15 assertions.
- Dirty main contains a separate graphic notification UX contract change in `AdminGraphicCustomerApprovalActionTest` and `GraphicCustomerApprovalController`.
- Adding those files would be a new non-Product-Data-Hub shell dependency and was not included in V3.

## Diff Hygiene

`git diff --check` in V3 reports pre-existing copied-manifest EOF whitespace warnings:

```text
app/Http/Controllers/SuperAdmin/SuperAdminSupplierSourceController.php:2132: new blank line at EOF.
resources/views/public/graphics/approval/show.blade.php:657: new blank line at EOF.
tests/Feature/CompanySubcontractorPrintRoleUxTest.php:288: new blank line at EOF.
tests/Feature/ProductSelectionWarningDisplayTest.php:1687: new blank line at EOF.
```

These warnings are not introduced by the N2.3 shell patch, but they remain present in the exact V3 snapshot.

## Final Execution Manifest

Base:

- `c7f2a80`

Whole-file A/C/report files copied path-by-path from dirty main:

- `app/Http/Controllers/Admin/LocalProductController.php`
- `app/Http/Controllers/Admin/ProductionController.php`
- `app/Http/Controllers/SuperAdmin/SuperAdminSupplierSourceController.php`
- `app/Services/Notifications/NotificationVariableBuilder.php`
- `app/Services/ProductDataHub/ProductHubOperationFlowService.php`
- `app/Services/ProductDataHub/ProductHubReviewQueueService.php`
- `app/Services/ProductDataHub/ProductHubSourceOnboardingPresenter.php`
- `app/Services/TenantCatalog/TenantLocalProductWriteService.php`
- `docs/PRODELYA-M13-J-PROCUREMENT-DRAFT-PRICE-REFRESH-HOTFIX-RAPORU-20260722.md`
- `docs/PRODELYA-M13-K-TENANT-CURRENCY-PERMISSION-TRUTH-HOTFIX-RAPORU-20260722.md`
- `docs/PRODELYA-M13-L-PRE-M14-REGRESSION-CLEANUP-FULL-SUITE-RAPORU-20260722.md`
- `docs/PRODELYA-M13-M1-FULL-SUITE-BLOCKER-BATCH-1-RAPORU-20260722.md`
- `docs/PRODELYA-M13-M2-FULL-SUITE-BLOCKER-BATCH-2-RAPORU-20260722.md`
- `docs/PRODELYA-M13-M3-FULL-SUITE-BLOCKER-BATCH-3-RAPORU-20260722.md`
- `docs/PRODELYA-M13-M4-TENANT-ADVANCED-CATALOG-FULL-SUITE-CLOSURE-RAPORU-20260722.md`
- `docs/PRODELYA-M13-N1-PRE-M14-FULL-SUITE-CHECKPOINT-PREP-RAPORU-20260722.md`
- `docs/PRODELYA-M13-N2-1-EXACT-SELECTIVE-SNAPSHOT-VERIFICATION-RAPORU-20260723.md`
- `docs/PRODELYA-M13-N2-2-SUPPLIER-LOCAL-STOCK-DEPENDENCY-ISOLATION-RAPORU-20260723.md`
- `docs/PRODELYA-M13-N2-PRE-M14-C-CLASS-ISOLATION-RAPORU-20260723.md`
- `resources/views/admin/catalog/local-products-index.blade.php`
- `resources/views/admin/companies/show.blade.php`
- `resources/views/public/graphics/approval/show.blade.php`
- `resources/views/public/work-forms/track.blade.php`
- `resources/views/super-admin/product-data-hub/index.blade.php`
- `resources/views/super-admin/product-data-hub/sources/_source-detail-card.blade.php`
- `resources/views/super-admin/product-data-hub/sources/index.blade.php`
- `tests/Feature/CompanyContactAddressActionsTest.php`
- `tests/Feature/CompanySubcontractorPrintRoleUxTest.php`
- `tests/Feature/CustomerPortalVisibleFilesTest.php`
- `tests/Feature/FinanceNotificationIntegrationTest.php`
- `tests/Feature/FullOperationalFlowSmokeTest.php`
- `tests/Feature/NotificationSecurityHardeningTest.php`
- `tests/Feature/NotificationV1EndToEndSmokeTest.php`
- `tests/Feature/PermissionRelationDiagnosticTest.php`
- `tests/Feature/PermanentCategoryBackboneLockTest.php`
- `tests/Feature/ProcurementDraftPriceRefreshTest.php`
- `tests/Feature/ProcurementPurchasePriceSnapshotTest.php`
- `tests/Feature/ProcurementSupplierExactVariantPriceTest.php`
- `tests/Feature/ProcurementSupplierPriceLabelIntegrityTest.php`
- `tests/Feature/ProcurementSupplierPricePresenterBindingTest.php`
- `tests/Feature/ProcurementSupplierPriceSourceAttributionTest.php`
- `tests/Feature/ProductHubDeltaFreshnessActionsUiTest.php`
- `tests/Feature/ProductHubSourceOnboardingProductionUiTest.php`
- `tests/Feature/ProductHubSupplierFlowStepperTest.php`
- `tests/Feature/ProductHubTemplateCleanupTest.php`
- `tests/Feature/ProductSelectionWarningDisplayTest.php`
- `tests/Feature/PublicGraphicApprovalRouteTest.php`
- `tests/Feature/PublicLinkScreensUxPolishTest.php`
- `tests/Feature/PublicQuoteApprovalTemplateLayoutTest.php`
- `tests/Feature/PublicQuoteApprovalTurkishTerminologyTest.php`
- `tests/Feature/PublicQuoteApprovalUiStatusTest.php`
- `tests/Feature/QuickCustomerWhatsappPhoneTest.php`
- `tests/Feature/QuotePrintDefaultPriceSuggestionTest.php`
- `tests/Feature/SettingsNotificationTemplateCssPolishTest.php`
- `tests/Feature/SupplierProcurementRequestPriceReferenceTest.php`
- `tests/Feature/TenantAdvancedCatalogTest.php`
- `tests/Feature/TenantCatalogSupplierVisibilityTest.php`
- `tests/Feature/TenantCurrencySettingsDiagnosticTest.php`
- `tests/Feature/TenantResolverDiagnosticTest.php`
- `tests/Feature/TenantSettingsDomainReadinessTest.php`
- `tests/Feature/TenantSettingsLandingTest.php`

Narrow patches applied:

- `.tmp/pre-m14-selective-patches/admin-menu-currency-permission.patch`
- `.tmp/pre-m14-selective-patches/graphic-module-history-label.patch`
- `.tmp/pre-m14-selective-patches/customer-portal-order-visible-files.patch`
- `.tmp/pre-m14-selective-patches/public-quote-approval-copy-labels.patch`
- `.tmp/pre-m14-selective-patches-v2/local-products-routes.patch`
- `.tmp/pre-m14-selective-patches-v2/local-products-supplier-stock-controller.patch`
- `.tmp/pre-m14-selective-patches-v2/tenant-advanced-supplier-stock-contract.patch`
- `.tmp/pre-m14-selective-patches-v2/local-products-search-contract.patch`
- `.tmp/pre-m14-selective-patches-v3/product-data-hub-shell-test-contract.patch`

## Approval Decision

Checkpoint execution is not approved.

Reason:

- The Product Data Hub UI shell dependency is isolated and its targeted gates pass.
- The exact V3 selective snapshot is not complete because the targeted Notification gate fails before the full suite.
- The required `2213/2213` full-suite proof was not obtained on V3.
