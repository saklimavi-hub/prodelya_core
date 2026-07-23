# PRODELYA M13-M2 Full-Suite Blocker Batch 2 Report

Date: 2026-07-22
Branch: feature/master-restructure-phase-2-order-flow

## Scope

Applied only Full-Suite Blocker Batch 2 cleanup for:

- Company contact/address action copy and empty states.
- Quote readonly product code and exact SKU metadata source.
- Public link customer-facing copy/privacy labels.
- Quick customer WhatsApp / phone label and Turkey default prefix presentation.

Product Hub, permanent categories, and TenantAdvancedCatalog were not fixed in this batch. No schema, global CSS, M14, staging, commit, tag, reset, restore, stash, or clean was performed.

## Root Cause Summary

| Area | Expected contract | Actual before fix | Root cause | Decision |
|---|---|---|---|---|
| Company contact/address | `Yeni Yetkili Ekle`, `Yeni Adres Ekle`, `Henüz yetkili eklenmemiş.`, `Henüz adres eklenmemiş.` | Older shorter CTA labels and `Henüz yetkili kişi eklenmemiş.`; raw `placeholder` assertion also caught shared sidebar HTML attribute. | Company panel copy lagged behind canonical wording; test was checking raw HTML for a visible-copy concern. | Updated company panel labels/empty copy and made the placeholder assertion visible-text based. Permissions and tenant guards were unchanged. |
| Quote product/SKU | Readonly `product_code` input and exact SKU metadata string from selected product/variant identity. | Workspace source used `SKU: ${sku}` while the contract/test expects exact code variable flow. | Naming drift in the metadata helper; identity precedence already used product/variant data. | Renamed the local metadata variable to `code` and render `SKU: ${code}` without changing product/variant precedence or catalog payload identity. |
| Public links | `Teklifi İncele`, `Grafik Çalışmasını İncele`, `İş Takibini Gör`, `Dosyayı Görüntüle`; no raw token/path/operator/fason/finance details. | Public quote/graphic/work-form/customer-portal pages still used older or internal-facing wording such as `Teklifinizi İnceleyin`, `Grafik Onayı`, `Müşteri Dosyaları`, and `exact` copy. | Customer-facing view copy had not been normalized after the public privacy polish contract. | Updated visible public/customer-portal labels only. Route tokens, attachment URLs, and authorization guards were not changed. |
| Quick customer WhatsApp | `WhatsApp / Telefon`, e-mail not required, `+90` shown once. | Modal showed `WhatsApp Cep Telefonu` and a flag-prefixed `+90` label. | UI copy/prefix presentation drift; controller already stores phone in existing phone/mobile fields and does not require e-mail. | Updated the modal label and prefix presentation only; no WhatsApp API was introduced and phone persistence stayed unchanged. |

## Changed Files

- `resources/views/admin/companies/show.blade.php`
- `resources/views/admin/promotion-quotes/_form-workspace.blade.php`
- `resources/views/public/quotes/approval/show.blade.php`
- `resources/views/public/graphics/approval/show.blade.php`
- `resources/views/public/work-forms/track.blade.php`
- `resources/views/customer-portal/orders/show.blade.php`
- `tests/Feature/CompanyContactAddressActionsTest.php`
- `tests/Feature/ProductSelectionWarningDisplayTest.php`
- `tests/Feature/PublicLinkScreensUxPolishTest.php`
- `tests/Feature/QuickCustomerWhatsappPhoneTest.php`
- `tests/Feature/PublicQuoteApprovalTemplateLayoutTest.php`
- `tests/Feature/PublicQuoteApprovalTurkishTerminologyTest.php`
- `tests/Feature/PublicQuoteApprovalUiStatusTest.php`
- `tests/Feature/CustomerPortalVisibleFilesTest.php`
- `tests/Feature/PublicGraphicApprovalRouteTest.php`
- `docs/PRODELYA-M13-M2-FULL-SUITE-BLOCKER-BATCH-2-RAPORU-20260722.md`

## Syntax And View Checks

- `php -l resources\views\admin\companies\show.blade.php`: PASS
- `php -l resources\views\admin\promotion-quotes\_form-workspace.blade.php`: PASS
- `php -l resources\views\public\quotes\approval\show.blade.php`: PASS
- `php -l resources\views\public\graphics\approval\show.blade.php`: PASS
- `php -l resources\views\public\work-forms\track.blade.php`: PASS
- `php artisan view:clear`: PASS
- `php artisan view:cache`: PASS

## Targeted Tests

- `php artisan test --filter=CompanyContactAddressActionsTest --stop-on-failure`: PASS, 6 tests, 47 assertions
- `php artisan test --filter=ProductSelectionWarningDisplayTest --stop-on-failure`: PASS, 31 tests, 358 assertions
- `php artisan test --filter=PublicLinkScreensUxPolishTest --stop-on-failure`: PASS, 1 test, 50 assertions
- `php artisan test --filter=QuickCustomerWhatsappPhoneTest --stop-on-failure`: PASS, 2 tests, 8 assertions
- `php artisan test --filter=CustomerPortalVisibleFilesTest --stop-on-failure`: PASS, 3 tests, 49 assertions

Note: one parallel rerun of `CustomerPortalVisibleFilesTest` hit a fake-storage directory creation race while another storage test was running. The same test passed when rerun sequentially.

## Broad Tests

- `php artisan test --filter=Company --stop-on-failure`: PASS, 128 tests, 1231 assertions
- `php artisan test --filter=PromotionQuote --stop-on-failure`: PASS, 197 tests, 1663 assertions
- `php artisan test --filter=ProductSelection --stop-on-failure`: PASS, 35 tests, 383 assertions
- `php artisan test --filter=PublicLink --stop-on-failure`: PASS, 1 test, 50 assertions
- `php artisan test --filter=PublicQuote --stop-on-failure`: PASS, 15 tests, 178 assertions
- `php artisan test --filter=PublicWorkForm --stop-on-failure`: PASS, 10 tests, 90 assertions
- `php artisan test --filter=Graphic --stop-on-failure`: PASS, 114 tests, 1572 assertions
- `php artisan test --filter=Order --stop-on-failure`: PASS, 263 tests, 2371 assertions
- `php artisan test --filter=Notification --stop-on-failure`: PASS, 53 tests, 1289 assertions
- `php artisan test --filter=AdminSmokeTest --stop-on-failure`: PASS, 59 tests, 214 assertions

## Full Suite Recount

Command:

`php artisan test --log-junit .tmp\m13-m2-full-suite.xml`

Result:

- Before Batch 2 baseline: 2213 tests, 13 failures, 0 errors.
- After Batch 2: 2213 tests, 8 failures, 0 errors.
- Reduction: 5 failures cleared, 0 new errors.

All Batch 2 blocker tests are absent from the remaining full-suite failure/error list.

## Remaining Full-Suite Failures Outside Batch 2 Scope

- FAIL `Tests.Feature.PermanentCategoryBackboneLockTest::test_tenant_category_selection_shows_permanent_categories_and_hides_archived` at `tests/Feature/PermanentCategoryBackboneLockTest.php:83`
- FAIL `Tests.Feature.PermanentCategoryBackboneLockTest::test_product_data_hub_overview_shows_category_reset_metrics` at `tests/Feature/PermanentCategoryBackboneLockTest.php:131`
- FAIL `Tests.Feature.ProductDataHubFinalUiCleanupTest::test_working_local_csv_import_remains_visible` at `tests/Feature/ProductDataHubFinalUiCleanupTest.php:63`
- FAIL `Tests.Feature.ProductHubFinalUiTerminologyRadiusTest::test_daily_product_hub_screens_use_abone_firma_language` at `tests/Feature/ProductHubFinalUiTerminologyRadiusTest.php:26`
- FAIL `Tests.Feature.ProductHubSupplierFlowStepperTest::test_supplier_flows_screen_renders_eight_step_stepper_and_stateful_actions` at `tests/Feature/ProductHubSupplierFlowStepperTest.php:46`
- FAIL `Tests.Feature.ProductHubTemplateCleanupTest::test_supplier_flow_cards_use_single_primary_cta_and_clear_catalog_message` at `tests/Feature/ProductHubTemplateCleanupTest.php:61`
- FAIL `Tests.Feature.TenantAdvancedCatalogTest::test_tenant_can_edit_and_deactivate_local_product` at `tests/Feature/TenantAdvancedCatalogTest.php:373`
- FAIL `Tests.Feature.TenantAdvancedCatalogTest::test_supplier_purchase_uses_discount_calculation_and_manual_purchase_price` at `tests/Feature/TenantAdvancedCatalogTest.php:764`

These remaining failures are the Product Hub, permanent category, and TenantAdvancedCatalog group explicitly excluded from Batch 2.

## Worktree And Staging

- Staged area check: empty.
- No commit was created.
- Unrelated dirty worktree changes were preserved.

## Final Status

READY -- FULL-SUITE BLOCKER BATCH 2 CLEARED -- PRODUCT HUB/CATALOG REGRESSIONS REMAIN
