# Teklif Currency UI Preview Raporu — 2026-07-10

## 1. Faz özeti
Bu faz preview-only olarak yürütüldü. Production Blade, CSS, JavaScript, controller, service, model, migration, config, route ve test dosyalarına dokunulmadı. Yeni çıktı olarak yalnız standalone HTML preview ve bu değerlendirme raporu üretildi.

## 2. Production ekran incelemesi
`resources/views/admin/promotion-quotes/create.blade.php` ve `resources/views/admin/promotion-quotes/_form-workspace.blade.php` read-only incelendi. Mevcut ekranın müşteri arama alanı, teklif meta bilgileri, ürün kalemleri, baskı alt satırları, KDV/not alanları ve sağ sticky özet paneli hiyerarşisi korundu.

## 3. Currency Core contract özeti
Ana currency davranışı Product Data Hub üzerinden gelen kaynak fiyatın tenant base currency’ye güvenli dönüşümü üzerine kuruluyor. Preview içinde kullanılan ana kavramlar:

- `source_price`
- `source_currency`
- `base_price`
- `base_currency`
- `conversion_available`
- `conversion_status`
- `applied_rate`
- `rate_date`
- `rate_source`
- `rate_type`
- `is_fallback_rate`
- `is_stale_rate`
- `currency_origin`
- `currency_status`
- `multi_currency_enabled`
- `can_view_currency_details`
- `can_use_foreign_document_currency`
- `can_use_manual_rate`

## 4. Product Data Hub currency payload özeti
`app/Services/ProductDataHub/ProductHubCurrencyService.php`, `app/Http/Controllers/Admin/CatalogSearchController.php`, `app/Http/Controllers/Admin/ProductHubLiveProductInfoController.php` ve ilgili currency payload testleri read-only doğrulandı. Browser payload tarafında finans yetkisi olmayan kullanıcı için kaynak fiyat, kaynak para birimi ve rate detayları gizleniyor. `can_use_manual_rate` mevcut implementation’da `false`.

## 5. İncelenen eski preview aileleri
Read-only fikir kaynağı olarak şu ailelere bakıldı:

- promosyon teklif oluşturma preview’ları
- müşteri arama scroll dropdown preview’ları
- müşteri seçimi komut paleti preview’ları
- sağ sticky hızlı sipariş paneli preview’ları
- teklif ara eleman popup preview’ları
- promosyon teklif KDV preview’ları
- teklif detay kompakt preview’ları

## 6. Eski preview’lardan alınan fikirler
- Kompakt üst bilgi alanı
- Scrollable müşteri/ürün arama sonucu mantığı
- Sağ sticky özet paneli
- Ürün satırı altında ikincil bilgi şeridi yaklaşımı
- Kısa operasyon notları ve rozetlerle durum anlatımı

## 7. Alınmayan fikirler
- Ayrı büyük ürün detay paneli
- Fazla kartlaşmış blok düzeni
- Teknik Product Hub alanlarının tenant ekranına taşınması
- Matbaa V2 alanları
- Dieline V3 alanları
- Production truth olmayan eski dummy veri modeli

## 8. Yeni preview’ın amacı
Amaç, mevcut teklif hazırlama akışını bozmadan TRY, USD ve EUR kaynaklı ürünlerin anlaşılır biçimde teklif satırına alınmasını göstermek; TCMB kuru ile TL karşılığını açıklamak; önerilen satış fiyatı ile kullanıcı satış fiyatını ayırmak; manuel satış fiyatının kur güncellemesinde ezilmediğini görselleştirmektir.

## 9. multi_currency açık/kapalı davranışı
Preview iki tenant davranışını birlikte gösterir:

- `multi_currency` kapalı: belge para birimi yalnız TL, USD/EUR seçenekleri pasif, sade “bu paket yalnız TL teklif oluşturur” mesajı.
- `multi_currency` açık: belge para birimi TL, USD ve EUR olarak gösterilebilir; ana demo yine TL belge para birimi etrafında tutulur.

## 10. Finans yetkili görünüm
Finans yetkili senaryoda aşağıdaki alanlar görünür:

- kaynak fiyat
- kaynak para birimi
- uygulanan kur
- kur tarihi
- tahmini alış maliyeti
- önerilen satış fiyatı
- stale/fallback uyarıları

## 11. Operasyon kullanıcısı görünüm
Operasyon senaryosunda aşağıdaki güvenli alanlar korunur:

- ürün adı
- adet
- satış birim fiyatı
- KDV
- toplam
- baskı bilgisi
- stok

Gizli kalan alanlar:

- supplier alış maliyeti
- source cost ayrıntıları
- applied rate
- kur override yapan kişi/gerekçe
- supplier financial metadata

## 12. TRY/USD/EUR senaryoları
Preview aynı form içinde üç ürün örneği taşır:

- USD kaynaklı Metal Tükenmez Kalem
- EUR kaynaklı Premium Termos
- TRY kaynaklı Plastik Tükenmez Kalem

Bu veriler demo niteliğindedir ve hassas gerçek veri içermez.

## 13. Kur durumları
Preview küçük demo seçici ile şu durumları gösterir:

- güncel kur
- önceki iş günü kuru
- stale kur
- manuel kur senaryosu
- kur bulunamadı
- unsupported currency

## 14. Manuel fiyat koruma davranışı
USD kaynaklı ana örnekte satış birim fiyatı kullanıcı tarafından değiştirilebilir tutuldu. “Kuru Güncelle” davranışı önerilen fiyat ve maliyet bilgisini yenileyebilir; ancak manuel satış fiyatını ezmez. “Mevcut Kuru Koru” akışında da satış alanı korunur.

## 15. Taslak/gönderilmiş/siparişe dönüşmüş teklif davranışı
Sağ bilgi alanında küçük operasyon notu olarak gösterildi:

- taslak: kur güncellenebilir
- gönderilmiş: kullanılan kur snapshotı kilitlenir, değişiklik için revizyon gerekir
- siparişe dönüşmüş: teklif snapshotı siparişe taşınır, yeni kurla otomatik yeniden fiyatlandırılmaz

## 16. Responsive yaklaşım
Preview masaüstünde ana form + sağ sticky özet düzeniyle çalışır. Dar ekranda:

- sağ özet alta iner
- meta alanlar tek kolona düşer
- ürün satırı alanları alt alta dizilir
- kur detayları ikinci satır mantığında kalır
- satış fiyatı alanı görünür kalır

## 17. Production’dan farklar
- Bu çıktı standalone HTML’dir
- Production route, Blade syntax veya submit davranışı yoktur
- Canlı TCMB çağrısı yapılmaz
- Gerçek tenant/müşteri verisi kullanılmaz
- Manuel kur aksiyonu çalışıyormuş gibi uygulanmaz

## 18. Uygulanırsa değişecek dosya aileleri
Kullanıcı onayı sonrası muhtemel production implementation şu aileleri etkileyebilir:

- quote create/edit Blade dosyaları
- ilgili quote JavaScript akışı
- quote controller submit/snapshot alanları
- olası currency snapshot taşıma kodu
- ilgili test dosyaları

Bu fazda bunların hiçbiri değiştirilmedi.

## 19. Gerekli backend sözleşmeleri
Production implementation için korunması gereken temel sözleşmeler:

- finans yetkisine göre currency detaylarını gizleme
- `conversion_status` davranışının UI’a doğru yansıması
- `multi_currency_enabled` ile belge para birimi seçeneğini kontrol etme
- `can_use_foreign_document_currency` davranışını form seviyesinde kullanma
- `can_use_manual_rate` açılmadıkça manuel kur aksiyonunu pasif tutma
- kur bulunamadığında yanlış TL fiyat üretmeme

## 20. Kapsam dışı
Bu preview içinde özellikle yer verilmedi:

- Matbaa V2
- Dieline V3
- fason currency motoru
- public portal
- PDF final template
- gerçek API call
- gerçek form submit
- raw technical Product Hub alanları

## 21. Güvenlik kontrolü
Dosya içinde aşağıdakiler aranarak güvenlik kontrolü uygulandı:

- gerçek token
- gerçek API key
- gerçek password
- gerçek müşteri e-postası
- gerçek telefon
- gerçek local absolute path
- `file:///C:/`
- `localhost`
- `saklimavi.prodelya_core.test`
- supplier secret
- raw XML/JSON

Preview içinde bunlara yer verilmedi.

## 22. Kullanıcıdan beklenen karar
Bu preview tamamlandıktan sonra açık kullanıcı onayı gerekir. Beklenen karar seçenekleri:

1. Uygun — production uygulamasına geç
2. Küçük düzenleme gerekli
3. Alternatif preview hazırla
4. Bu yaklaşımı kullanma

## 23. Sonraki faz
Kullanıcı preview’ı açıkça onaylarsa sonraki faz:

`Prodelya_V1 10.16.4 — Quote Currency Conversion and Snapshot Implementation`

Bu rapor kapsamında production implementation başlatılmadı.
