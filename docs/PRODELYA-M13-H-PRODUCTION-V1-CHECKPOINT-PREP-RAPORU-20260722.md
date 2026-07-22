# PRODELYA M13-H - Production V1 Checkpoint Prep Raporu

Tarih: 2026-07-22
Mod: Read-only audit / manifest hazırlığı
Sonuç: READY - PRODUCTION V1 CHECKPOINT MANIFEST PREPARED - USER APPROVAL REQUIRED

## 1. Git Durumu

- Branch: `feature/master-restructure-phase-2-order-flow`
- HEAD: `921e483` (`docs: finalize graphic process depth and public security report`)
- Staged alan: boş (`git diff --cached --name-only` çıktı vermedi)
- Worktree: yoğun dirty; Production V1 dışında catalog, stock, procurement, quote, graphic, order, product-data-hub, promotion ve çok sayıda rapor/test değişikliği mevcut.
- Git uyarısı: bazı dosyalarda CRLF -> LF uyarısı var. Bu yüzden geniş `git add` komutları riskli; staging yalnızca aşağıdaki manifest ile yapılmalı.

## 2. Production V1 Checkpoint Kapsamı

Bu checkpoint yalnızca aşağıdaki M13 üretim kapanış ailesini hedefler:

- M13-C3: production legacy route/action surface cleanup
- M13-C4: production pool/detail UI status truth
- M13-F: work form production/subcontract alignment
- M13-F1: visible subcontract assign/transfer CTA hotfix
- M13-G: final closure + public token visibility hotfix
- M13-H: bu checkpoint manifest raporu

Kapsam dışı olduğu bilinen regresyon: `tests/Feature/ProcurementDraftPriceRefreshTest.php` içindeki `value="164.49"` beklenti farkı Procurement alanına aittir ve Production V1 checkpoint kapsamına alınmamalıdır.

## 3. A - Pure Production / Direct Stage Adayları

Aşağıdaki dosyalar Production V1 kapsamı için doğrudan stage edilebilir. Bu liste `git add -- <path...>` ile kullanılacak tek direct-staging manifestidir.

```text
app/Http/Controllers/Admin/ProductionController.php
app/Http/Controllers/Admin/WorkFormAttachmentController.php
app/Models/OrderItemPrintProduction.php
app/Services/ProductionDataBuilder.php
app/Services/PublicWorkFormTrackingDataBuilder.php
app/Services/WorkFormAttachmentService.php
app/Services/WorkFormPdfService.php
app/Services/WorkFormRenderDataBuilder.php
resources/views/admin/productions/index.blade.php
resources/views/admin/productions/show.blade.php
resources/views/admin/productions/operator.blade.php
resources/views/admin/productions/subcontract-assignment.blade.php
resources/views/admin/productions/subcontract-tracking.blade.php
resources/views/admin/productions/partials/_production_actions.blade.php
resources/views/admin/productions/partials/_production_external.blade.php
resources/views/admin/productions/partials/_production_photos.blade.php
resources/views/admin/productions/partials/_production_summary.blade.php
resources/views/admin/work-forms/pdf.blade.php
resources/views/public/work-forms/track.blade.php
tests/Feature/InternalOperatorFlowTest.php
tests/Feature/ProductionCanonicalRouteResolverTest.php
tests/Feature/ProductionLegacyRouteCleanupTest.php
tests/Feature/ProductionPoolDetailUiStatusTruthTest.php
tests/Feature/ProductionSubcontractReceiptTrackingFlowTest.php
tests/Feature/ProductionSubcontractorAssignmentFlowTest.php
tests/Feature/ProductionPartialCompletionWorkflowTest.php
tests/Feature/ProductionUiTest.php
tests/Feature/PublicWorkFormTrackingTest.php
tests/Feature/WorkFormPdfTest.php
tests/Feature/WorkFormShowTest.php
docs/PRODELYA-M13-C3-PRODUCTION-LEGACY-ROUTE-ACTION-SURFACE-CLEANUP-RAPORU-20260721.md
docs/PRODELYA-M13-C4-PRODUCTION-POOL-DETAIL-UI-STATUS-TRUTH-RAPORU-20260721.md
docs/PRODELYA-M13-F-WORK-FORM-PRODUCTION-SUBCONTRACT-ALIGNMENT-RAPORU-20260722.md
docs/PRODELYA-M13-F1-VISIBLE-SUBCONTRACT-ASSIGNMENT-TRANSFER-CTA-RAPORU-20260722.md
docs/PRODELYA-M13-G-PRODUCTION-MODULE-V1-FINAL-CLOSURE-RAPORU-20260722.md
docs/PRODELYA-M13-H-PRODUCTION-V1-CHECKPOINT-PREP-RAPORU-20260722.md
```

## 4. B - Hunk-Separable Mixed Dosyalar

Bu dosyalar Production V1 ile ilgili hunk içeriyor, fakat aynı dosyada kapsam dışı çalışma da var. Direct stage yapılmamalı; yalnızca interactive hunk staging kullanılmalı.

```text
routes/web.php
public/css/prodelya-admin.css
resources/views/admin/work-forms/show.blade.php
tests/Feature/CompanySubcontractorPrintRoleUxTest.php
app/Support/WorkFormActivityLabelResolver.php
app/Services/ProductionReadinessResolver.php
```

Interactive seçim talimatı:

- `routes/web.php`: yalnızca production canonical/pool/operator/subcontract route blokları ve public/admin work-form attachment/tracking route uyum hunks kabul edilmeli. Catalog, stock purchase, graphic, procurement, product-data-hub ve local-products route hunks reddedilmeli.
- `public/css/prodelya-admin.css`: yalnızca production/work-form scoped selector hunks kabul edilmeli (`pd-ui-v1-production`, `pd-production-*`, `pd-ui-v1-internal-operator`, `pd-subcontract-*`, `pd-production-detail*`, work-form production/subcontract görünümleri). Catalog, stock, quote, graphic, procurement, order, promotion ve generic admin redesign hunks reddedilmeli.
- `resources/views/admin/work-forms/show.blade.php`: production/subcontract print blockları, attachment/gallery görünümü ve M13-G token privacy hotfix hunk kabul edilmeli. Promotion intermediate element veya scope dışı work-form UI hunks reddedilmeli.
- `tests/Feature/CompanySubcontractorPrintRoleUxTest.php`: yalnızca M13-F1 "Fasona Ata / Fasona Devret" CTA görünürlük ve role UX assertion hunks kabul edilmeli. Genel company role-removal/permission hunks reddedilmeli.
- `app/Support/WorkFormActivityLabelResolver.php`: yalnızca production/work-form action label hunks kabul edilmeli. Graphic/procurement/order label hunks reddedilmeli.
- `app/Services/ProductionReadinessResolver.php`: yalnızca M13-C4 production status/readiness truth hunk kabul edilmeli. Print-setup veya process-depth kapsamına taşan hunk varsa reddedilmeli.

## 5. C - Inseparable Mixed / Checkpoint Dışı Tutulacaklar

Bu dosyalarda Production V1 ile ilişkili görünen izler olsa da aynı hunk veya aynı davranış yüzeyi procurement/stock/order gibi scope dışı değişikliklerle ayrışıyor. Bu checkpoint için stage edilmemeli; ayrı karar veya ayrı commit gerekir.

```text
app/Models/OrderItemProcurement.php
app/Services/ProductionWorkflowService.php
app/Services/WorkFormCreationService.php
app/Services/WorkFormDataBuilder.php
```

Notlar:

- `app/Models/OrderItemProcurement.php` içinde user-facing procurement label değişiklikleri, supplier request helper değişiklikleri ve procurement status semantics aynı akışta görünüyor. Production/work-form render dosyalarında yeni `userFacingStatusLabel` bağımlılığı tespit edilmedi; bu nedenle Production V1 checkpoint için dışarıda bırakılabilir.
- `app/Services/ProductionWorkflowService.php` quantity/status workflow semantiğine dokunuyor. M13-G promptu quantity semantics alanına dokunulmamasını özellikle şart koştuğu için checkpoint dışı bırakılmalı.
- `app/Services/WorkFormCreationService.php` ve `app/Services/WorkFormDataBuilder.php` Work Form alanında olsa da M13-F/F1/G doğrudan doğrulanan Production V1 manifestinde zorunlu görünmüyor; ayrı hunk ayrıştırması yapılmadan stage edilmemeli.

## 6. D - Unrelated Dirty Worktree

Aşağıdaki aileler Production V1 checkpoint dışında korunmalı ve stage edilmemeli:

- Catalog/local-products/stock purchase: `app/Http/Controllers/Admin/LocalProduct*`, `StockPurchaseController`, `app/Services/TenantCatalog/*`, `app/Services/Stock/*`, local stock migrations, catalog views, stock-purchases views, local-products tests ve `LIVE-B1-M10*` raporları.
- Procurement/supplier purchase price: `ProcurementController`, `SupplierProcurementRequest*`, procurement services/views/tests, supplier price/currency tests, `F1P3*`, `PROCUREMENT*`, `LIVE-B1-M2/M3/M7/M8/M9*` raporları.
- Quote/promotion/currency: `PromotionQuote*`, quote approval/services/views/tests, currency reports, promotion intermediate element files/tests/reports.
- Graphic module: `Graphic*`, public graphic approval files/tests/reports, M12 graphic reports.
- Order/customer portal/auth/admin menu/process depth: order controllers/services/views/tests, customer portal views/tests, login/admin menu/process-depth reports and tests.
- Any untracked `docs/prompts/`, `docs/ui-previews*`, `resources/views/vendor/`, unrelated docs, and unrelated untracked tests not named in A or B.

## 7. Direct-Staging Manifest

Kullanıcı onayından sonra direct stage için:

```powershell
git add -- app/Http/Controllers/Admin/ProductionController.php app/Http/Controllers/Admin/WorkFormAttachmentController.php app/Models/OrderItemPrintProduction.php app/Services/ProductionDataBuilder.php app/Services/PublicWorkFormTrackingDataBuilder.php app/Services/WorkFormAttachmentService.php app/Services/WorkFormPdfService.php app/Services/WorkFormRenderDataBuilder.php resources/views/admin/productions/index.blade.php resources/views/admin/productions/show.blade.php resources/views/admin/productions/operator.blade.php resources/views/admin/productions/subcontract-assignment.blade.php resources/views/admin/productions/subcontract-tracking.blade.php resources/views/admin/productions/partials/_production_actions.blade.php resources/views/admin/productions/partials/_production_external.blade.php resources/views/admin/productions/partials/_production_photos.blade.php resources/views/admin/productions/partials/_production_summary.blade.php resources/views/admin/work-forms/pdf.blade.php resources/views/public/work-forms/track.blade.php tests/Feature/InternalOperatorFlowTest.php tests/Feature/ProductionCanonicalRouteResolverTest.php tests/Feature/ProductionLegacyRouteCleanupTest.php tests/Feature/ProductionPoolDetailUiStatusTruthTest.php tests/Feature/ProductionSubcontractReceiptTrackingFlowTest.php tests/Feature/ProductionSubcontractorAssignmentFlowTest.php tests/Feature/ProductionPartialCompletionWorkflowTest.php tests/Feature/ProductionUiTest.php tests/Feature/PublicWorkFormTrackingTest.php tests/Feature/WorkFormPdfTest.php tests/Feature/WorkFormShowTest.php docs/PRODELYA-M13-C3-PRODUCTION-LEGACY-ROUTE-ACTION-SURFACE-CLEANUP-RAPORU-20260721.md docs/PRODELYA-M13-C4-PRODUCTION-POOL-DETAIL-UI-STATUS-TRUTH-RAPORU-20260721.md docs/PRODELYA-M13-F-WORK-FORM-PRODUCTION-SUBCONTRACT-ALIGNMENT-RAPORU-20260722.md docs/PRODELYA-M13-F1-VISIBLE-SUBCONTRACT-ASSIGNMENT-TRANSFER-CTA-RAPORU-20260722.md docs/PRODELYA-M13-G-PRODUCTION-MODULE-V1-FINAL-CLOSURE-RAPORU-20260722.md docs/PRODELYA-M13-H-PRODUCTION-V1-CHECKPOINT-PREP-RAPORU-20260722.md
```

## 8. Interactive-Hunk Manifest

Kullanıcı onayından sonra yalnızca bu dosyalarda `git add -p` çalıştırılmalı:

```powershell
git add -p -- routes/web.php
git add -p -- public/css/prodelya-admin.css
git add -p -- resources/views/admin/work-forms/show.blade.php
git add -p -- tests/Feature/CompanySubcontractorPrintRoleUxTest.php
git add -p -- app/Support/WorkFormActivityLabelResolver.php
git add -p -- app/Services/ProductionReadinessResolver.php
```

Her promptta yukarıdaki B sınıfı kabul/red kuralları uygulanmalı. Gerekirse `s` ile split edilmeli; split edilemeyen ve scope dışı satır taşıyan hunk reddedilmeli.

## 9. Excluded Files

Bu checkpoint için özellikle stage edilmemesi gereken dosyalar:

```text
app/Models/OrderItemProcurement.php
app/Services/ProductionWorkflowService.php
app/Services/WorkFormCreationService.php
app/Services/WorkFormDataBuilder.php
tests/Feature/ProcurementDraftPriceRefreshTest.php
```

A ve B manifestlerinde adı geçmeyen diğer tüm dirty/untracked dosyalar da excluded kabul edilmelidir.

## 10. Test Kanıtı

M13-G final closure raporuna göre aşağıdaki doğrulamalar geçti:

- `php artisan view:clear`
- `php artisan view:cache`
- `tests/Feature/InternalOperatorFlowTest.php`: 10 test / 108 assertion
- `tests/Feature/ProductionSubcontractorAssignmentFlowTest.php`: 9 / 78
- `tests/Feature/ProductionSubcontractReceiptTrackingFlowTest.php`: 6 / 71
- `tests/Feature/ProductionLegacyRouteCleanupTest.php`: 6 / 43
- `tests/Feature/ProductionCanonicalRouteResolverTest.php`: 1 / 6
- `tests/Feature/ProductionPoolDetailUiStatusTruthTest.php`: 10 / 67
- `tests/Feature/ProductionUiTest.php`: 165 / 2273
- `tests/Feature/WorkFormShowTest.php`: 5 / 60
- `tests/Feature/WorkFormPdfTest.php`: 4 / 48
- `tests/Feature/WorkFormAttachmentTest.php`: 5 / 51
- `tests/Feature/PublicWorkFormTrackingTest.php`: 10 / 90
- `tests/Feature/Graphic*`: 114 / 1572
- `tests/Feature/Order*`: 263 / 2371
- `tests/Feature/AdminSmokeTest.php`: 59 / 214

Bu M13-H adımında test veya staging çalıştırılmadı; yalnızca read-only audit ve rapor üretildi.

## 11. Önerilen Commit Mesajı / Label

Önerilen commit mesajı:

```text
checkpoint: production v1 closure
```

Önerilen label:

```text
PRODUCTION_V1_CHECKPOINT_M13_C3_C4_F_F1_G_H_20260722
```

## 12. User Approval Sonrası Komut Sırası

Bu komutlar yalnızca kullanıcı açık onay verdikten sonra çalıştırılmalıdır:

```powershell
git add -- app/Http/Controllers/Admin/ProductionController.php app/Http/Controllers/Admin/WorkFormAttachmentController.php app/Models/OrderItemPrintProduction.php app/Services/ProductionDataBuilder.php app/Services/PublicWorkFormTrackingDataBuilder.php app/Services/WorkFormAttachmentService.php app/Services/WorkFormPdfService.php app/Services/WorkFormRenderDataBuilder.php resources/views/admin/productions/index.blade.php resources/views/admin/productions/show.blade.php resources/views/admin/productions/operator.blade.php resources/views/admin/productions/subcontract-assignment.blade.php resources/views/admin/productions/subcontract-tracking.blade.php resources/views/admin/productions/partials/_production_actions.blade.php resources/views/admin/productions/partials/_production_external.blade.php resources/views/admin/productions/partials/_production_photos.blade.php resources/views/admin/productions/partials/_production_summary.blade.php resources/views/admin/work-forms/pdf.blade.php resources/views/public/work-forms/track.blade.php tests/Feature/InternalOperatorFlowTest.php tests/Feature/ProductionCanonicalRouteResolverTest.php tests/Feature/ProductionLegacyRouteCleanupTest.php tests/Feature/ProductionPoolDetailUiStatusTruthTest.php tests/Feature/ProductionSubcontractReceiptTrackingFlowTest.php tests/Feature/ProductionSubcontractorAssignmentFlowTest.php tests/Feature/ProductionPartialCompletionWorkflowTest.php tests/Feature/ProductionUiTest.php tests/Feature/PublicWorkFormTrackingTest.php tests/Feature/WorkFormPdfTest.php tests/Feature/WorkFormShowTest.php docs/PRODELYA-M13-C3-PRODUCTION-LEGACY-ROUTE-ACTION-SURFACE-CLEANUP-RAPORU-20260721.md docs/PRODELYA-M13-C4-PRODUCTION-POOL-DETAIL-UI-STATUS-TRUTH-RAPORU-20260721.md docs/PRODELYA-M13-F-WORK-FORM-PRODUCTION-SUBCONTRACT-ALIGNMENT-RAPORU-20260722.md docs/PRODELYA-M13-F1-VISIBLE-SUBCONTRACT-ASSIGNMENT-TRANSFER-CTA-RAPORU-20260722.md docs/PRODELYA-M13-G-PRODUCTION-MODULE-V1-FINAL-CLOSURE-RAPORU-20260722.md docs/PRODELYA-M13-H-PRODUCTION-V1-CHECKPOINT-PREP-RAPORU-20260722.md
git add -p -- routes/web.php
git add -p -- public/css/prodelya-admin.css
git add -p -- resources/views/admin/work-forms/show.blade.php
git add -p -- tests/Feature/CompanySubcontractorPrintRoleUxTest.php
git add -p -- app/Support/WorkFormActivityLabelResolver.php
git add -p -- app/Services/ProductionReadinessResolver.php
git diff --cached --name-only
git diff --cached --stat
git commit -m "checkpoint: production v1 closure"
```

Kesin yasaklar: `git add .`, `git add -A`, `git reset`, `git restore`, `git stash`, `git clean`, tag veya commit/stage işlemi kullanıcı onayı olmadan çalıştırılmamalıdır.

Final durum: READY - PRODUCTION V1 CHECKPOINT MANIFEST PREPARED - USER APPROVAL REQUIRED
