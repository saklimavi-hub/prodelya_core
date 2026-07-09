# PH-2B Import + Alan/Kategori Birleşik Akış Raporu — 2026-07-09

## 1. Özet

* Product Hub kaynak kurulum ekranları birleşik ve daha az unutulabilir bir akış gibi yeniden çerçevelendi.
* Değişen ana dosyalar:
  * `resources/views/super-admin/product-data-hub/sources/create.blade.php`
  * `resources/views/super-admin/product-data-hub/sources/edit.blade.php`
  * `resources/views/super-admin/product-data-hub/sources/preview.blade.php`
  * `resources/views/super-admin/product-data-hub/field-mappings/source.blade.php`
  * `resources/views/super-admin/product-data-hub/category-mappings.blade.php`
  * `resources/views/super-admin/product-data-hub/sources/supplier-show.blade.php`
  * `resources/views/super-admin/product-data-hub/sources/_source-detail-card.blade.php`
  * `resources/views/super-admin/product-data-hub/sources/sync-reports.blade.php`
  * ilgili Product Hub feature testleri
* İş kuralı değişti mi: hayır.
* Endpoint yazıldı mı: hayır.
* Migration var mı: hayır.

## 2. Eski Dağınık Kurulum Akışı

* Kullanıcı kaynak kaydı, preview, alan eşleme, kategori eşleme, sync raporu ve teknik bakım aksiyonları arasında parçalı ilerliyordu.
* Özellikle şu buton/dil kombinasyonları unutulma riski yaratıyordu:
  * `Fiyat/Stok Güncelle`
  * `Değişimleri İncele`
  * `Projection Onar`
  * `Teknik Test`
  * ayrı preview / mapping / category ekranları arasında açık akış bağı olmaması
* Kullanıcı “önce kaynak ekle, sonra alan eşle, sonra kategori eşle” sırasını görse bile bunun aynı setup zinciri olduğunu yeterince net hissetmiyordu.

## 3. Yeni Birleşik Akış

Her ana ekranda kurulum adımları açık dille tekrarlandı:

* Kaynak bilgisi
* Ön kontrol
* Alan eşleme
* İlk kategori eşleme
* Toplu kategori değiştir
* Kaynağı aktif et
* Otomatik senkronizasyon

Uygulama kararı:

* `create` ve `edit` ekranlarına üstte birleşik setup flow kartları eklendi.
* `preview` ekranı “ön kontrol” adımı olarak yeniden adlandırıldı ve aynı ekrandan alan eşleme / kategori eşleme / bekleyen kontroller bağlantıları öne çıkarıldı.
* `field-mappings/source` ekranı doğrudan birleşik kurulum akışının 3. adımı gibi sunuldu.
* `category-mappings` ekranı ilk kategori eşleme ve toplu kategori değiştir mantığıyla kurulum zincirine bağlandı.
* `supplier-show` ve `_source-detail-card` içinde aynı kurulum dili güçlendirildi.

## 4. Ana Aksiyonlar

Öne çıkarılan sade ana aksiyonlar:

* `Ön Kontrol Yap`
* `Eşlemeyi Kaydet`
* `Kategorileri Eşle`
* `Kaynağı Aktif Et`
* `Ürünleri Senkronize Et`
* `Bekleyen Kontrolleri Aç`

Uygulama notu:

* Bu metinler create/edit/preview/category/source detail zincirinde görünür hale getirildi.
* `Eşlemeleri Kaydet` dili `Eşlemeyi Kaydet` olarak sadeleştirildi.

## 5. Teknik Aksiyonların Geri Plana Alınması

Bu fazda teknik aksiyonlar kaldırılmadı; ama görünürlükleri geri plana alındı.

Geri plana itilen veya yeniden adlandırılanlar:

* `Teknik Test` -> `Ön Kontrolü Yenile`
* `Projection Onar` -> `Satış Listesi Onar`
* `Fiyat/Stok Güncelle` -> `Ürünleri Senkronize Et`
* gelişmiş veri-yazan bakım aksiyonları artık daha açık biçimde `Gelişmiş Teknik İşlemler` altında anlatılıyor

Normal kullanım vurgusu:

* ekstra `havuza aktar`
* ekstra `kataloğa aktar`
* ekstra `teklife aç`
* teknik projection / dirty mantığı

ana kullanıcı akışı olarak öne çıkarılmadı.

## 6. Kategori Eşleme ve Toplu Değiştir Mantığı

* Kategori eşleme ekranına, bu adımın birleşik setup zincirindeki yeri açıkça eklendi.
* “Kategori eşlenirse aynı kategoriye gelen yeni ürünler tekrar sorulmadan akışa devam eder” mesajı görünür hale getirildi.
* “Kategori eşlenmeyen ürünler satış listesine otomatik açılmaz; Bekleyen Kontroller tarafında kalır” kararı UI’da netleştirildi.
* `Kabul Et` aksiyonu kullanıcı-facing dilde `Kategorileri Eşle` olarak sadeleştirildi.
* Toplu kategori değiştir mantığı yeni backend yazmadan, mevcut toplu karar akışı üzerinden daha görünür hale getirildi.
* Yanlış eşlemeyi geri alma ihtiyacı bu fazda korunuyor; tam “geri al geçmişi” işlevi eklenmedi.

## 7. Tenant Teklif/Sipariş Otomatik Yansıma Kararı

Bu fazda ekran dilinde netleştirilen karar:

* Ekstra `teklife aktar` işlemi yok.
* Abone firma ilgili tedarikçiye erişim aldıysa, uygun ürünler otomatik görünür.
* Senkronizasyon fiyat/stok bilgisini güncel tutar.
* Teklif satırına eklenen fiyat snapshot olarak korunur.
* Canlı teklif/sipariş uyarı endpoint’i bu fazda yazılmadı.

## 8. UI-3 Design System Uyumu

* Mevcut `pd-*` sınıf sistemi korundu.
* `Arial, Helvetica, sans-serif` standardı bozulmadı.
* Yeni veya güçlendirilen hook’lar Product Hub namespace’i içinde kaldı:
  * `.pd-product-hub`
  * `.pd-product-hub__setup-flow`
  * `.pd-product-hub__setup-step`
  * `.pd-product-hub__auto-note`
* Yeni global `.btn`, `.card`, `.chip`, `.tab`, `.modal` sınıfları açılmadı.

## 9. Güvenlik ve Hassas Veri Kontrolü

* Preview teknik detayında ham alış fiyatı ve benzeri hassas fiyat alanları artık sayısal değer olarak sergilenmiyor; yalnız alanın algılanıp algılanmadığı gösteriliyor.
* Raw payload gösterilmedi.
* Supplier internal id kullanıcı-facing sade akışa taşınmadı.
* `group_code`, `file_path`, `physical_path`, `api_key`, `token`, `secret`, `smtp_password` gibi yeni sızıntı yüzeyi açılmadı.

## 10. Test Sonuçları

Çalıştırılan filtreler:

* `php artisan test --filter="ProductHub|ProductDataHub|TenantCatalog|SupplierAccess|PromotionQuoteDetailCssNamespaceSmokeTest"`
* `php artisan test --filter="PromotionQuote|PublicQuoteApproval|OrderRevision|RepeatOrder"`

Sonuç:

* ilk filtre seti: `354` test geçti, `2390` assertion, yaklaşık `44.3 sn`
* ikinci filtre seti: `190` test geçti, `1610` assertion, yaklaşık `48.6 sn`
* ayrıca hedefli smoke olarak:
  * `ProductHubPreviewMappingTemplateCleanupTest|ProductDataHubFieldMappingUxTest`
  * `11` test geçti, `99` assertion

## 11. Manuel Smoke Sonuçları

* Workspace içinde referans preview dosyası bulundu ve okundu:
  * `docs/ui-previews/prodelya_product_hub_sade_akis_onizleme.html`
* Gerçek tarayıcı tabanlı manuel smoke bu fazda çalıştırılmadı.
* Bu nedenle sonuç statüsü:
  * tasarım referansı doğrulandı
  * canlı ekran manuel tıklama smoke yapılmadı

## 12. Kalan Riskler

* Gerçek tek-ekran wizard yazılmadı; mevcut route’lar korunarak çok-ekranlı akış daha anlaşılır hale getirildi.
* Bazı teknik Product Hub ekranlarında iç terminoloji tamamen gizlenmedi; çünkü ekranların doğası operasyonel/teknik teşhis.
* `Ürünleri Senkronize Et` aksiyonu hâlâ arka plandaki mevcut sync zincirini çalıştırır; bu fazda çekirdek sync davranışı değiştirilmedi.
* Kategori eşleme ekranı hâlâ geniş bir yönetim merkezi; yalnızca kullanıcı-facing dili sadeleştirildi.

## 13. PH-2C İçin Net Öneri

Net öneri:

* `PH-2D Bekleyen Kontroller Ekranı`

Gerekçe:

* PH-2B kaynak kurulumu, ön kontrol, alan eşleme ve ilk kategori eşlemeyi aynı zihinsel akışa yaklaştırdı.
* Bir sonraki en yüksek faydalı adım, Bekleyen Kontroller ekranını kategori / alan / fiyat / ürün durumu bazında daha karar odaklı sadeleştirmek olur.
* Böylece Product Hub’da kullanıcı artık yalnız iki şeyi düşünür:
  * kurulum akışı
  * bekleyen kararlar
