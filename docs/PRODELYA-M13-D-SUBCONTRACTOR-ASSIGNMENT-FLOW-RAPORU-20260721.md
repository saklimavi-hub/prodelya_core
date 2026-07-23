# PRODELYA M13-D — Dış Baskı / Fason Atama Akışı Raporu

Tarih: 2026-07-21
Durum: IMPLEMENTED / AUTOMATED GATES MOSTLY PASS / MANUAL SMOKE PENDING

## Kapsam

Bu faz yalnız dış baskı / fason exact production assignment akışını ekledi. Fason mobile/public, QR/link, fiyat/maliyet/cari/payment ekranları, schema ve global CSS değiştirilmedi.

## Uygulanan Sözleşme

- Canonical iş birimi exact `order_item_print_productions.id` ve `order_item_print_id` olarak korundu.
- Yeni route: `admin.productions.subcontract-assignment` (`GET /admin/productions/{production}/subcontract-assignment`).
- Route, `/{production}` show route'undan önce tanımlandı; static/exact route yutulma riski yok.
- Yalnız `production_type=external|outsourced` kayıtlar atama ekranını açabilir.
- Internal kayıtlar önce mevcut rota-transfer akışını kullanmalı; atama ekranı 404 döner.
- Completed/cancelled kayıtların ataması kapalıdır.
- Fason firma seçimi readiness beklemeden yapılabilir.
- `Fasona Gönder` readiness + atanmış fason firma gerektirir.
- Firma atama/değiştirme mevcut exact production row'u günceller; yeni production/print row oluşturmaz.
- Partial kayıtlar completed/remaining miktarlarını korur.
- Reassignment gerekçe ister; sent job reassignment mevcut üretim yönetimi izni (`edit_orders|manage_stock`) gerektirir.
- Assignment hiçbir current-account transaction oluşturmaz.

## Dokunulan Dosyalar

- `routes/web.php`
- `app/Http/Controllers/Admin/ProductionController.php`
- `app/Services/ProductionWorkflowService.php`
- `app/Support/WorkFormActivityLabelResolver.php`
- `resources/views/admin/productions/subcontract-assignment.blade.php`
- `public/css/prodelya-admin.css` scoped `.pd-ui-v1-subcontract-assignment*`
- `tests/Feature/ProductionSubcontractorAssignmentFlowTest.php`

## Korunan Alanlar

- Fason mobile/public/QR/link: NOT STARTED
- Schema/migration: NOT TOUCHED
- Global CSS primitive/root/button/card/modal/sidebar: NOT TOUCHED
- Graphic detail/public/mail/preview: NOT TOUCHED
- Procurement/current-account algorithms: NOT TOUCHED
- Staging/commit/tag: NOT DONE

## Test Kanıtları

PASS:
- `php -l app/Http/Controllers/Admin/ProductionController.php`
- `php -l app/Services/ProductionWorkflowService.php`
- `php -l tests/Feature/ProductionSubcontractorAssignmentFlowTest.php`
- `php artisan test --filter=ProductionSubcontractorAssignmentFlowTest` → 4 tests / 37 assertions PASS
- `php artisan test --filter=Production` → 143 tests / 2026 assertions PASS
- `php artisan test --filter=CompanySubcontractorPrintRoleUxTest` → 5 tests / 30 assertions PASS
- `php artisan test --filter=AdminSmokeTest` → 59 tests / 214 assertions PASS
- `php artisan test --filter=Order` → 263 tests / 2371 assertions PASS
- `php artisan test --filter=CurrentAccount` → 77 tests / 719 assertions PASS
- `php artisan view:clear` PASS
- `php artisan view:cache` PASS
- `php artisan route:list --name=admin.productions --path=admin/productions` yeni route'u gösterdi.

NON-BLOCKING / OUT OF SCOPE FAILURE:
- `php artisan test --filter=WorkForm` → 36 tests, 34 passed, 2 failed.
- Failure alanı WorkForm PDF/HTML eski metin beklentisi: test “Tedarik Bekliyor” arıyor, mevcut output “Talep Hazırlanacak” gösteriyor.
- Bu M13-D hunklarından kaynaklı değil; Work Form/PDF bu fazda dokunulmaması gereken kabul edilmiş alan olduğu için düzeltilmedi.

## Manuel Smoke Durumu

PENDING. Browser'da kontrol edilmesi gerekenler:
- Outsourced row CTA `Fason Ata` ile atama sayfasına gider.
- Internal exact production atama sayfasını açamaz.
- Atama ekranında yalnız tenant active `production_partner|print_fason` firmalar görünür.
- Maliyet, fiyat, ödeme, cari alanı görünmez.
- Firma atanır; production row identity ve exact print binding korunur.
- Readiness yokken `Fasona Gönder` görünür blok/uyarı verir.
- Readiness tamamlanınca `Fasona Gönder` mevcut row'u `Fasona Gönderildi` durumuna taşır.
- Partial işlerde yalnız kalan miktar fasona gidecek miktar olarak gösterilir.
- Reassignment gerekçe ister; terminal kayıtlar kapalıdır.

## Karar

M13-D backend + scoped UI implementation tamamlandı. Manuel browser smoke açık PASS olmadan staging/commit yapılmamalıdır.
## M13-E1 Addendum — 2026-07-21

M13-E1 kapsamında `Fasona Gönder` sonrası assignment ekranı tekrar gönderme aksiyonu göstermeyecek şekilde bağlandı. Gönderilmiş/kısmi/gelen fason işler artık dedicated admin tracking route’una `Fason Takibi Aç` / `Fason Takip` CTA’sı ile yönlenir.

Yeni route: `admin.productions.subcontract-tracking`.

Bu addendum M13-D atama kararını değiştirmez; fason firma seçimi ve gönderim ayrımı korunur. Manual smoke durumu M13-E1 raporunda `READY — SUBCONTRACT RECEIPT TRACKING FLOW — MANUAL SMOKE REQUIRED` olarak bırakıldı.
## M13-D1.1 Compact Fason Atama UI Addendum — 2026-07-21

Durum: READY — COMPACT SUBCONTRACT ASSIGNMENT ACTION SURFACE — MANUAL SMOKE REQUIRED

Bu addendum yalnız Dış Baskı / Fason Atama sayfasının karar yüzeyini kompakt hale getirdi. Firma eligibility query, assignment/send/reassignment workflow servisleri, readiness resolver, quantity semantics, current-account, schema, public/mobile takip ve global CSS değiştirilmedi.

Uygulanan compact yüzey:

- Sağ `Kısa Özet` paneli kaldırıldı.
- Eski hero/focus/exact-job/readiness/right-summary tekrarları tek compact exact iş başlığı ve tek inline metrics strip altında toplandı.
- Metrics strip: `Grafik`, `Tedarik`, `Fasona Gidecek`, `Durum`; kısmi işler için `Önceden Tamamlanan` da görünür.
- Firma seçimi büyük kartlardan compact radio row listesine indirildi.
- Atanmamış durumda tek primary CTA: `Fasona Ata`.
- Atanmış/hazır durumda tek primary CTA: `Fasona Gönder`.
- Atanmış/hazır olmayan durumda `Fasona Gönder` disabled kalır ve kısa readiness nedeni gösterilir.
- Gönderilmiş durumda normal firma listesi ve `Fasona Gönder` gizlenir; tek primary CTA `Fason Takibi Aç` olur.
- Tamamlanan outsourced kayıtlar için read-only compact view açılır; tek CTA `Kaydı Aç`.
- Firma değiştirme default kapalı `Firmayı Değiştir` details paneline taşındı.
- İş detayları default kapalı `İş Detaylarını Göster` paneline taşındı.
- Geçmiş default son 3 hareketle sınırlandı; fazlası `Tüm Geçmişi Göster` içinde kalır.
- CSS yalnız `.pd-ui-v1-subcontract-assignment*` scope altında eklendi.

Dar display guard notu:

- Assignment route daha önce completed outsourced kayıtları 404 yapıyordu.
- Prompt completed compact state istediği için yalnız read-only display erişimi açıldı; `cancelled` hâlâ 404.
- Assignment/update/transfer workflow guard'ları değiştirilmedi; completed/cancelled üzerinde mutation hâlâ engellenir.

Dokunulan dar dosyalar:

- `resources/views/admin/productions/subcontract-assignment.blade.php`
- `public/css/prodelya-admin.css`
- `app/Http/Controllers/Admin/ProductionController.php` — yalnız completed read-only display guard
- `tests/Feature/ProductionSubcontractorAssignmentFlowTest.php`
- `docs/PRODELYA-M13-D-SUBCONTRACTOR-ASSIGNMENT-FLOW-RAPORU-20260721.md`

Test sonuçları:

- `php -l app/Http/Controllers/Admin/ProductionController.php` → PASS
- `php -l tests/Feature/ProductionSubcontractorAssignmentFlowTest.php` → PASS
- `php artisan view:clear` → PASS
- `php artisan view:cache` → PASS
- `php artisan test --filter=ProductionSubcontractorAssignmentFlowTest --stop-on-failure` → 7 tests / 65 assertions PASS
- `php artisan test --filter=SubcontractAssignment --stop-on-failure` → NO MATCHING TESTS; compact assertions `ProductionSubcontractorAssignmentFlowTest` içinde çalıştırıldı.
- `php artisan test --filter=ProductionSubcontractReceiptTrackingFlowTest --stop-on-failure` → 6 tests / 71 assertions PASS
- `php artisan test --filter=Production --stop-on-failure` → 152 tests / 2145 assertions PASS
- `php artisan test --filter=AdminSmokeTest --stop-on-failure` → 59 tests / 214 assertions PASS

Manual smoke beklenenleri:

- Atanmamış: compact header, readiness/miktar strip, compact radio rows ve tek `Fasona Ata`.
- Atanmış/hazır: atanan firma, tek `Fasona Gönder`, `Firmayı Değiştir` secondary kapalı panel.
- Gönderilmiş: `Fasona Gönder` ve normal firma listesi yok; tek `Fason Takibi Aç`.
- Tamamlanan: compact read-only ve tek `Kaydı Aç`.
- Sağ özet paneli yok.
- 404/405/500 yok.

Kapanış: READY — COMPACT SUBCONTRACT ASSIGNMENT ACTION SURFACE — MANUAL SMOKE REQUIRED

## M13-C3 Addendum — 2026-07-21

Fason atama canonical operasyon yüzeyi `admin.productions.subcontract-assignment` olarak route resolver'a bağlandı. Internal → outsourced transfer sonrası bu ekrana dönülür; detail içindeki duplicate assignment write yüzeyi kullanılmaz. Manual smoke durumu: PENDING.
