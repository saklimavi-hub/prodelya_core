# PH-2A Product Hub Otomatik Yayın ve Sade Akış Raporu — 2026-07-09

## 1. Özet

* Product Hub kullanıcı-facing akışı sadeleştirildi.
* Super Admin ekranlarında ayrı ayrı görünen "kataloğa yansıt", "projection", "tenant catalog etkisi" gibi teknik/ara işlem dili geri plana alındı.
* Ana mesaj netleştirildi: normal kullanımda ön kontrol + senkronizasyon yeterlidir; uygun ürünler Abone Firma ürün listesine ve teklif aramasına otomatik yansır, yalnız istisnalar bekleyen kontrole düşer.
* İş kuralı değişti mi: hayır.
* Endpoint yazıldı mı: hayır.
* Migration var mı: hayır.

Değişen ana dosyalar:

* `resources/views/super-admin/product-data-hub/index.blade.php`
* `resources/views/super-admin/product-data-hub/pipeline.blade.php`
* `resources/views/super-admin/product-data-hub/catalog-output.blade.php`
* `resources/views/super-admin/product-data-hub/sources/index.blade.php`
* `resources/views/super-admin/product-data-hub/sources/sync-reports.blade.php`
* `resources/views/super-admin/product-data-hub/product-panel.blade.php`
* `resources/views/super-admin/tenant-supplier-access/index.blade.php`
* ilgili yalnız beklenti güncelleyen test dosyaları

## 2. Eski Karmaşık Akış

Kafa karıştıran başlıca kullanıcı-facing dil:

* `Değişen Ürünleri Kataloğa Yansıt`
* `Abone Katalog Güncelle`
* `Projection`
* `Projection Bekleyen`
* `Tenant Katalog Etkisi`
* `Tenant çıkışı blokajları`
* `projection satırları üzerinden kontrol edilir`
* ana ekranda eski CTA dili: `Tedarikçi Akışlarını Aç`, `Abone Katalog Yayınını Aç`

Sorun:

* Kullanıcı, ürünlerin teklif ekranına düşmesi için birden fazla manuel ara işlem gerekiyormuş gibi algılayabiliyordu.
* Teknik pipeline katmanları günlük operasyon diliyle fazla iç içeydi.
* "Projection", "tenant catalog", "dirty update" gibi kavramlar günlük kullanıcı aksiyonu gibi görünüyordu.

## 3. Yeni Sade Akış Kararı

PH-2A sonrası günlük karar dili şu eksene çekildi:

* `Ürünleri Senkronize Et`
* `Bekleyen Kontrolleri Aç`
* `Yeni Kaynak Ekle`
* `Güncelleme Ayarı`

Ana karar:

* Normalde yalnız senkronizasyon yeterlidir.
* Sistem uygun ürünleri otomatik satış listesine yansıtır.
* Yalnız kategori, kimlik, erişim, eksik alan veya riskli değişim gibi istisnalar kullanıcı kararına düşer.

## 4. Otomatik Yayın Mantığı

Ana işlem olmaması gerekenler:

* havuza aktar
* kataloğa aktar
* teklife at
* dirty project
* tenant catalog projection

Neden:

* Bunlar işin arka plandaki teknik pipeline adımlarıdır.
* Günlük operasyonda kullanıcı yalnız senkronizasyonu ve karar kuyruğunu düşünmelidir.

Bu fazda UI’da verilen karar:

* `catalog-output` ekranı artık otomatik yayın mantığını anlatır.
* Mutating aksiyonlar tamamen kaldırılmadı; ama "İleri Düzey Güncelleme" altında ikincil seviyeye alındı.
* `Projection` kullanıcı-facing ana metinlerden büyük ölçüde çıkarıldı; yerine `satış listesi`, `Abone Firma ürün listesi`, `otomatik yayın` dili kullanıldı.

Arka planda çalışan teknik zincir aynen korunur:

* raw source
* standard product build
* tenant catalog projection
* quote search read model

## 5. Import + Alan Eşleme + Kategori Eşleme Birleşik Akışı

Bu fazda tam wizard yazılmadı.

Ama mevcut ekran dili şu birleşik akış fikrine yaklaştırıldı:

1. Kaynak bilgisi
2. Ön kontrol
3. Alan eşleme
4. İlk kategori eşleme
5. Gerekirse toplu kategori düzeltme
6. Kaynak aktifse otomatik senkronizasyon ve otomatik satış listesi yansıması

PH-2A’da yapılanlar:

* `sources/index` ekranı "kaynak ve ön kontrol" mantığıyla yeniden çerçevelendi.
* "Detaya Git" ana CTA olarak bırakıldı.
* ayrı "havuza aktar" veya "teklife gönder" hissi veren dil azaltıldı.

## 6. Bekleyen Kontroller Mantığı

Kuyruğa düşmesi gereken durumlar:

* yeni kategori
* eksik alan
* kimlik / varyant sorunu
* şüpheli fiyat/stok değişimi
* pasife düşen / kaybolan ürün
* yeni ürün manuel karar ihtiyacı

Kuyruğa düşmemesi gereken durumlar:

* normal fiyat değişimi
* normal stok değişimi
* erişimi açık ve satışa uygun ürünlerde rutin güncelleme

UI kararı:

* `Bekleyen Kontrolleri Aç` ana günlük aksiyon olarak öne çıkarıldı.
* `sync-reports` ekranında istisna mantığı açık yazıyla belirtildi.
* `product-panel` üzerinde `satış listesi sorunları` ve `Abone Firma çıkışı blokajları` diliyle karar kuyruğu sadeleştirildi.

## 7. Tenant Teklif/Sipariş Otomatik Yansıma Kararı

Bu fazda UI ve rapor düzeyinde netleştirilen karar:

* Abone Firma hangi tedarikçiye erişim aldıysa, o tedarikçinin satışa uygun ürünleri otomatik görünür.
* Ekstra `teklife aktar` adımı yoktur.
* Senkronizasyon sonrası ürünler Abone Firma ürün listesine ve teklif aramasına otomatik yansır.
* Teklif satırına eklenen fiyat snapshot olarak korunur.
* Sonraki fiyat/stok değişimi eski snapshot'ı bozmaz.
* Erişim kapanırsa yeni seçimde görünmez; eski teklif snapshot'ı yine korunur.

Canlı endpoint:

* bu fazda yazılmadı

## 8. UI / Menü / Terminoloji Değişiklikleri

Ana terminoloji dönüşümleri:

* `Projection` -> `satış listesi` / `otomatik yayın`
* `Tenant Catalog` -> `Abone Firma ürün listesi`
* `Projection Bekleyen` -> `Satış Listesine Yansıma Bekleyen`
* `Tenant Katalog Etkisi` -> `Abone Firma Ürün Listesi`
* `Projection eski` -> `Satış listesi eski`
* `Tenant çıkışı blokajları` -> `Abone Firma çıkışı blokajları`
* `Abone Katalog Güncelle` -> `Ürünleri Güncelle`
* `Eksik Kayıtları Tamamla` -> `Boşlukları Tamamla`
* `Abone Katalog Yayınını Aç` ana CTA'sı yerine merkez ekranda `Ürünleri Senkronize Et`, `Bekleyen Kontrolleri Aç`, `Yeni Kaynak Ekle`, `Güncelleme Ayarı` öne çıkarıldı

UI hook’ları:

* mevcut `.pd-product-hub` namespace daha fazla ekrana taşındı
* düşük riskli wrapper/hook sınıfları korundu

Not:

* Kullanıcının referans verdiği `prodelya_product_hub_sade_akis_onizleme(2).html` dosyası workspace içinde bulunamadı; bu nedenle uygulama mevcut repo ekranları ve PH-1/UI-3 dokümanları üzerinden yapıldı.

## 9. Güvenlik ve Hassas Veri Kontrolü

Bu fazda:

* raw payload gösterilmedi
* supplier internal id kullanıcı-facing sade özetten öne çıkarılmadı
* cost / purchase cost ekrana taşınmadı
* file path / physical path / token / secret / smtp_password gibi alanlar için yeni sızıntı yüzeyi açılmadı

Super Admin teknik detay ekranları mevcut sınırında kaldı; sade günlük özet ekranları daha da güvenli dil kullandı.

## 10. Test Sonuçları

Çalıştırılan filtreler:

* `php artisan test --filter="ProductHub|ProductDataHub|TenantCatalog|SupplierAccess|PromotionQuoteDetailCssNamespaceSmokeTest"`
* `php artisan test --filter="PromotionQuote|PublicQuoteApproval|OrderRevision|RepeatOrder"`

Sonuç:

* ilk filtre seti: `353` test geçti, `2376` assertion, yaklaşık `55.4 sn`
* ikinci filtre seti: `190` test geçti, `1610` assertion, yaklaşık `52.5 sn`
* toplam: `543` test geçti

Not:

* Güncellenen testler yalnız yeni Türkçe terminoloji ve sade akış metin beklentileriyle hizalandı.
* İş kuralı doğrulayan testlerde davranış değişikliği yapılmadı.

## 11. Kalan Riskler

* `catalog-output` içinde ileri düzey güncelleme formları teknik olarak hâlâ mevcut; yalnızca ikincil seviyeye alındı.
* `product-panel` gibi bazı teknik ekranlarda iç terminoloji tamamen gizlenmedi; çünkü ekranın tanımı operasyonel/teknik teşhis.
* Gerçek "tek tuşla senkronize et" deneyimi bazı kaynak detay ekranlarında hâlâ route/ekran parçalanmasıyla yaşıyor.
* Güncelleme ayarı ekranı ayrı bir scheduler yönetim yüzeyi değil; bugün daha çok teknik açıklama/akış ekranı işlevi görüyor.

## 12. PH-2B İçin Net Öneri

Net öneri:

* `PH-2B Import + Alan/Kategori Eşleme Wizard`

Gerekçe:

* PH-2A günlük yayın akışının dilini sadeleştirdi.
* Bir sonraki büyük kazanım, yeni kaynak kurulumunda dağınık preview + field mapping + category mapping akışını tek rehberli setup deneyimine toplamak olur.
* Canlı ürün bilgisi endpoint'i değerli olsa da, önce kaynak kurulumunun kullanıcı algısını sadeleştirmek Product Hub deneyimini daha bütünlüklü hale getirir.
