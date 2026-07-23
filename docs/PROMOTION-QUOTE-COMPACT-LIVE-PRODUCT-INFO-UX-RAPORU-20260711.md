# Promotion Quote Compact Live Product Info UX Raporu — 2026-07-11

## 1. Yönetici özeti
- Büyük `Canlı Ürün Bilgisi` kartı quote create/edit formunda kaldırıldı.
- Gerekli canlı bilgiler ürünün altındaki mevcut kompakt meta satıra taşındı.
- Müşteri araması, ürün araması, TL/USD/EUR dönüşümü ve toplam hesapları korunarak doğrulandı.
- Commit veya staging yapılmadı.
- Nihai karar: `COMPACT LIVE PRODUCT INFO READY — MANUAL UX REVIEW`

## 2. Kullanıcı geri bildirimi
Kullanıcı geri bildirimi, ürün seçimi sonrası açılan büyük canlı ürün bilgisi kartının iki veya daha fazla ürün olduğunda formu gereksiz uzattığı ve satış kullanıcısına fazla teknik tekrar gösterdiği yönündeydi.

## 3. Önceki büyük kartın bilgi yoğunluğu
Önceki yapı şunları ayrı bloklar halinde gösteriyordu:
- `Canlı Ürün Bilgisi` başlığı
- açıklama mesajı
- büyük durum metni
- ayrı güncel fiyat kutusu
- ayrı güncel stok kutusu
- ayrı son güncelleme kutusu
- ayrı satış durumu kutusu
- ayrıca uyarı alanı

Bu yapı özellikle iki ürünlü formda gereksiz yükseklik oluşturuyordu.

## 4. Yeni kompakt bilgi mimarisi
Yeni yapı büyük dashboard kartını kaldırıp canlı ürün verisini ürünün altındaki mevcut kompakt meta akışına indirdi.

Yeni yerleşim:
- ürün adı
- mevcut kısa meta satırda ürün kodu
- hemen altında canlı meta row
- alt satırda yalnız gerekli küçük badge uyarıları
- yalnız bloklayıcı veya hata durumunda kısa açıklama mesajı

## 5. Gösterilen bilgiler
Başarılı ürün durumunda kompakt row içinde gösterilen bilgiler:
- ürün kodu
- güncel stok
- güncel fiyat
- son güncelleme zamanı

Headless smoke örneği:
- ürün kodu: `ET-0506-S`
- stok: `2.000`
- güncel fiyat: `9,20 TL`
- güncellendi: `29.06.2026 06:46`

## 6. Kaldırılan/tekrarlanmayan bilgiler
Kaldırılan tekrarlar:
- büyük `Canlı Ürün Bilgisi` başlığı
- ayrı dört metrik kutusu
- büyük durum başlığı
- tekrar eden `Teklife uygun` vurgusu
- ayrı `Uyarılar` paneli

## 7. Uyarı ve bloklayıcı durum davranışı
Uyarılar tamamen kaldırılmadı; kompakt badge yapısına indirildi.

Davranış:
- kategoriye bağlı non-blocking uyarılar tek badge altında gösteriliyor: `Kategori uyarısı`
- fiyat snapshot farkı tek badge altında gösteriliyor: `Güncel fiyat farklı`
- bloklayıcı durumlar kısa kırmızı badge ile gösteriliyor:
  - `Teklifte kullanılamaz`
  - `Ürün pasif`
  - `Kur bilgisi bulunamadı`
- bloklayıcı mesaj gerekiyorsa kısa satır mesajı korunuyor

## 8. Değişen dosyalar
- `resources/views/admin/promotion-quotes/_form-workspace.blade.php`
- `tests/Feature/PromotionQuoteLiveProductInfoUiTest.php`
- `docs/PROMOTION-QUOTE-COMPACT-LIVE-PRODUCT-INFO-UX-RAPORU-20260711.md`

## 9. UI/CSS kapsamı
Değişiklikler feature-local quote form alanında tutuldu.

Dokunulan alanlar:
- Blade içi live product info render fonksiyonu
- Blade içi feature-local style bloğu
- quote item içi compact meta yerleşimi
- ilgili UI regression testleri

Dokunulmayan alanlar:
- ProductHub endpoint contract
- currency core backend davranışı
- global CSS primitive’leri
- public/customer yüzeyleri

## 10. Güvenlik ve hassas veri kontrolü
DOM ve headless smoke içinde aşağıdaki hassas alanların görünmediği doğrulandı:
- `purchase_price`
- `supplier_price`
- `group_code`
- `raw_payload`

Ayrıca internal snapshot/raw teknik anahtarlar kullanıcı-facing compact row’a taşınmadı.

## 11. Hedefli test sonuçları
Test runtime yapılandırması `phpunit.xml` üzerinden doğrulandı:
- `DB_CONNECTION=sqlite`
- `DB_DATABASE=:memory:`

Çalıştırılan hedefli komutlar ve sonuçlar:
- `PromotionQuoteCreateEditUiRegressionTest`: 5 test, 98 assertion, geçti
- `PromotionQuoteLiveProductInfoUiTest`: 2 test, 76 assertion, geçti
- `ProductHubLiveProductInfoEndpointTest`: 12 test, 51 assertion, geçti
- `CatalogSearchCurrencyPayloadTest`: 3 test, 21 assertion, geçti
- `PromotionQuoteHasPrintFirstRowQuantityRegressionTest`: 1 test, 10 assertion, geçti
- `PromotionQuoteCurrencySnapshotTest`: 5 test, 18 assertion, geçti
- `PromotionQuote|Currency`: 175 test, 1349 assertion, geçti
- `AdminSmokeTest|FullOperationalFlowSmokeTest`: 60 test, 644 assertion, geçti
- `DemoTenantFullAccessTest`: 2 test, 26 assertion, geçti

## 12. Development DB sayaçları
Test öncesi:
- tenants: `6`
- tenant_catalog_products: `18032`
- orders: `30`

Test sonrası:
- tenants: `6`
- tenant_catalog_products: `18032`
- orders: `30`

Sayaçlar değişmedi.

## 13. Headless Chrome/manual smoke
Gerçek host:
- `http://saklimavi.prodelya_core.test/admin/promotion-quotes/create`

Doğrulananlar:
- müşteri araması çalıştı
- müşteri sonucu: `2`
- `0506` araması iki satırda da sonuç verdi
- en az iki ürün seçildi
- her iki ürün satırında da büyük kart başlığı görünmedi
- iki satırda da compact meta bilgileri göründü
- kategori uyarısı kompakt badge olarak göründü
- TL/USD/EUR seçenekleri göründü
- TRY/USD/EUR toplamları güncellendi
- runtime JavaScript exception sayısı: `0`
- sensitive alan sızıntısı bulunmadı

## 14. Önce/sonra kompaktlık sonucu
Headless ölçümde yeni compact live-info alanı yaklaşık `55px` yüksekliğe indi.

Önceki yapı, başlık + açıklama + dört metrik kutusu + uyarı alanı nedeniyle yaklaşık `140-160px` seviyesinde bir alan kullanıyordu.

Pratik sonuç:
- yaklaşık yükseklik düşüşü: `yaklaşık 90-105px`
- iki ürünlü formda gözle görülür dikey kısalma sağlandı

## 15. Final Git durumu
Başlangıç HEAD:
- `2bd5d749abbdb7c3e61b6b8024d5150287df6aa8`

Branch:
- `feature/master-restructure-phase-2-order-flow`

Final durum:
- staged alan boş
- ilgili modified dosyalar:
  - `resources/views/admin/promotion-quotes/_form-workspace.blade.php`
  - `tests/Feature/PromotionQuoteLiveProductInfoUiTest.php`
- report dosyası:
  - `docs/PROMOTION-QUOTE-COMPACT-LIVE-PRODUCT-INFO-UX-RAPORU-20260711.md`

## 16. Kalan riskler
- Çok özel bloklayıcı kombinasyonlarda badge başlığı ile kısa hata mesajının gerçek kullanıcı beklentisi manuel gözle tekrar değerlendirilmeli.
- Çok uzun ürün adlarında dar ekran wrap davranışı görsel olarak manuel kontrol edilmeli.

## 17. Nihai karar
`COMPACT LIVE PRODUCT INFO READY — MANUAL UX REVIEW`

## 18. Kullanıcının manuel kontrol adımları
- `http://saklimavi.prodelya_core.test/admin/promotion-quotes/create` aç
- müşteri araması yap
- `0506` ile iki ayrı ürün satırı seç
- büyük `Canlı Ürün Bilgisi` kartının görünmediğini doğrula
- ürün adı/kodu/stok/güncel fiyat/güncelleme bilgisinin kompakt row’da kaldığını doğrula
- `TRY`, `USD`, `EUR` arasında geçiş yapıp toplamların güncellendiğini doğrula
- quantity değiştirip sağ özetin güncellendiğini doğrula
