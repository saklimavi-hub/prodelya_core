# PH-2C-B Teklif Ürün Seçim Canlı Bilgi Entegrasyon Raporu — 2026-07-09

## 1. Özet

* PH-2C'de eklenen güvenli canlı ürün bilgisi endpoint'i teklif create/edit ürün seçim ekranına bağlandı.
* Değişen ana dosyalar:
  * `resources/views/admin/promotion-quotes/_form-workspace.blade.php`
  * `resources/views/admin/promotion-quotes/create.blade.php`
  * `resources/views/admin/promotion-quotes/edit.blade.php`
  * `tests/Feature/PromotionQuoteLiveProductInfoUiTest.php`
* DB write yok.
* Endpoint güvenlik mantığı değiştirilmedi; yalnız UI'de kullanıldı.
* Endpoint contract değişmedi.

## 2. Mevcut Form Akışı Analizi

* Ürün arama `GET /admin/catalog/search` ile çalışıyor.
* Arama sonuçlarında `tenant_catalog_product_id` ve varyant varsa `tenant_catalog_product_variant_id` dönüyor.
* Ürün seçimi `normalizeCatalogSelectionEntry()` ve `updateItemSummary()` üzerinden satıra aktarılıyor.
* Snapshot fiyat/stok `price_snapshot` ve `stock_snapshot` alanlarında tutuluyor.
* Edit ekranında mevcut order item kimliği artık güvenli `quote_item_id` olarak UI state'ine taşınıyor.
* Stable key, print row, toplam hesaplama ve mount/collect zinciri korunuyor.

## 3. Canlı Bilgi Kutusu

* Kutu ürün satırında, ürün meta bloğunun hemen altında gösteriliyor.
* Gösterilen alanlar:
  * Güncel fiyat
  * Güncel stok
  * Son güncelleme
  * Satış durumu
  * Uyarılar
* Ürün seçilmemişse pasif bilgi metni gösteriliyor.
* Loading, success, warning ve error durumları ayrı görünümle işleniyor.

## 4. Endpoint Entegrasyonu

* Kullanılan route: `/admin/product-hub/live-product-info`
* Parametreler güvenli alanlarla kuruluyor:
  * `tenant_catalog_product_id`
  * `tenant_catalog_product_variant_id`
  * `quote_item_id`
  * `snapshot_price`
  * `snapshot_stock`
* Ürün seçimi yoksa çağrı yapılmıyor.
* State, form hesap zincirinden ayrı bir istemci cache içinde tutuluyor; remount sonrası gereksiz tekrar fetch azaltılıyor.

## 5. Snapshot ve Fiyat Güvenliği

* Fiyat otomatik değişmiyor.
* Mevcut teklif snapshot verisi korunuyor.
* Kullanıcıya yalnız bilgi ve warning gösteriliyor.
* Endpoint sonucu satır toplamları, KDV ve baskı zincirini otomatik güncellemiyor.

## 6. Hassas Veri Kontrolü

* Data attribute olarak yalnız güvenli alanlar kullanıldı:
  * `data-tenant-catalog-product-id`
  * `data-tenant-catalog-product-variant-id`
  * `data-quote-item-id`
  * `data-snapshot-price`
  * `data-snapshot-stock`
* Hassas alanlar UI entegrasyonuna taşınmadı:
  * purchase/cost/supplier price
  * raw payload
  * group code
  * token / api key / secret
* Blade/UI testlerinde hassas anahtar kelimelerin görünmediği doğrulandı.

## 7. UI-3 ve Türkçe Metin Uyumu

* Namespace olarak `pd-product-live-info` ailesi kullanıldı.
* Türkçe karakterli kullanıcı metinleri kullanıldı.
* Kutu, mevcut `pd-card`, `pd-chip`, `pd-btn` görsel diliyle uyumlu tutuldu.
* Global `btn/card/chip/tab/modal` benzeri yeni çakışan class açılmadı.

## 8. Test Sonuçları

* Çalıştırılan komutlar:
  * `php artisan test --filter="ProductHubLiveProductInfoEndpointTest|PromotionQuote|PromotionQuoteDetail|PromotionQuoteLiveProductInfo|PromotionQuoteHasPrintFirstRowQuantityRegressionTest"`
  * `php artisan test --filter="ProductHub|ProductDataHub|TenantCatalog|SupplierAccess"`
  * `php artisan test --filter="PublicQuoteApproval|OrderRevision|RepeatOrder"`
* Sonuçlar:
  * `ProductHubLiveProductInfoEndpointTest|PromotionQuote|PromotionQuoteDetail|PromotionQuoteLiveProductInfo|PromotionQuoteHasPrintFirstRowQuantityRegressionTest`: 137 test geçti
  * `ProductHub|ProductDataHub|TenantCatalog|SupplierAccess`: 364 test geçti
  * `PublicQuoteApproval|OrderRevision|RepeatOrder`: 66 test geçti
* Toplam doğrulanan test: 567
* Yeni UI testi:
  * `tests/Feature/PromotionQuoteLiveProductInfoUiTest.php`

## 9. Manuel Smoke Sonucu

* İn-app browser yüzeyi bu oturumda erişilebilir değildi; bu nedenle gerçek browser tabanlı smoke tamamlanamadı.
* Buna rağmen otomatik test tarafında şu noktalar doğrulandı:
  * create/edit formunda canlı bilgi kutusu hook'u mevcut
  * güvenli data attribute şablonu mevcut
  * edit payload içinde `quote_item_id` ve katalog kimlikleri taşınıyor
  * mevcut baskı miktarı regresyonu bozulmuyor

## 10. Kalan Riskler

* Live info state istemci cache'i remount dostu olacak şekilde kurgulandı; yine de yoğun ürün değişiminde browser smoke faydalı olur.
* Şu faz yalnız teklif create/edit ekranını hedefliyor; sipariş edit/revizyon ekranlarında ayrı genişletme gerekebilir.

## 11. Sonraki Öneri

* Net öneri: `PH-2C-C Sipariş Edit/Revizyon ekranlarında canlı bilgi genişletme`

Bu fazda canlı bilgi kullanıcıya gösterilir; teklif fiyatı, satır toplamları ve snapshot verisi otomatik değiştirilmez.
