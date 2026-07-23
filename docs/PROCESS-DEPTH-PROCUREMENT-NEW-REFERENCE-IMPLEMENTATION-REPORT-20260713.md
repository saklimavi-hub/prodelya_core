# PROCESS-DEPTH-PROCUREMENT-NEW-REFERENCE-IMPLEMENTATION-REPORT-20260713

## 1. Canonical Reference Files
- `docs/ui-previews-new/prodelya_tedarik_talep_listesi_sade_onizleme.html`
- `docs/ui-previews-new/prodelya_tedarik_detay_sade_onizleme.html`
- `docs/ui-previews-new/prodelya_tedarik_surec_penceresi_onizleme.html`
- `docs/ui-previews-new/prodelya_tedarik_talep_formu_onizleme.html`

## 2. Old Preview Superseded
- Eski `docs/ui-previews/prodelya_process_depth_tedarik_matrix_onizleme.html` bu fazda production UI referansı olarak kullanılmadı.
- Yeni procurement UI ailesi yalnız `docs/ui-previews-new` altındaki resmi referanslara hizalandı.

## 3. Route / View Mapping Audit
- Liste yüzeyi: `admin.procurements.index` / `resources/views/admin/procurements/index.blade.php`
- Detay yüzeyi: `admin.procurements.show` / `resources/views/admin/procurements/show.blade.php`
- Gelen ürün / partial receipt yüzeyi: `admin.procurements.supplier-requests.edit` + `mark-partially-received`
- Fiyatsız print yüzeyi: `admin.procurements.supplier-requests.print`
- Orphan procurement create route üretilmedi; yeni talep aksiyonu yalnız gerçek açık procurement ihtiyacından supplier-request create akışına bağlı kaldı.

## 4. List Implementation
- Procurement list ekranı yeni resmi “talep listesi” ailesine taşındı.
- KPI kartları: `Talep Hazırlanacak`, `Tedarikçiye İletildi`, `Gelen Ürün Bekleyen`, `Tamamlanan`
- Sekmeler: `Açık Talepler`, `Sipariş Verilenler`, `Tamamlananlar`
- Tek primary CTA dili `Talebi Aç` olarak standardize edildi.
- Sağ panelde orphan oluşturmayan gerçek supplier request adayları bırakıldı.
- Ham / finansal alanlar gizli kaldı.

## 5. Detail Implementation
- Procurement detail tek-akışlı yeni aileye taşındı.
- Üst sıradaki iş paneli, ürün ve ihtiyaç özeti, üç aşamalı süreç ve sağ sticky kısa özet eklendi.
- Eski tablı “tedarik sekmeleri” yapısı kaldırıldı.
- Tek primary CTA sözleşmesi korundu.
- Purchase price ve supplier cari yüzeyleri yalnız controlled depth + mevcut permission ile açıldı.

## 6. Receipt Modal / Window
- Gerçek backend’e bağlı supplier request edit ekranı yeni “süreç penceresi” ailesine taşındı.
- Partial receipt girişleri yalnız gerçek `mark-partially-received` akışına bağlı kaldı.
- Negatif / overflow girişleri backend validation ile korunuyor.
- Nested form oluşturulmadı.

## 7. Price-Free Print Form
- A4 print görünümü yeni resmi fiyatsız talep formu ailesine hizalandı.
- Tedarikçi, tarih, talep no, hazırlayan, not, sipariş no, ürün kodu/adı, miktar, birim ve imza alanları korundu.
- Alış / satış / KDV / toplam / raw path / token benzeri alanlar formda gösterilmedi.

## 8. Fast / Standard / Controlled
- Procurement detail ve receipt yüzeylerinde tenant process depth kanonik resolver/policy üzerinden okundu.
- `fast`: daha kompakt, history ve kontrol blokları kapalı
- `standard`: kısa history açık
- `controlled`: kontrol özeti, supplier cari ve izinli alış özeti açılıyor

## 9. Permission / Sensitive Data
- Yetkisiz kullanıcıda purchase price kolonları supplier request edit ekranında gizli kaldı.
- Supplier cari paneli yalnız controlled depth ve mevcut cari izniyle açıldı.
- Procurement / print yüzeylerinde `group_code`, `raw_mapping`, `payload`, `file_path`, finansal ham alanlar gösterilmedi.

## 10. Tests
Çalıştırılan hedefli testler:
- `php artisan test --filter=ProcurementNewReferenceFamily --stop-on-failure`
- `php artisan test --filter=ProcurementProcessDepthUi --stop-on-failure`
- `php artisan test --filter=ProcurementReceiptModalContract --stop-on-failure`
- `php artisan test --filter=SupplierRequestPriceFreePrintReference --stop-on-failure`
- `php artisan test --filter=ProcurementUiTest --stop-on-failure`
- `php artisan test --filter=ProcurementCoreTest --stop-on-failure`
- `php artisan test --filter=ProcurementDetailSimplificationTest --stop-on-failure`
- `php artisan test --filter=ProcurementShowSupplierCariTabTest --stop-on-failure`
- `php artisan test --filter=SupplierProcurementRequestUiTest --stop-on-failure`
- `php artisan test --filter=ProcessDepth --stop-on-failure`
- `php artisan test --filter=AdminSmokeTest --stop-on-failure`

## 11. Manual Smoke
- Henüz bekleniyor.
- Bu fazda staging veya commit yapılmadı.

## 12. Git / Staging / Commit
- Bu fazda staging yapılmadı.
- Commit yapılmadı.

## 13. G1 Gate
- Ara karar: `IMPLEMENTED — NEW PROCUREMENT REFERENCE FAMILY APPLIED — MANUAL SMOKE PENDING`

## 14. Completed Procurement Purchase Price Save Hotfix
- Önceki F1H1 yaklaşımı yerine F1H2 kararı uygulandı.
- Tamamlanmış supplier request edit ekranında normal kaydet akışı fiyat ve iç not düzeltmesine ayrıldı.
- `Alış Fiyatlarını Kaydet` yalnız completed request + mevcut procurement manage + purchase price görüntüleme yetkisi olan iç kullanıcıya açıldı.
- Normal completed save akışı `requested_quantity`, `received_quantity`, `remaining_quantity`, request membership ve status alanlarını değiştirmez.
- Purchase total client input olarak alınmadı; server-side canonical olarak yeniden hesaplandı.
- Completed requestte hesap miktarı `received_quantity` üzerinden güncellendi.
- Mevcut supplier cari sync servisi korunarak aynı `source_type/source_id` transaction kaydı duplicate üretmeden güncellendi.
- Fiyat değişikliği ve request note değişikliği merkezi `audit_logs` tablosuna `supplier_procurement_purchase_prices_updated` aksiyonu ile yazıldı.
- Adet düzeltmesi bu hotfix kapsamına alınmadı; ayrı audit-loglu akış TODO olarak bırakıldı.

Ek hedefli testler:
- `php artisan test --filter=CompletedSupplierProcurementPurchasePriceUpdate --stop-on-failure`

Bu hotfix için güncel durum:
- `IMPLEMENTED — COMPLETED PROCUREMENT PURCHASE PRICE SAVE RESTORED — MANUAL SMOKE PENDING`
- Staging yapılmadı.
- Commit yapılmadı.
