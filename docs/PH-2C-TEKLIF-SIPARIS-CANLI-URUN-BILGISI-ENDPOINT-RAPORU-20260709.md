# PH-2C Teklif/Sipariş Canlı Ürün Bilgisi Endpoint Raporu — 2026-07-09

## 1. Özet

* Teklif/sipariş ürün seçimleri için güvenli, read-only canlı ürün bilgisi endpoint'i eklendi.
* Değişen ana dosyalar:
  * `app/Http/Controllers/Admin/ProductHubLiveProductInfoController.php`
  * `app/Services/ProductDataHub/ProductHubLiveProductInfoService.php`
  * `routes/web.php`
  * `tests/Feature/ProductHubLiveProductInfoEndpointTest.php`
* Endpoint yazıldı: `GET /admin/product-hub/live-product-info`
* Migration yok.
* DB write yok.

## 2. Mevcut Ürün Seçim Akışı Analizi

* Ürün arama şu anda `GET /admin/catalog/search` endpoint'i ile çalışıyor.
* Bu endpoint ham Product Hub katmanından değil, `TenantCatalogProduct` ve `TenantCatalogProductVariant` read model'inden besleniyor.
* Arama sonucu ürün seçiminde `tenant_catalog_product_id` ve gerekiyorsa `tenant_catalog_product_variant_id` dönüyor.
* Flat ürün ve varyant ayrımı `CatalogSearchController::expandSellableSearchResults()` içinde yapılıyor.
* Tenant erişimi `resolve.tenant` middleware'i, `tenant.membership` middleware'i ve arama içinde `TenantSupplierAccess` filtresi ile korunuyor.
* Teklif satırına ürün eklenirken fiyat/stok bilgisi `price_snapshot` ve `stock_snapshot` olarak satıra kopyalanıyor; canlı veri satırı mutate etmiyor.
* Mevcut akış kullanıcıya canlı karşılaştırma endpoint'i sunmuyordu; bu faz onu güvenli okuma katmanı üzerinden ekledi.
* En güvenli okuma katmanı tenant catalog satış/read model katmanıdır; yeni endpoint bunu kullanır.

## 3. Yeni Endpoint

* Route: `admin.product-hub.live-product-info`
* URL: `/admin/product-hub/live-product-info`
* Controller: `ProductHubLiveProductInfoController`
* Service: `ProductHubLiveProductInfoService`
* Desteklenen parametreler:
  * `tenant_catalog_product_id`
  * `tenant_catalog_product_variant_id`
  * `quote_item_id`
  * `snapshot_price`
  * `snapshot_stock`
  * `currency`
* Response güvenli alanlar içerir:
  * ürün/varyant adı
  * display code
  * güncel fiyat/stok
  * para birimi
  * son senkron zamanı
  * sellable/access/visibility booleans
  * snapshot fark uyarıları
  * kısa güvenli mesaj ve warnings listesi

## 4. Tenant Scope ve Güvenlik

* Endpoint yalnız tenant admin route grubu altında tanımlandı; public/customer route açılmadı.
* Auth, tenant resolve, tenant active ve tenant membership middleware standardı korunuyor.
* Başka tenant ürün/varyant ID'si 404 güvenli response ile reddediliyor.
* `quote_item_id` verilirse yalnız aynı tenant içindeki satır okunuyor ve seçili ürün/varyant ile eşleşme aranıyor.
* Supplier access durumu `TenantSupplierAccess` üzerinden tekrar kontrol ediliyor.
* Quote visible veya tenant catalog aktiflik durumu uygun değilse endpoint bunu güvenli uyarı olarak dönüyor.

## 5. Snapshot Karşılaştırma

* Endpoint fiyat güncellemez.
* `snapshot_price` veya `quote_item_id` içindeki snapshot fiyatı ile güncel fiyat karşılaştırılır.
* `snapshot_stock` veya `quote_item_id` içindeki stock snapshot ile güncel stok karşılaştırılır.
* Ürün satışa uygun değilse veya liste/erişim kapalıysa Türkçe kullanıcı-facing warning üretilir.
* Örnek warning tipleri:
  * fiyat farkı
  * stok farkı
  * tedarikçi erişimi kapalı
  * teklifte kullanıma kapalı
  * satış listesinde kapalı / pasif

## 6. Hassas Veri Kontrolü

* Response içine şu alanlar alınmadı:
  * purchase/supplier/cost bilgileri
  * raw payload
  * supplier internal/source id alanları
  * standard/internal teknik id'ler
  * tenant id
  * path / token / secret benzeri gizli alanlar
* Feature test içinde response JSON düz metin olarak taranıp bu kelimelerin sızmadığı doğrulandı.

## 7. UI Entegrasyon Durumu

* Bu fazda ağır form/JS entegrasyonu yapılmadı.
* Mevcut `/admin/promotion-quotes/create` veya edit layout'u değiştirilmedi.
* Canlı bilgi AJAX entegrasyonu, düşük riskli ayrı faz olarak bırakıldı.

## 8. Test Sonuçları

* Yeni test dosyası eklendi:
  * `tests/Feature/ProductHubLiveProductInfoEndpointTest.php`
* Çalıştırılan komutlar:
  * `php artisan test --filter=ProductHubLiveProductInfoEndpointTest`
  * `php artisan test --filter="ProductHub|ProductDataHub|TenantCatalog|SupplierAccess|PromotionQuoteDetailCssNamespaceSmokeTest"`
  * `php artisan test --filter="PromotionQuote|PublicQuoteApproval|OrderRevision|RepeatOrder"`
* Sonuçlar:
  * `ProductHubLiveProductInfoEndpointTest`: 11 test geçti
  * `ProductHub|ProductDataHub|TenantCatalog|SupplierAccess|PromotionQuoteDetailCssNamespaceSmokeTest`: 365 test geçti
  * `PromotionQuote|PublicQuoteApproval|OrderRevision|RepeatOrder`: 190 test geçti
* Toplam doğrulanan test: 566
* Route doğrulaması:
  * `php artisan route:list --name=product-hub.live-product-info` ile route kaydı doğrulandı.

## 9. Manuel Smoke Sonucu

* Browser tabanlı ayrı manuel smoke yapılmadı.
* Buna karşılık tenant host üzerinden çalışan JSON endpoint davranışı feature test ile doğrulandı:
  * kendi tenant ürünü okunabiliyor
  * varyant cevabı dönüyor
  * başka tenant ürünü reddediliyor
  * supplier access / quote visible kapanınca güvenli uyarı dönüyor
  * snapshot fiyat ve stok farkı işaretleniyor

## 10. Kalan Riskler

* UI tarafında henüz canlı endpoint çağrısı olmadığı için kullanıcı akışında görünür uyarı entegrasyonu sonraki faza kalıyor.
* `quote_item_id` isimlendirmesi uygulama içinde `OrderItem` modeli ile temsil ediliyor; mevcut quote/order item ortak yapısı korunuyor.
* Türkçe kullanıcı metinleri güvenli ve sade tutuldu; istenirse sonraki fazda içerik tonu ürün ekip diliyle hizalanabilir.

## 11. Sonraki Öneri

* Net öneri: `PH-2C-B Teklif Ürün Seçim Ekranına Canlı Bilgi Entegrasyonu`

Bu faz fiyat güncellemez, teklif satırını değiştirmez ve Product Hub teknik verisini açığa çıkarmaz. Sadece güvenli canlı bilgi okuma endpoint'i sağlar.
