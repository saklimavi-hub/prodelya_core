# PRODELYA M12-B1 — Grafik İşleri Görev Listesi + UI Standardı v1 Raporu

Tarih: 2026-07-20
Durum: READY — GRAPHIC TASK LIST UI V1 — MANUAL SMOKE REQUIRED

## Kapsam
Bu fazda yalnız `/admin/graphics` görev listesi ve scoped `.pd-ui-v1-graphics*` CSS ele alındı.

Dokunulan ana alanlar:
- `resources/views/admin/graphics/index.blade.php`
- `public/css/prodelya-admin.css`
- dar kapsamlı liste filtre/doğruluk akışı için `app/Http/Controllers/Admin/GraphicController.php`
- exact per-print liste presenter truth için `app/Services/GraphicModuleDataBuilder.php`
- ilgili feature/regression testleri

Bilerek dokunulmayan kabul edilmiş alanlar:
- `resources/views/admin/graphics/show.blade.php`
- `resources/views/public/graphics/approval/show.blade.php`
- public secure file route
- recipient/mail/notification akışı
- large preview modal
- schema/migration
- global CSS primitive'leri

## Uygulanan Sonuç
Görev listesi artık aggregate work-form yerine exact print satırı bazında çalışır:
- canonical row = `order item + exact print row + order_item_print_graphic`
- satırda müşteri, exact SKU, print key (`1a / 1b / 1c`), baskı türü/seçeneği görünür
- tek primary CTA sözleşmesi korunur
- `approved` otomatik `production_ready` gibi gösterilmez
- `revision_requested` ayrı kuyruk ve ayrı aksiyon olarak korunur
- attachment görünürlüğü kompakt Türkçe etiketlerle gösterilir
- finance / path / token / storage leak yoktur

Yeni UI v1 hiyerarşisi:
- başlık
- kompakt özet
- kuyruk sekmeleri
- filtreler
- görev listesi
- sağ kısa sticky özet

## Test Sonuçları
Geçen kapılar:
- `php artisan view:cache`
- `php artisan test --filter=GraphicsIndex --stop-on-failure`
- `php artisan test --filter=GraphicListNextAction --stop-on-failure`
- `php artisan test --filter=Graphic --stop-on-failure`
- `php artisan test --filter=PublicGraphicApproval --stop-on-failure`
- `php artisan test --filter=Order --stop-on-failure`
- `php artisan test --filter=AdminSmokeTest --stop-on-failure`

Scope dışı drift:
- `php artisan test --filter=Production --stop-on-failure`
- kalan failure: `Tests\Feature\ProductionEndToEndSmokeTest::test_production_end_to_end_smoke_flow_is_safe_and_complete`
- attribution: mevcut `/admin/orders/{id}` order-detail/UI metni ile eski test beklentisi arasında drift; bu fazda production/order detail kodu genişletilmedi

## Test Katmanı Hizalamaları
Bu faz sırasında stale test beklentileri yeni kabul edilmiş liste sözleşmesine hizalandı:
- eski `Düzenle` / aggregate liste varsayımları kaldırıldı
- eski thumb class / preview class beklentileri yeni scoped `.pd-ui-v1-graphics*` yapısına taşındı
- grafik index üzerinde display path görünürlüğü bekleyen stale assertionlar no-leak sözleşmesine hizalandı
- customer approval list wording ve history wording mevcut kabul edilmiş truth’a hizalandı

## Manuel Smoke İçin Beklenenler
Kullanıcıdan özellikle şu ekran görüntüleri / kontroller beklenir:
- `Aksiyon Bekleyenler`
- `Revize İstenenler`
- `Üretime Hazır`

Kontrol listesi:
- müşteri adı doğru
- exact sipariş / ürün / print identity doğru
- tek primary CTA var
- durum doğru kuyrukta görünüyor
- attachment bilgisi doğru
- finance leak yok
- token/path/storage leak yok
- `404/405/500` yok

## Kapanış
Kod ve test tarafında grafik task-list UI v1 fazı hazırdır.

Final karar:
- READY — GRAPHIC TASK LIST UI V1 — MANUAL SMOKE REQUIRED
