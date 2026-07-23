# PRODELYA M13-E1 — Admin Fason Takip / Gelen Miktar Flow Raporu — 2026-07-21

## Durum

READY — SUBCONTRACT RECEIPT TRACKING FLOW — MANUAL SMOKE REQUIRED

Bu faz yalnız admin tarafındaki fason takip / gelen miktar akışını ekledi. Public/mobile token takibi, schema/migration, global CSS, fiyat/maliyet/cari ekranı ve yeni fason motoru başlatılmadı.

## Uygulanan Dar Kapsam

- `Fasona Gönder` sonrası production pool CTA artık sent/kısmi/gelen dış üretim işleri için `Fason Takip` / `Gelen İşi Kontrol Et` bağlantısını dedicated tracking route’una verir.
- `GET /admin/productions/{production}/subcontract-tracking` route’u eklendi.
- Tracking route yalnız exact external/outsourced, fason firması atanmış ve gönderilmiş/gelen/kısmi/QC/sorunlu/tamamlanmış production kayıtlarını açar.
- Tracking ekranında tek ana aksiyon `Gelen Bilgisi Gir`; açıldığında `Tamamı Geldi`, `Kısmi Geldi`, `Eksik/Sorun Bildir` seçenekleri mevcut `ProductionWorkflowService` status endpoint’ine gider.
- `Tamamı Geldi` akışı mevcut `markReturnedFromSubcontractor` + `markCompleted` servislerini sırayla kullanır.
- `Kısmi Geldi` mevcut `markPartiallyCompleted` servisini kullanır.
- `Eksik/Sorun Bildir` mevcut `markIssue` servisini kullanır.
- Production fotoğrafı upload’u mevcut Work Form attachment store’u kullanır ve tracking sayfasına dönebilir.
- Aynı `production_photo` attachment’ı tracking ekranında ve Work Form production-photo bölümünde aynı store üzerinden görünür.
- Assignment ekranında gönderilmiş iş için `Fasona Gönder` tekrar gösterilmez; primary CTA `Fason Takibi Aç` olur.

## Baseline / Miktar Ayrımı

Activity log tablosunda metadata/payload kolonu bulunmadığı kanıtlandı. Migration açmadan send-baseline bilgisi mevcut `production_snapshot.subcontract_tracking.send_baseline` path’inde tutuldu.

Captured alanlar:

- `production_snapshot.subcontract_tracking.send_baseline.captured_at`
- `production_snapshot.subcontract_tracking.send_baseline.production_id`
- `production_snapshot.subcontract_tracking.send_baseline.order_item_print_id`
- `production_snapshot.subcontract_tracking.send_baseline.planned_quantity_at_send`
- `production_snapshot.subcontract_tracking.send_baseline.completed_quantity_before_send`
- `production_snapshot.subcontract_tracking.send_baseline.remaining_quantity_at_send`
- `production_snapshot.subcontract_tracking.send_baseline.source`

Tracking presenter bu baseline varsa:

- önceden iç üretimde tamamlanan miktarı,
- fasona gerçekten gönderilen miktarı,
- fasondan gelen miktarı,
- fasonda kalan miktarı

ayrı gösterir.

Baseline yoksa gelen miktar tahmini yapılmaz; kullanıcıya `Fason gönderim başlangıç miktarı bu geçmiş kayıt için ayrıştırılamadı` uyarısı gösterilir.

## Dokunulan Dosyalar

- `routes/web.php`
- `app/Http/Controllers/Admin/ProductionController.php`
- `app/Http/Controllers/Admin/WorkFormAttachmentController.php`
- `app/Services/ProductionDataBuilder.php`
- `resources/views/admin/productions/subcontract-assignment.blade.php`
- `resources/views/admin/productions/subcontract-tracking.blade.php`
- `public/css/prodelya-admin.css` scoped `.pd-ui-v1-subcontract-tracking*`
- `tests/Feature/ProductionSubcontractReceiptTrackingFlowTest.php`

## Korunan Sınırlar

- Public/mobile fason token tracking yapılmadı; M13-E2’ye bırakıldı.
- Schema/migration eklenmedi.
- Global CSS primitive değişmedi.
- Maliyet, fiyat, kar, cari hareket bilgisi tracking UI’da gösterilmedi.
- Yeni current-account davranışı eklenmedi.
- Yeni production/print row oluşturulmadı; exact `OrderItemPrintProduction` satırı güncelleniyor.
- Graphic public/mail/large-preview/detail kodlarına dokunulmadı.
- Staging/commit yapılmadı.

## Test Sonuçları

PASS:

- `php -l app/Http/Controllers/Admin/ProductionController.php`
- `php -l app/Services/ProductionDataBuilder.php`
- `php -l app/Http/Controllers/Admin/WorkFormAttachmentController.php`
- `php -l tests/Feature/ProductionSubcontractReceiptTrackingFlowTest.php`
- `php artisan route:list --name=productions.subcontract` → `subcontract-assignment` ve `subcontract-tracking` route’ları göründü.
- `php artisan view:cache`
- `php artisan test --filter=ProductionSubcontractReceiptTrackingFlowTest` → 3 tests / 46 assertions PASS
- `php artisan test --filter=ProductionSubcontractorAssignmentFlowTest` → 4 tests / 37 assertions PASS
- `php artisan test --filter=ProductionPoolRouteMethodGroupingTest` → 5 tests / 33 assertions PASS
- `php artisan test --filter=WorkFormAttachment` → 5 tests / 51 assertions PASS
- `php artisan test --filter=Production` → 146 tests / 2092 assertions PASS
- `php artisan test --filter=CurrentAccount` → 77 tests / 719 assertions PASS
- `php artisan test --filter=AdminSmokeTest` → 59 tests / 214 assertions PASS
- `php artisan test --filter=Order` ikinci koşu → 263 tests / 2371 assertions PASS

Not:

İlk `Order` broad koşusunda `OrderDetailApprovedStickyPanelTest::test_controlled_depth_uses_turkish_activity_labels_in_recent_activities_card` tek defa düştü. Aynı exact test izole çalıştırıldığında PASS verdi; ardından `Order` broad tekrar çalıştırıldığında PASS oldu. Bu nedenle E1 deterministik blocker olarak kabul edilmedi.

## Manual Smoke Beklenenler

- Fasona gönderilmiş exact outsourced üretim satırında assignment ekranında `Fasona Gönder` yok, `Fason Takibi Aç` var.
- `/admin/productions/{id}/subcontract-tracking` açılır.
- Sayfada tek ana CTA `Gelen Bilgisi Gir` görünür.
- `Tamamı Geldi`, `Kısmi Geldi`, `Eksik/Sorun Bildir` workflow servisleriyle çalışır.
- Kısmi transfer örneğinde önceden iç üretimde tamamlanan miktar ile fasondan gelen miktar karışmaz.
- Fotoğraf upload sonrası tracking’e döner ve aynı dosya Work Form üretim fotoğrafı bölümünde görünür.
- UI’da fiyat/maliyet/cari bilgi sızıntısı yoktur.
- 404/405/500 yoktur.

## Kapanış

READY — SUBCONTRACT RECEIPT TRACKING FLOW — MANUAL SMOKE REQUIRED
## M13-E1.1 Compact Fason Takip UI Addendum — 2026-07-21

Durum: READY — COMPACT SUBCONTRACT TRACKING ACTION SURFACE — MANUAL SMOKE REQUIRED

Bu addendum yalnız admin Fason Takip sayfasının presentation/UX yoğunluğunu azalttı. Workflow, quantity semantics, baseline üretimi, photo upload controller, Work Form attachment store, current-account, schema, public/mobile takip ve production pool davranışı değiştirilmedi.

Uygulanan compact yüzey:

- Sağ `Kısa Özet` paneli kaldırıldı.
- Header tek compact iş kimliği satırına indirildi: sipariş, exact print sequence, baskı tipi, ürün, müşteri, fason firma, gönderim ve miktar.
- Büyük exact iş kartı ve ayrı miktar kartı yerine tek inline metrics strip kullanıldı.
- İlk viewport tek ana aksiyon olarak `Gelen Bilgisi Gir` gösterir.
- `Tamamı Geldi`, `Kısmi Geldi`, `Eksik / Sorun Bildir` karar paneli details içinde compact kaldı.
- Baseline ayrıştırılamayan tarihsel kayıt için birden fazla `Ayrıştırılamadı` kutusu kaldırıldı; tek compact note gösterilir.
- İş detayları default kapalı `İş Detaylarını Göster` paneline taşındı.
- Fotoğraf upload default kapalı `Teslim Fotoğrafı Ekle` paneline taşındı; tamamlanan kayıtta input paneli kapalıdır.
- Geçmiş default son 3 hareketle sınırlandı; fazlası `Tüm Geçmişi Göster` içinde kalır.
- Tamamlanan görünüm compact/read-only hale getirildi.
- CSS yalnız `.pd-ui-v1-subcontract-tracking*` scope içinde eklendi.

Dokunulan dar dosyalar:

- `resources/views/admin/productions/subcontract-tracking.blade.php`
- `public/css/prodelya-admin.css`
- `tests/Feature/ProductionSubcontractReceiptTrackingFlowTest.php`
- `docs/PRODELYA-M13-E1-SUBCONTRACT-RECEIPT-TRACKING-FLOW-RAPORU-20260721.md`

Test sonuçları:

- `php -l tests/Feature/ProductionSubcontractReceiptTrackingFlowTest.php` → PASS
- `php artisan view:clear` → PASS
- `php artisan view:cache` → PASS
- `php artisan test --filter=ProductionSubcontractReceiptTrackingFlowTest --stop-on-failure` → 6 tests / 71 assertions PASS
- `php artisan test --filter=SubcontractTracking --stop-on-failure` → NO MATCHING TESTS; compact assertions aynı feature test dosyasında çalıştırıldı.
- `php artisan test --filter=ProductionSubcontractorAssignmentFlowTest --stop-on-failure` → 4 tests / 37 assertions PASS
- `php artisan test --filter=Production --stop-on-failure` → 149 tests / 2117 assertions PASS
- `php artisan test --filter=WorkFormAttachment --stop-on-failure` → 5 tests / 51 assertions PASS
- `php artisan test --filter=AdminSmokeTest --stop-on-failure` → 59 tests / 214 assertions PASS

Manual smoke beklenenleri:

- İlk viewport: compact header, inline miktar özeti ve tek `Gelen Bilgisi Gir` CTA.
- Kısmi örnek: `Gönderilen`, `Gelen`, `Kalan`, `Durum` inline görünür.
- Baseline bilinmeyen tarihsel kayıt: yalnız tek note, ayrı ayrı `Ayrıştırılamadı` kutuları yok.
- Tamamlanan kayıt: compact success, read-only, işlem ve fotoğraf input panelleri kapalı.
- 404/405/500 yok.

Kapanış: READY — COMPACT SUBCONTRACT TRACKING ACTION SURFACE — MANUAL SMOKE REQUIRED

## M13-C3 Addendum — 2026-07-21

Fason takip canonical operasyon yüzeyi `admin.productions.subcontract-tracking` olarak route resolver'a bağlandı. Sent/returned/partial/QC/problem durumları eski detail action tabına değil takip ekranına yönlenir. Manual smoke durumu: PENDING.

## M13-F Link - 2026-07-22
- Fason receipt quantities are projected into Work Form exact rows without changing receipt workflow or quantity semantics.
- Existing send_baseline is used when available; legacy missing baseline shows only a compact note.
- See docs/PRODELYA-M13-F-WORK-FORM-PRODUCTION-SUBCONTRACT-ALIGNMENT-RAPORU-20260722.md.
