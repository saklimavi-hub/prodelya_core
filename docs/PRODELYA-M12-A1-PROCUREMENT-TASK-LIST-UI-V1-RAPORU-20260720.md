# PRODELYA M12 A1 — Procurement Task List UI V1 Raporu
Tarih: 2026-07-20
Durum: IMPLEMENTED — MANUAL SMOKE REQUIRED

## Kapsam
- Faz: `PRODELYA_V1_10.20.1_M12_A1_PROCUREMENT_TASK_LIST_UI_V1_PROMPT.md`
- Amaç: `/admin/procurements` ekranında Tedarik / Malzeme İşleri task-list UI v1 pilotunu uygulamak.
- Sınır: Global CSS, schema, staging, commit yok.
- Korunan sözleşmeler:
  - `Stok Girişi / Satın Alma` ayrı kaldı.
  - Normal procurement akışında stok ve supplier debit başlangıçta oluşmuyor.
  - Exact ürün/varyant kimliği korunuyor.
  - İstenen / Gelen / Kalan görünürlüğü eklendi.
  - Satır başına tek primary CTA korunuyor.

## Değişen Dosyalar
- `resources/views/admin/procurements/index.blade.php`
- `public/css/prodelya-admin.css`
- `tests/Feature/ProcurementIndexUiV1ScopedTest.php`
- stale test copy hizalamaları:
  - `tests/Feature/OperationsFastActionUxTest.php`
  - `tests/Feature/ProcurementEndToEndSmokeTest.php`
  - `tests/Feature/ProcurementSupplierCariMatchedAfterSyncTest.php`
  - `tests/Feature/ProcurementUsesCanonicalSupplierCariTest.php`
  - `tests/Feature/SupplierCariLinkTypeProcurementLookupTest.php`

## Uygulanan UI V1 Noktaları
- Procurement index tam sayfa wrapper: `pd-ui-v1-procurement`
- Hero + kompakt summary + tab strip + filter card + task list + compact right summary
- Tedarik adayları ve aktif talepler ayrı panellere ayrıldı
- Exact SKU görünürlüğü satırda açık gösterildi
- Requested / Received / Remaining miktarları her satırda açık gösterildi
- Tek primary CTA:
  - aday ihtiyaçta `Talebi Aç`
  - aktif kayıtta durumuna göre `Talebi Aç` veya `Kaydı Aç`
- Sadece scoped `.pd-ui-v1-procurement*` CSS eklendi
- Controller / service / route değişikliği yapılmadı

## Test Sonuçları
### Hedefli ve orta kapılar
- `php artisan test --filter=ProcurementIndexUiV1ScopedTest --stop-on-failure` PASS
- `php artisan test --filter=ProcurementUiTest --stop-on-failure` PASS
- `php artisan test --filter=ProcurementQuickActionsUxTest --stop-on-failure` PASS
- `php artisan test --filter=ProcurementIndex --stop-on-failure` PASS
- `php artisan test --filter=ProcurementListNextAction --stop-on-failure` NO TESTS FOUND

### Broad kapılar
- `php artisan test --filter=Procurement --stop-on-failure` PASS
- `php artisan test --filter=StockPurchase --stop-on-failure` PASS
- `php artisan test --filter=Stock --stop-on-failure` PASS
- `php artisan test --filter=CurrentAccount --stop-on-failure` PASS
- `php artisan test --filter=AdminSmokeTest --stop-on-failure` PASS

### Scope dışı gözlem
- `php artisan test --filter=Order --stop-on-failure` FAIL
- Kalan tek failure procurement index pilotundan doğrudan kaynaklı görünmüyor:
  - `Tests\Feature\ProcessDepth\OrderDetailApprovedStickyPanelTest::test_controlled_depth_uses_turkish_activity_labels_in_recent_activities_card`
- Failure, order detail recent activity card içeriğiyle ilgili; bu fazın yetkili dosya sınırı dışında kaldığı için burada düzeltilmedi.

## Notlar
- Procurement testlerindeki bazı beklentiler eski UI kopyasına (`Aktif Talep Listesi`, eski sidebar note) bakıyordu; approved procurement UI v1 kopyasına semantic hizalama yapıldı.
- Production davranışına dokunulmadı; yalnız stale test metinleri yeni approved ekran diliyle hizalandı.
- `ProcurementListNextAction` filtre adıyla eşleşen test bulunmadı; bu durum ayrıca raporlandı.

## Manuel Smoke İçin Hazır Noktalar
- `/admin/procurements` masaüstü ve responsive görünüm
- Hero, summary, tabs, filter ve task-list hiyerarşisi
- Exact SKU görünürlüğü
- İstenen / Gelen / Kalan miktar görünürlüğü
- Satır başına tek primary CTA
- Right compact summary panel
- Tedarikçi kartları ve `Talep Hazırla` akışı

## Faz Sonucu
- Procurement Task List UI V1 pilotu uygulandı.
- Procurement / StockPurchase / Stock / CurrentAccount / AdminSmoke kapıları geçti.
- Manuel smoke bekleniyor.
- Staging / commit yapılmadı.
