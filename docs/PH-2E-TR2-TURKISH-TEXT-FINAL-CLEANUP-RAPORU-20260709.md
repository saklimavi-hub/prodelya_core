# PH-2E-TR2 Turkish Text Final Cleanup Raporu — 2026-07-09

## 1. Özet

Bu küçük fazda Product Hub canlı ürün bilgisi akışında kalan kullanıcı-facing ASCII Türkçe metinler düzeltildi.

Değişen dosyalar:

- `app/Services/ProductDataHub/ProductHubLiveProductInfoService.php`
- `app/Http/Controllers/SuperAdmin/SuperAdminProductDataHubController.php`
- `resources/views/super-admin/product-data-hub/sources/create.blade.php`
- `resources/views/super-admin/product-data-hub/sources/edit.blade.php`
- `tests/Feature/ProductHubLiveProductInfoEndpointTest.php`
- `tests/Feature/PromotionQuoteLiveProductInfoUiTest.php`

İncelendi fakat bu fazda değişiklik gerekmeyen dosya:

- `resources/views/admin/promotion-quotes/_form-workspace.blade.php`

İş kuralı değişmedi.

- category_pending non-blocking davranışı değiştirilmedi
- endpoint contract değiştirilmedi
- response key'leri değiştirilmedi
- tenant scope / guard / route davranışı değiştirilmedi
- fiyat / snapshot / projection mantığı değiştirilmedi

## 2. Düzeltilen Canlı Ürün Bilgisi Metinleri

- `Urun secimi eksik.` → `Ürün seçimi eksik.`
- `Urun guncel ve teklif icin uygun.` → `Ürün güncel ve teklif için uygun.`
- `Urun secilebilir, ancak guncel durum icin uyari kontrol edilmelidir.` → `Ürün seçilebilir, ancak güncel durum için uyarı kontrol edilmelidir.`
- `Bu urunun guncel fiyati teklif satirindaki fiyattan farkli.` → `Bu ürünün güncel fiyatı teklif satırındaki fiyattan farklı.`
- `Stok bilgisi degismis olabilir.` → `Stok bilgisi değişmiş olabilir.`
- `Bu urun su anda teklif icin uygun degil.` → `Bu ürün şu anda teklif için uygun değil.`
- `Bu urun teklifte kullanima kapali.` → `Ürün teklifte kullanıma kapalı.`
- `Bu urun secilebilir satis satiri olarak hazir degil.` → `Ürün seçilebilir satış satırı olarak hazır değil.`
- `Bu urunun guncel fiyat bilgisi eksik.` → `Bu ürünün güncel fiyat bilgisi eksik.`
- `Stok bilgisi su anda okunamiyor.` → `Stok bilgisi şu anda okunamıyor.`
- `Bu urun icin satista kullanilabilir stok gorunmuyor.` → `Bu ürün için satışta kullanılabilir stok görünmüyor.`
- `Abone Firma bu tedarikciye erisemiyor.` → `Abone Firma bu tedarikçiye erişemiyor.`
- `Gonderilen urun bilgisi dogrulanamadi.` → `Gönderilen ürün bilgisi doğrulanamadı.`
- `Gonderilen karsilastirma bilgisi dogrulanamadi.` → `Gönderilen karşılaştırma bilgisi doğrulanamadı.`
- `Bu urun bilgisi guvenli sekilde okunamadi.` → `Bu ürün bilgisi güvenli şekilde okunamadı.`
- `Kayıtlı helper örneğinde Urun` → `Ürün`

Product Hub kaynak ekranı örnekleri:

- `RECORD / urunler / Urun` → `RECORD / urunler / Ürün`
- `İlpen → Urun` → `İlpen → Ürün`

Not:

- `urunler` gibi node path örnekleri veri kaynağı yapısını temsil ettiği için korunmuştur.
- internal key / array key / route / enum adları değiştirilmemiştir.

## 3. Endpoint Response Türkçe Kontrolü

`ProductHubLiveProductInfoService` içindeki kullanıcı-facing response metinleri Türkçe karakterli hale getirildi.

Kontrol edilen alanlar:

- `public_safe_message`
- `warnings`
- `stock_warning`
- `product_inactive_warning`

Test güvence güncellemeleri:

- endpoint JSON içinde eski ASCII `Urun guncel` metni artık beklenmiyor
- endpoint JSON içinde `Ürün güncel ve teklif için uygun.` bekleniyor
- doğrulama ve not-found mesajları Türkçe karakterli beklentiye güncellendi

## 4. UI Metin Kontrolü

Teklif create/edit ekranında canlı bilgi kartı için beklenen metinlerin zaten doğru olduğu doğrulandı:

- `Canlı Ürün Bilgisi`
- `Güncel fiyat`
- `Güncel stok`
- `Son güncelleme`
- `Satış durumu`
- `Uyarılar`

UI testlerine ek güvence eklendi:

- `Guncel fiyat` görünmesin
- `Guncel stok` görünmesin
- `Satis durumu` görünmesin
- eski ASCII güvenli mesaj görünmesin

category_pending uyarısı bu fazda değiştirilmedi ve kullanıcı-facing beklenen hali korunuyor:

- `Kategori eşleşmemiş.`
- `Genel kategori henüz bağlanmadı.`
- `Kategori uyarısı`

## 5. Korunan İş Kuralları

Bu faz yalnız metin cleanup fazıdır. Aşağıdaki kurallar korunmuştur:

- category_pending non-blocking kaldı
- snapshot mantığı korunuyor
- fiyat otomatik değişmiyor
- tenant scope korunuyor
- foreign tenant erişimi gevşetilmedi
- Product Hub sync / import / projection davranışı değiştirilmedi

## 6. Test Sonuçları

Çalıştırılan testler:

1. `php artisan test --filter="ProductHubLiveProductInfoEndpointTest|PromotionQuoteLiveProductInfoUiTest|PromotionQuoteHasPrintFirstRowQuantityRegressionTest"`
- Sonuç: `15/15 PASS`

2. `php artisan test --filter="ProductHub|ProductDataHub|TenantCatalog|SupplierAccess|PromotionQuoteDetailCssNamespaceSmokeTest"`
- Sonuç: `366/366 PASS`

3. `php artisan test --filter="PromotionQuote|PublicQuoteApproval|OrderRevision|RepeatOrder"`
- Sonuç: `192/192 PASS`

Özet:

- toplam `573/573` test geçti
- bu fazda yeni işlevsel kırılım gözlenmedi

## 7. Manuel Smoke / HTTP Smoke

Başarılı kısa smoke:

- `/admin/promotion-quotes/create` sayfası `200` döndü
- `Canlı Ürün Bilgisi` bulundu
- `Güncel fiyat` bulundu
- `Güncel stok` bulundu
- `Satış durumu` bulundu
- `Guncel fiyat` bulunmadı
- `Satis durumu` bulunmadı

Tenant host header ile ek canlı smoke denemesi bu fazda uygulama davranışından değil, oturum/host bağlamından dolayı `Erişim Engellendi` sayfasına düştü. Bu nedenle tenant-side HTTP smoke burada kesin karar üretmek için kullanılmadı.

Not:

- category_pending non-blocking davranışı önceki final audit ve güncel test paketi ile korunmuş görünüyor
- bu fazda o iş kuralı üzerinde kod değişikliği yapılmadı

## 8. Kalan Riskler

- Product Hub commit hazırlığında ortak dosya karışma riski devam ediyor:
  - `routes/web.php`
  - `config/admin_menu.php`
  - `public/css/prodelya-admin.css`
  - `app/Http/Controllers/Admin/PromotionQuoteController.php`

- Tenant host bazlı manuel smoke için doğru tenant oturumu ile ayrı kısa kontrol gerekebilir; mevcut super admin oturumu bu kontrolde erişim kısıtına takıldı.

- Repo genelinde Product Hub dışı bazı eski ASCII metinler başka modüllerde veya eski rapor dosyalarında bulunabilir; bu faz yalnız Product Hub canlı bilgi cleanup kapsamındaydı.

## 9. Sonraki Öneri

Net öneri:

- `Product Hub release checkpoint commit hazırlığı`

Önemli:

Bu faz tamamlandıktan sonra yeni geliştirmeye geçmeden önce Product Hub değişiklikleri commit gruplarına ayrılmalı ve hunk staging ile güvenli şekilde kaydedilmelidir.
