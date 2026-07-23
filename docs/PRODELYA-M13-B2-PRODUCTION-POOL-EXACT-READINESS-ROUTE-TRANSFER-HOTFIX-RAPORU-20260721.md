# PRODELYA M13-B2 — Production Pool Exact Readiness / Route Transfer Hotfix Raporu

Tarih: 2026-07-21
Kapsam: `/admin/productions` üretim havuzu exact grafik/tedarik readiness ayrıştırması ve dar hotfix.

## Karar

Sonuç: READY — PRODUCTION POOL EXACT READINESS AFTER ROUTE TRANSFER — MANUAL SMOKE REQUIRED

Ayrıştırma sonucu iki gerçek durum bulundu:

- SP-2026-0013 içindeki browser örneklerinde karşılaştırılan işler aynı siparişe ait olsa da farklı exact print row olabilir. 1a, 2a ve 3a bağımsızdır. Bu özel ayrım için karar: NOT A BUG — DIFFERENT EXACT PRINT ROW.
- Aynı exact print row olan bazı kayıtlarda persisted `production_snapshot` stale kalmıştı. Live resolver hazır derken persisted snapshot `Grafik Bekliyor` / `Tedarik Bekliyor` gösterebiliyordu. Production pool label render path'i artık live exact resolver sonucundan türetilen `pool_readiness` alanını kullanıyor.

## Read-only Runtime Audit Kanıtı

SAKLImavi tenant: `tenant_account_id=2`, `panel_subdomain=saklimavi`.

### SP-2026-0013 Exact Row Ayrımı

| Sequence | Product / SKU | order_item_print_id | production_id | Graphic status | Attachment | Procurement | Resolver | Karar |
|---|---|---:|---:|---|---:|---|---|---|
| 1a | YN-3037-SIYAH İncek Siyah Tarihsiz Defter | 113 | 26 | production_ready | 25 | kismi_geldi | graphic_ready=true, procurement_ready=false | 1a hazır; tedarik bloklu |
| 2a | ET-0544-85-G Tükenmez Kalem Gri | 114 | 27 | waiting_visual | - | tamami_geldi | graphic_ready=false, procurement_ready=true | Grafik Bekliyor doğru |
| 3a | PZ-DF07SY Defter Siyah | 115 | 28 | waiting_visual | - | tedarik_talebi_acildi | graphic_ready=false, procurement_ready=false | Grafik Bekliyor doğru |

Bu nedenle aynı order üzerinden sibling readiness yaymak doğru değildir.

### Same Exact Row Stale Snapshot Örnekleri

| Order | Print | Production | Persisted Snapshot | Live Resolver / Pool Truth |
|---|---:|---:|---|---|
| SP-2026-0016 | 123 | 30 | Grafik Bekliyor / Talep Hazırlanacak / ui_can_start=false | graphic_ready=true, procurement_ready=true, can_start=true |
| SP-2026-0011 | 107 | 23 | Grafik Bekliyor / Tedarik Bekliyor / ui_can_start=false | graphic_ready=true, procurement_ready=true, can_start=true |
| SP-2026-0008 | 73 | 14 | Grafik Bekliyor / Tedarik Bekliyor / ui_can_start=false | graphic_ready=true, procurement_ready=true, can_start=true |

Duplicate production audit: `order_item_print_id` başına duplicate `order_item_print_productions` bulunmadı.

## Root Cause

Production pool zaten runtime snapshot hydrate etmeye başlamıştı; ancak UI label ve bazı pool readiness metrik/filtresi snapshot alanlarına bağlı kalabiliyordu. Stale persisted snapshot DB'de durduğu için havuz response'u güvenli biçimde live exact resolver'a açıkça bağlanmalıydı.

Route transfer tarafında servis audit'i yeni production/print row yaratmadığını gösterdi: `updateAssignment()` mevcut `OrderItemPrintProduction` üzerinde `production_type`, `production_company_id`, `production_unit_name`, `assigned_to` gibi assignment alanlarını güncelliyor; `order_item_print_id`, graphic/procurement/work_form/quantity bağlarını değiştirmiyor.

## Uygulanan Dar Hotfix

Dosyalar:

- `app/Http/Controllers/Admin/ProductionController.php`
- `resources/views/admin/productions/index.blade.php`
- `tests/Feature/ProductionReadinessPerPrintGraphicTest.php`

Değişiklikler:

- Production pool row payload'ına live resolver kaynaklı `pool_readiness` eklendi.
- Pool UI grafik label sırası: exact production -> exact print -> live resolver -> fallback.
- Pool UI tedarik label sırası: exact work form procurement -> live resolver -> fallback.
- Hazır iş metrikleri ve legacy pool `ready/preparation` filtresi `snapshot.ui_can_start` yerine live `readiness.can_start` kullanıyor.
- UI label sözleşmesi sadeleştirildi: `Grafik Hazır`, `Grafik Gerekli Değil`, `Grafik Bekliyor`; `Tedarik Tamamlandı`, `Tedarik Gerekli Değil`, `Tedarik Bekliyor`.
- Sibling print readiness yayılımı yapılmadı.
- Historical bulk rewrite yapılmadı; stale snapshot DB kayıtları topluca güncellenmedi.

## Test Kanıtı

PASS:

- `php -l app\Http\Controllers\Admin\ProductionController.php`
- `php -l resources\views\admin\productions\index.blade.php`
- `php -l tests\Feature\ProductionReadinessPerPrintGraphicTest.php`
- `php artisan view:clear`
- `php artisan view:cache`
- `php artisan test --filter=ProductionPoolReadiness --stop-on-failure` — 1 test, 13 assertions
- `php artisan test --filter=ProductionRouteTransfer --stop-on-failure` — 1 test, 15 assertions
- `php artisan test --filter=ProductionReadinessPerPrintGraphicTest --stop-on-failure` — 6 tests, 61 assertions
- `php artisan test --filter=ProductionPoolRouteMethodGroupingTest --stop-on-failure` — 5 tests, 33 assertions
- `php artisan test --filter=TenantPrintSettingProductionModeIntegrationTest --stop-on-failure` — 4 tests, 42 assertions
- `php artisan test --filter=ProductionUiTest --stop-on-failure` — 12 tests, 144 assertions
- `php artisan test --filter=Procurement --stop-on-failure` — 131 tests, 1836 assertions
- `php artisan test --filter=Order --stop-on-failure` — 263 tests, 2371 assertions
- `php artisan test --filter=AdminSmokeTest --stop-on-failure` — 59 tests, 214 assertions

Scope dışı mevcut drift:

- `php artisan test --filter=Graphic --stop-on-failure` 29. testte düştü.
- Failure: `Tests\Feature\GraphicListThumbFrameTest::test_graphic_index_uses_small_thumb_frames_without_technical_paths`.
- Beklenti: `pd-ui-v1-graphics__thumb--visual`.
- Actual response: kabul edilmiş order-grouped graphics index markup. Bu fazda graphic index/detail/public/mail/preview alanlarına dokunulmadı.

## Manual Smoke Checklist

Beklenen manuel kontrol:

- Aynı exact baskı satırında graphic production_ready + final attachment + procurement ready ise `/admin/productions` satırı `Grafik Hazır` ve `Tedarik Tamamlandı` göstermeli.
- Aynı siparişin farklı 2a/3a satırları graphic waiting ise `Grafik Bekliyor` kalmalı.
- Fasona aktarılmış ama firma atanmamış ready satır `Fason Ata` göstermeli.
- Fason firma atanmış ready satır `Fasona Gönder` göstermeli.
- Sayfa refresh sonrası stale `Grafik Bekliyor` geri gelmemeli.

## Worktree / Staging

- Staging yapılmadı.
- Commit yapılmadı.
- Schema/migration yok.
- M13-C operator flow başlatılmadı.
- Global CSS yok.
- Graphic detail/public/mail/preview alanlarına dokunulmadı.

Not: `ProductionController.php` ve `resources/views/admin/productions/index.blade.php` dosyalarında önceki M13-B fazlarından geniş dirty diff zaten vardı. Bu turdaki değişiklikler bu mixed dosyalardaki dar production pool readiness hunklarıdır.
