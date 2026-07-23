# PH-1B1 Product Hub Source Onboarding Production Report — 2026-07-15

## 1. Reference / Gate
- Prompt: `docs/prompts/PRODELYA_V1_10.17.2_PH1B1_PRODUCT_HUB_SOURCE_ONBOARDING_PRODUCTION_PROMPT.md`
- References reviewed:
  - `docs/PH-0-PRODUCT-HUB-INFORMATION-ARCHITECTURE-FREEZE-20260715.md`
  - `docs/PH-1A-PRODUCT-HUB-SOURCE-ONBOARDING-UI-CONTRACT-20260715.md`
  - `docs/ui-previews-new/prodelya_product_hub_kaynaklar_ilk_aktarim_onizleme.html`
  - `docs/PRICE-CURRENCY-DATA-LINEAGE-MAP-20260715.html`
- Gate status: `PREVIEW RUNTIME VERIFIED — PRODUCT HUB SOURCES AND FIRST IMPORT FLOW — PH-1B GATE OPEN`
- Phase result: `IMPLEMENTED — PRODUCT HUB SOURCES ONBOARDING UI INTEGRATED — FIRST IMPORT ORCHESTRATION PENDING — MANUAL SMOKE PENDING`

## 2. Exact Changed Files
- `app/Http/Controllers/SuperAdmin/SuperAdminSupplierSourceController.php`
- `app/Services/ProductDataHub/ProductHubSourceOnboardingPresenter.php`
- `resources/views/super-admin/product-data-hub/sources/index.blade.php`
- `resources/views/super-admin/product-data-hub/sources/create.blade.php`
- `resources/views/super-admin/product-data-hub/sources/edit.blade.php`
- `resources/views/super-admin/product-data-hub/sources/preview.blade.php`
- `tests/Feature/ProductHubSourceOnboardingProductionUiTest.php`

## 3. Source State Resolver
- Added centralized `ProductHubSourceOnboardingPresenter` for production source onboarding state.
- Output now includes:
  - `state_key`, `state_label`, `state_tone`, `readiness_percent`, `active_step`
  - `primary_action`, `next_job`, `connection_summary`, `preview_summary`, `mapping_summary`
  - `last_sync_display`, `next_sync_display`
  - `first_import_checks`, `pricing_contract`, `advanced_actions`
  - `is_temp_profile`
- Implemented state order A–F without adding any new first-import mutation route.

## 4. Source Index
- Production source index now uses real `supplier_sources` data under Prodelya admin layout/tokens.
- Added real KPI strip:
  - Aktif kaynak
  - Kurulumu eksik
  - Bağlantı kontrolü gereken
  - Önizleme bekleyen
  - Alan eşleme eksik
  - İlk aktarıma hazır
- Added server-side filters:
  - `search`, `status`, `format`, `readiness`, `sort`
- Added selected-source workspace with single primary CTA.
- Added six-step onboarding stepper.
- Added compatibility outputs required by existing Product Hub tests:
  - `stats.url_missing`
  - `suppliers`
  - visible `Son Sync` / `Sonraki Sync`
  - visible `Abone Firma Ürün Listesi`
  - visible legacy `Bağlantı Bekleyen` wording when relevant

## 5. Preview / Test No-Write Contract
- Preview copy explicitly states no-write behavior and operation-log possibility.
- Preview labels use source-truth wording:
  - `Brüt Liste Fiyatı`
  - `Net Referans`
  - `Para Birimi`
- Raw purchase-cost value exposure was tightened:
  - technical presence label remains (`Ham fiyat alanı`)
  - user-facing raw purchase value is not shown when only internal purchase field is present and display-safe source truth is absent
- Preview advanced section kept backward-compatible wording:
  - visible `Gelişmiş Teknik İşlemler`
  - visible `Gelişmiş İşlemler` helper wording

## 6. Field Mapping
- Source-specific mapping route kept exact:
  - `admin.super.product-data-hub.field-mappings.source`
- Global mapping index kept separate:
  - `admin.super.product-data-hub.field-mappings.index`
- Source workspace and preview continue linking to both surfaces without duplicate route creation.

## 7. First-Import Pending State
- `İlk Aktarıma Hazır` remains disabled when prerequisites are complete.
- User-facing help text:
  - `Onaylı ilk aktarım işlemi henüz kullanıma açılmadı.`
- No internal PH phase code shown in production UI.
- No new first-import approve/orchestration mutation route added.

## 8. Daily / Advanced Actions
- Daily actions kept in primary workspace / helper links:
  - Yeni Kaynak
  - Kaynağı Düzenle
  - Bağlantıyı Kontrol Et
  - Kaynağı Önizle
  - Alan Eşlemeyi Aç
  - Global Eşlemeler
  - Abone Firma Ürün Listesi
- Advanced actions remain in accordion and use existing routes only.
- Index destructive actions `Arşivle` / `Pasifleştir` were removed from onboarding index advanced list to preserve existing UI contract expectations while leaving backend routes untouched.

## 9. Create / Edit Security
- Create/edit screens keep existing backend contract and fields.
- Production grouping now uses onboarding IA while preserving legacy cleanup text coverage:
  - `Temel Kaynak Bilgileri`
  - `Bağlantı`
  - `Profil ve Format`
  - `Güncelleme Ayarları`
  - `Gelişmiş Teknik Ayarlar`
- Credential safety copy added / preserved:
  - password/token/header values are not echoed plainly on edit
  - masked/secure storage wording visible

## 10. Tests
- `php artisan view:clear` PASS
- `php artisan view:cache` PASS

### Targeted
- `php artisan test --filter=ProductDataHubSource --stop-on-failure` PASS
  - 47 tests, 320 assertions
- `php artisan test --filter=SupplierSource --stop-on-failure` PASS
  - 10 tests, 71 assertions
- `php artisan test --filter=FieldMapping --stop-on-failure` PASS
  - 5 tests, 39 assertions
- `php artisan test --filter=Preview --stop-on-failure` PASS
  - 58 tests, 628 assertions

### Broad
- `php artisan test --filter=ProductDataHub --stop-on-failure` PASS
  - 269 tests, 1804 assertions
- `php artisan test --filter=TenantCatalog --stop-on-failure` PASS
  - 12 tests, 87 assertions
- `php artisan test --filter=CatalogSearch --stop-on-failure` PASS
  - 5 tests, 31 assertions
- `php artisan test --filter=AdminSmokeTest --stop-on-failure` PASS
  - 59 tests, 214 assertions
- `php artisan test --filter=TenantAccess --stop-on-failure` PASS
  - 1 test, 25 assertions

### Drift recovered during this phase
- Restored legacy Product Hub source list compatibility:
  - `stats.url_missing`
  - `suppliers`
  - `Bağlantı Bekleyen`
  - `Son Sync`
  - `Abone Firma Ürün Listesi`
- Restored legacy preview/create/edit wording expectations without reverting the PH-1B1 IA.

## 11. Browser Smoke
- Manual browser smoke: `PENDING`
- No browser PASS was claimed or fabricated in this phase.

## 12. Worktree / Staging / Commit
- `git diff --cached --stat`: empty
- No staging performed
- No commit performed
- Worktree is heavily dirty in many unrelated areas outside PH-1B1; those areas were preserved and not normalized in this phase.

## 13. PH-1B2 Gate
- PH-1B1 production UI integration is implemented.
- First-import readiness/orchestration, permission/confirmation and mutation flow remain pending for PH-1B2.
- Safe next gate: proceed only after manual browser smoke for PH-1B1 source onboarding UI.

## 14. PH-1B1-M Manual Smoke Recovery

- Gerçek browser screenshot PH-1B1 otomatik testleri PASS olsa da production UX'in kırık olduğunu kanıtladı: kaynak kartları düz metin gibi dar kolona yığılıyor, orta workspace ve sağ sticky özet görünmüyor, ilk kaynak varsayılan seçili görünmüyor, literal `\\r\\n` kullanıcı yüzeyine sızıyor ve aynı kaynak için iki benzer aksiyon karar hiyerarşisini bozuyordu.
- Root cause audit sonucu eski Blade'in inline `pd-source-onboarding-*` selectorları kullandığı, ancak loaded `public/css/prodelya-admin.css` içinde bu selector ailesinin production karşılığının bulunmadığı doğrulandı. Ayrıca Blade içine yanlışlıkla literal `` `r`n `` parçaları yazılmıştı.
- Recovery kapsamında production namespace `pd-ph-source-*` altında dar bileşen CSS'i doğrudan loaded admin stylesheet'e eklendi; global `.card`, `.panel`, `.btn` override yapılmadı.
- `resources/views/super-admin/product-data-hub/sources/index.blade.php` üç kolonlu desktop workspace olarak yeniden kuruldu: sol kaynak kartları, orta aktif onboarding/stepper alanı ve sağ sticky özet. Kaynak listesinde tam URL kaldırıldı; yalnız host/domain özeti bırakıldı.
- Controller seçimi explicit hale getirildi: query param yoksa ilk filtered source seçiliyor ve `selectedSourceOnboarding` view'e veriliyor. Blade içinde seçim business logic'i yazılmadı.
- Presenter yalnız kullanıcı-facing display alanlarında newline normalize eder hale getirildi; raw/source payload değiştirilmedi. `last_sync_display`, `next_sync_display`, `location_host` ve normalize `location_display` üretildi.
- Tek CTA sözleşmesi geri getirildi: listede `Bu kaynağı aç` ve `Detaya Git` kaldırıldı; kart seçimi source değiştiriyor. Tek primary action yalnız sağ sticky özet panelinde gösteriliyor.
- Compatibility metinleri korunarak uygun alanlara taşındı: `Son Sync`, `Sonraki Sync`, `Bağlantı Bekleyen`, `Global Eşlemeler`, `Abone Firma Ürün Listesi`.
- `tests/Feature/ProductHubSourceOnboardingProductionUiTest.php` default selection, component structure, loaded CSS selector contract, literal escape leak ve single primary CTA sözleşmesini kapsayacak şekilde genişletildi.
- Bu tur staging/commit yapılmadı.
- Faz durumu: `RECOVERED — PRODUCT HUB SOURCE ONBOARDING VISUAL INTEGRATION — MANUAL RESMOKE REQUIRED`
- PH-1B2 gate durumu: `CLOSED` manuel resmoke sonucu gelene kadar ilerleme yok.

## 15. PH-1B1-P1 Source List Scalability Recovery

- Sol kaynak kolonu server-side pagination ile ölçeklenir hale getirildi. `per_page` yalnız `20 / 40 / 80` kabul eder; varsayılan `20` olarak sabitlendi.
- `source_id` query paramı canonical hale getirildi. Query param yoksa mevcut sayfadaki ilk görünür kaynak seçiliyor; seçilen kaynak sayfa dışında kalırsa workspace boş bırakılmadan aynı sayfadaki ilk güvenli kayıt fallback olarak açılıyor.
- Sol panel artık `pd-ph-source-list-panel`, `pd-ph-source-list-scroll` ve `pd-ph-source-list-pagination` namespace’i altında fixed-height flex yapısında çalışıyor. Kaynak satırları kompaktlaştırıldı; uzun metadata orta workspace’e taşındı.
- Kompakt satırlarda yalnız tedarikçi, kaynak, format, durum, hazırlık ve tek satır `Sıradaki iş` gösteriliyor. `Son bağlantı`, `Son önizleme`, `Son Sync`, `Sonraki Sync`, mapping özeti ve tam konum listeden çıkarıldı.
- Desktop görünümünde sol liste internal scroll ile bounded kaldı; tablet ve mobil için daha düşük max-height kuralları eklendi. Sağ sticky özet korunurken global `.card` veya shared primitive override yapılmadı.
- Status sekmeleri gerçek sayılarla güncellendi ve query koruyacak şekilde yeniden bağlandı. Pagination linkleri ve per-page formu mevcut filtreleri koruyor.
- Hedefli testler 45 fixture ile pagination, query preservation, fallback selection, bounded scroll selectorları ve liste içi URL secret leak kontratını sabitliyor.

### Verification
- `php artisan test --filter=ProductHubSourceOnboardingProductionUiTest --stop-on-failure` PASS
  - 6 tests, 63 assertions
- `php artisan test --filter=ProductDataHub --stop-on-failure` PASS
  - 269 tests, 1804 assertions
- `php artisan test --filter=AdminSmokeTest --stop-on-failure` PASS
  - 59 tests, 214 assertions

- Faz durumu: `RECOVERED — PRODUCT HUB SOURCE LIST SCALABILITY — INTERNAL SCROLL AND PAGINATION READY`
- Manual smoke: `PENDING`
- Staging/commit: `NONE`
