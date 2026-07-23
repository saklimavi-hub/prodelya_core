# PRODELYA M13-N1 Pre-M14 Full-Suite Checkpoint Prep Raporu

Tarih: 2026-07-22

## Durum

READY - PRE-M14 FULL-SUITE CHECKPOINT MANIFEST PREPARED - USER APPROVAL REQUIRED

Bu rapor sadece okuma/audit ve checkpoint hazırlığıdır. Staging, commit, tag, reset, restore, stash veya clean yapılmadı.

## Git Bazı

- Branch: `feature/master-restructure-phase-2-order-flow`
- Checkpoint bazı: `c7f2a80 checkpoint: production v1 closure`
- Staged alan kontrolu: bos
- Dirty worktree: M13-J/K/L/M1/M2/M3/M4 dosyalarinin yaninda cok sayida eski/ilgisiz degisiklik var.
- JUnit dosyalari bu manifestte bilerek yoktur.

## Kaynak Raporlar

Manifest su raporlardaki kapsamla sinirlandirildi:

- `docs/PRODELYA-M13-J-PROCUREMENT-DRAFT-PRICE-REFRESH-HOTFIX-RAPORU-20260722.md`
- `docs/PRODELYA-M13-K-TENANT-CURRENCY-PERMISSION-TRUTH-HOTFIX-RAPORU-20260722.md`
- `docs/PRODELYA-M13-L-PRE-M14-REGRESSION-CLEANUP-FULL-SUITE-RAPORU-20260722.md`
- `docs/PRODELYA-M13-M1-FULL-SUITE-BLOCKER-BATCH-1-RAPORU-20260722.md`
- `docs/PRODELYA-M13-M2-FULL-SUITE-BLOCKER-BATCH-2-RAPORU-20260722.md`
- `docs/PRODELYA-M13-M3-FULL-SUITE-BLOCKER-BATCH-3-RAPORU-20260722.md`
- `docs/PRODELYA-M13-M4-TENANT-ADVANCED-CATALOG-FULL-SUITE-CLOSURE-RAPORU-20260722.md`

## A - Pure Pre-M14 Cleanup Files

Bu dosyalar rapor kapsamindaki M13-J/K/L/M1/M2/M3/M4 degisiklikleri olarak dogrudan stage edilebilir. Kullanici onayi olmadan calistirilmayacak dogrudan staging manifesti:

```powershell
git add -- `
  docs/PRODELYA-M13-J-PROCUREMENT-DRAFT-PRICE-REFRESH-HOTFIX-RAPORU-20260722.md `
  docs/PRODELYA-M13-K-TENANT-CURRENCY-PERMISSION-TRUTH-HOTFIX-RAPORU-20260722.md `
  docs/PRODELYA-M13-L-PRE-M14-REGRESSION-CLEANUP-FULL-SUITE-RAPORU-20260722.md `
  docs/PRODELYA-M13-M1-FULL-SUITE-BLOCKER-BATCH-1-RAPORU-20260722.md `
  docs/PRODELYA-M13-M2-FULL-SUITE-BLOCKER-BATCH-2-RAPORU-20260722.md `
  docs/PRODELYA-M13-M3-FULL-SUITE-BLOCKER-BATCH-3-RAPORU-20260722.md `
  docs/PRODELYA-M13-M4-TENANT-ADVANCED-CATALOG-FULL-SUITE-CLOSURE-RAPORU-20260722.md `
  docs/PRODELYA-M13-N1-PRE-M14-FULL-SUITE-CHECKPOINT-PREP-RAPORU-20260722.md `
  app/Http/Controllers/Admin/ProductionController.php `
  app/Services/Notifications/NotificationVariableBuilder.php `
  app/Services/ProductDataHub/ProductHubOperationFlowService.php `
  app/Services/ProductDataHub/ProductHubReviewQueueService.php `
  app/Services/ProductDataHub/ProductHubSourceOnboardingPresenter.php `
  resources/views/admin/companies/show.blade.php `
  resources/views/public/work-forms/track.blade.php `
  resources/views/super-admin/product-data-hub/index.blade.php `
  resources/views/super-admin/product-data-hub/sources/_source-detail-card.blade.php `
  tests/Feature/CompanyContactAddressActionsTest.php `
  tests/Feature/CompanySubcontractorPrintRoleUxTest.php `
  tests/Feature/CustomerPortalVisibleFilesTest.php `
  tests/Feature/FinanceNotificationIntegrationTest.php `
  tests/Feature/FullOperationalFlowSmokeTest.php `
  tests/Feature/NotificationSecurityHardeningTest.php `
  tests/Feature/NotificationV1EndToEndSmokeTest.php `
  tests/Feature/PermissionRelationDiagnosticTest.php `
  tests/Feature/PermanentCategoryBackboneLockTest.php `
  tests/Feature/ProcurementDraftPriceRefreshTest.php `
  tests/Feature/ProcurementPurchasePriceSnapshotTest.php `
  tests/Feature/ProcurementSupplierExactVariantPriceTest.php `
  tests/Feature/ProcurementSupplierPriceLabelIntegrityTest.php `
  tests/Feature/ProcurementSupplierPricePresenterBindingTest.php `
  tests/Feature/ProcurementSupplierPriceSourceAttributionTest.php `
  tests/Feature/ProductHubDeltaFreshnessActionsUiTest.php `
  tests/Feature/ProductHubSourceOnboardingProductionUiTest.php `
  tests/Feature/ProductHubSupplierFlowStepperTest.php `
  tests/Feature/ProductHubTemplateCleanupTest.php `
  tests/Feature/ProductSelectionWarningDisplayTest.php `
  tests/Feature/PublicGraphicApprovalRouteTest.php `
  tests/Feature/PublicLinkScreensUxPolishTest.php `
  tests/Feature/PublicQuoteApprovalTemplateLayoutTest.php `
  tests/Feature/PublicQuoteApprovalTurkishTerminologyTest.php `
  tests/Feature/PublicQuoteApprovalUiStatusTest.php `
  tests/Feature/QuickCustomerWhatsappPhoneTest.php `
  tests/Feature/QuotePrintDefaultPriceSuggestionTest.php `
  tests/Feature/SettingsNotificationTemplateCssPolishTest.php `
  tests/Feature/SupplierProcurementRequestPriceReferenceTest.php `
  tests/Feature/TenantCurrencySettingsDiagnosticTest.php `
  tests/Feature/TenantResolverDiagnosticTest.php `
  tests/Feature/TenantSettingsDomainReadinessTest.php `
  tests/Feature/TenantSettingsLandingTest.php
```

Notlar:

- `ProductionController.php` sadece M13-M1 `return_to` guard dogrulugunu tasir.
- `NotificationVariableBuilder.php` M13-L finans bildiriminde kullanici metninde `TL`, ic ISO baglaminda `TRY` gercegini korur.
- Product Hub presenter/queue/flow dosyalari M13-M3 kapsamindaki sekiz adimli tedarik akisi, Abone Firma terminolojisi, tek birincil CTA ve otomatik katalog mesajini tasir.
- JUnit XML, gecici debug ciktilari ve test artifactleri yoktur.

## B - Hunk-Separable Mixed Files

Bu dosyalar sadece `git add -p` ile stage edilmelidir. Kabul edilecek hunklar asagida sinirlandirilmistir; tum diger hunklar reddedilmelidir. Gerekirse `s` ile split, yeterli degilse `e` ile manuel hunk edit kullanilmalidir.

```powershell
git add -p -- config/admin_menu.php
```

Kabul:

- Tenant currency menu iteminda kanonik izin gercegini yansitan `permission_any` / settings permission hunku.

Reddet:

- Local products/catalog accordion, BOM/newline, menu siralama veya M13-K disi diger admin menu degisiklikleri.

```powershell
git add -p -- app/Services/GraphicModuleDataBuilder.php
```

Kabul:

- `historyLabel()` icinde `procurement_request_created` icin `Tedarik ihtiyaci olusturuldu` etiketi.

Reddet:

- Graphic index refactor, pagination, URL helper, filter, staged state veya bosluk/format hunklari.

```powershell
git add -p -- app/Services/SupplierProcurementRequestService.php
```

Kabul:

- M13-J draft price refresh sonrasinda canonical procurement/current-account truth'u yenileyen narrow hunklar.
- Supplier/product/variant purchase price, currency, exchange-rate ve rounding kaynagini mevcut procurement/catalog/currency servisleriyle koruyan refresh hunklari.

Reddet:

- Procurement akis kapsam genisletmeleri, UI policy, schema benzeri veya J raporunda olmayan hunklar.

```powershell
git add -p -- resources/views/admin/promotion-quotes/_form-workspace.blade.php
```

Kabul:

- Quick customer modalinda `WhatsApp / Telefon` etiketi.
- Telefon prefixinin kullaniciya `+90` olarak gorunmesi.
- Readonly product code/exact SKU metadata icin `buildCatalogResult(entry)` icinde `const code = entry.product_code || '-'`, `metaLine = buildCompactProductMetaLine(...)` ve sonucu gosteren narrow hunk.

Reddet:

- Quote currency refactor, global JS, fiyat hesaplama, validation, layout veya M13-M2 disi form workspace degisiklikleri.

```powershell
git add -p -- resources/views/public/quotes/approval/show.blade.php
```

Kabul:

- Public quote kullanici metni/privacy kapsamindaki `Teklifi Incele` ve public-facing price label duzeltmeleri.

Reddet:

- Fiyat mantigi, internal attribution, commercial total veya M13-M2 disi public quote redesign hunklari.

```powershell
git add -p -- resources/views/customer-portal/orders/show.blade.php
```

Kabul:

- Customer portal dosya gorunurlugu icin `Dosyayi Goruntule` / public file label hunklari.

Reddet:

- Musteri fiyat gosterimi, toplam tutar, layout redesign veya M13-M2 disi hunklar.

```powershell
git add -p -- tests/Feature/TenantAdvancedCatalogTest.php
```

Kabul:

- M13-M4 local product edit/deactivate stock truth assertions: editable metadata ile movement-managed stock ayrimi.
- Supplier purchase discount/manual price ve purchase-entry creation fixture/assertion hunklari.
- Sales price, tenant/product/variant identity ve rollback koruma assertions.

Reddet:

- M13-M4 raporunda olmayan eski local stock/list route fixture degisiklikleri.

## C - Inseparable Mixed / Needs Isolation Before Checkpoint

Bu dosyalarda rapor kapsamindaki hunklar daha genis, eski veya ayni blokta ic ice degisikliklerle birlikte duruyor. Kullanici onayi sonrasi staging oncesinde ayrica izole patch hazirlanmazsa stage edilmemelidir.

- `resources/views/public/graphics/approval/show.blade.php`
  - M13-M2 public graphic copy/privacy metinleri var.
  - Ancak dosya ayni diffte genis public approval redesign ve eski layout degisiklikleri tasiyor.
- `app/Http/Controllers/Admin/LocalProductController.php`
  - M13-M3 permanent category / archived filtering gercegine temas ediyor.
  - Ancak dosya untracked ve daha genis local product controller yuzeyiyle birlikte geliyor.
- `app/Services/TenantCatalog/TenantLocalProductWriteService.php`
  - M13-M3 permanent category write-path korumalariyla iliskili.
  - Ancak untracked servis olarak butun dosya stage edilirse M13 disi local catalog implementation da checkpoint'e girer.
- `resources/views/admin/catalog/local-products-index.blade.php`
  - M13-M3 working local CSV source visibility ve permanent category selection gorunurlugu var.
  - Ancak untracked buyuk local products UI dosyasi olarak daha genis Product Hub/catalog yuzeyiyle ic ice.
- `app/Http/Controllers/SuperAdmin/SuperAdminSupplierSourceController.php`
  - M13-M3 eight-step supplier flow ve source visibility hunklari var.
  - Ancak Product Hub source onboarding controller degisiklikleri genis ve ayni bloklarda ic ice.
- `resources/views/super-admin/product-data-hub/sources/index.blade.php`
  - M13-M3 supplier flow/source visibility/Abone Firma terminolojisi var.
  - Ancak genis source onboarding UI degisiklikleriyle ayrilmasi riskli.

## D - Unrelated / Do Not Stage For This Checkpoint

Asagidakiler bu checkpoint manifestine dahil edilmemelidir:

- `public/css/prodelya-admin.css`
  - Global CSS; J/K/L/M1/M2/M3/M4 raporlarinda narrow required hunk olarak kanitlanmadi.
- `routes/web.php`
  - Route diffi genis ve rapor-backed narrow hunk gerektiren bir M13-J/M4 degisikligi tespit edilmedi.
- JUnit XML ve test artifactleri:
  - `.tmp/*.xml`
  - `*.junit.xml`
  - `storage/logs/*`
  - gecici debug loglari
- Product Hub, catalog, TenantAdvancedCatalog disinda kalan tum dirty dosyalar.
- M14, schema, migration, global CSS veya production seed data degisiklikleri.

## Full-Suite Pre-Commit Gate

Kullanici onayi sonrasi staging yapildiktan sonra commit oncesi gate:

```powershell
git diff --cached --name-only
git diff --cached --check
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=Settings
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=Finance
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=TenantCurrency
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=ProcurementDraftPriceRefreshTest
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=Procurement
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=Production
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=Graphic
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=Order
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=AdminSmoke
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --log-junit .tmp\pre-m14-full-suite-checkpoint.xml
```

Commit sadece staged diff temiz ve test gate 0 failure / 0 error ise yapilmalidir:

```powershell
git commit -m "checkpoint: pre-m14 full-suite cleanup"
```

Tag yok, ikinci commit yok.

## Onay Gereksinimi

Bu manifest staging veya commit islemi yapmaz. Sonraki adim icin kullanici onayi gerekir. Ozellikle C sinifi dosyalar icin iki secenek vardir:

1. C dosyalarini bu checkpoint disinda tutup sadece A+B manifestini stage etmek.
2. C dosyalari icin once narrow isolation patch hazirlayip sonra staged diffi tekrar incelemek.

Final durum:

READY - PRE-M14 FULL-SUITE CHECKPOINT MANIFEST PREPARED - USER APPROVAL REQUIRED
