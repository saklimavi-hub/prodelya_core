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
- desteklenmeyen para birimi

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

## 24. Revizyon bölümü — 10.16.3-R1
İlk preview değerlendirmesi sonrası production implementation’a geçmeden önce ikinci bir preview revizyonu yapıldı. Revizyonun temel gerekçesi, belge para birimi değiştiğinde yalnız etiketlerin değil hesapların da tutarlı biçimde değişmesi ve rol/modül güvenliğinin daha sert uygulanmasıydı.

### 24.1 İlk preview değerlendirme sonucu
İlk sürümde ana kurgu doğruydu; ancak aşağıdaki konular implementation öncesi yanıltıcı kalıyordu:

- belge para birimi seçildiğinde sayısal alanların tam dönüşmemesi
- modül kapalıyken ayrıntılı currency bilgisinin görünmeye devam etmesi
- operasyon görünümünde maliyet bağlamı sızıntısı
- sağ panelde tutarsız tahmini maliyet ve brüt fark alanları
- aynı kur aksiyonlarının iki farklı yerde tekrar etmesi

### 24.2 Düzeltilen belge currency hesaplama davranışı
Revize preview’da tek demo kur sözleşmesi kullanıldı:

- `1 USD = 43,20 TRY`
- `1 EUR = 43,60 TRY`

Belge para birimi TL, USD veya EUR seçildiğinde aşağıdaki alanlar tutarlı biçimde yeniden hesaplanır:

- satış birim fiyatları
- ürün satır toplamları
- baskı toplamları
- ara eleman toplamları
- KDV
- genel toplam

Kaynak tedarikçi fiyatı kendi orijinal para biriminde bırakıldı. Tahmini tenant base maliyeti ise yalnız finans görünümünde TL olarak korunur.

### 24.3 Modül kapalı güvenliği
`multi_currency` kapalıyken preview şu güvenli davranışa çekildi:

- belge para birimi TL’ye sabitlenir
- USD/EUR seçimi pasif olur
- source price gizlenir
- source currency ayrıntısı gizlenir
- applied rate ve rate tarihi gizlenir
- manuel kur aksiyonu gösterilmez
- kullanıcı yalnız güvenli TL teklif görünümünü görür

Finans rolü seçili olsa bile modül kapalı tenant görünümünde advanced currency detail açılmaz.

### 24.4 Operasyon maliyet gizliliği
Operasyon görünümünde bütün ürün tipleri için aşağıdakiler gizlendi:

- kaynak fiyat
- kaynak para birimi maliyet bağlamı
- tahmini alış maliyeti
- uygulanan kur
- kur tarihi
- kur kaynağı
- tedarikçi maliyet bilgisi
- tahmini maliyet ve brüt fark
- manuel kur actor/gerekçe

Özellikle TRY ürünlerde görünen kaynak fiyat ve tahmini alış maliyeti sızıntısı kaldırıldı.

### 24.5 Finans toplamı düzeltmesi
Sağ paneldeki `Tahmini maliyet` ve `Tahmini brüt fark` satırları tamamen kaldırıldı. Bu preview kârlılık ekranına dönüştürülmedi; yalnız ürün toplamı, baskı toplamı, ara eleman toplamı, KDV ve genel toplam bırakıldı.

### 24.6 Tek kur aksiyon alanı
Kur aksiyonları yalnız sağ sticky `Kur ve Para Birimi` alanında tutuldu. Ürün kalemleri başlığındaki tekrar kaldırıldı.

- güncel kur: yalnız durum bilgisi
- fallback/stale: `Kuru Güncelle` ve `Mevcut Kuru Koru`
- missing: `Kur bilgisini yenile`
- desteklenmeyen para birimi: aksiyon yerine pasif uyarı

### 24.7 Manuel kur pasif contract
Backend contract doğrulaması değişmedi:

- `can_use_manual_rate = false`

Bu nedenle manuel kur aktif production yeteneği gibi gösterilmedi. Scenario alanında yalnız planlı/pasif notu korundu; ana form içinde aktif `Manuel Kur Kullan` butonu gösterilmedi.

### 24.8 Türkçe terminoloji temizliği
Kullanıcı-facing yüzeyde teknik İngilizce ifadeler temizlendi:

- `Kur ve Para Birimi` kullanıldı
- `Desteklenmeyen para birimi` kullanıldı
- `Para birimi durumu` ifadesi Türkçeleştirildi
- teknik modül anahtarı ana formdan kaldırıldı

### 24.9 Kompakt currency detail yaklaşımı
Önceki 6 kolonlu geniş price strip kaldırıldı. Yeni yaklaşım:

- finans görünümünde tek satırlık kompakt açıklama şeridi
- küçük `Kur detayı` aç/kapat alanı
- operasyon görünümünde yalnız güvenli satış uyarısı
- ana satış birim fiyatı alanı her zaman görünür

Bu sayede form currency dashboard gibi değil, teklif formu gibi kalır.

### 24.10 Responsive iyileştirme
430px dar ekran kabul kriterine göre preview sadeleştirildi:

- sağ özet alta iner
- ürün ana alanları tek kolona düşebilir
- kur şeridi kompakt kalır
- 6 ayrı price-cell blok yaklaşımı kaldırıldı
- kur detayı kapalı başlar
- satış fiyatı ve satır toplamı görünür kalır

### 24.11 Production’a taşınmayacak preview-only parçalar
Aşağıdaki preview parçaları production’a aynen taşınmayacaktır:

- standalone topbar’ın aynen kopyalanması
- `Preview / multi_currency / teklif oluşturma` benzeri önizleme üst etiketi
- uzun preview açıklama metni
- senaryo panelinin teknik demo biçimi

Production layout zaten kendi sayfa başlığını üretiyorsa ikinci bir topbar/h1 kopyalanmayacaktır.

### 24.12 Kullanıcı onayı
Revize preview tamamlandı. Bu noktada hâlâ açık kullanıcı onayı beklenmektedir. Onay olmadan `10.16.4` implementation fazına geçilmemelidir.

## 25. 10.16.3-R2 Son Güvenlik ve Etkileşim Düzeltmesi
Preview üzerinde son güvenlik ve etkileşim sertleştirmesi yapıldı. Bu turda amaç yeni görünüm üretmek değil, mevcut revizyonu role/modül görünürlüğü ve kullanıcı etkileşimi açısından son kez güvenli hale getirmekti.

### 25.1 Kaynak currency badge sızıntısı
Ürün başlıklarındaki `USD kaynaklı`, `EUR kaynaklı`, `TRY kaynaklı` chip’leri artık yalnız `multi_currency açık + finans yetkili` görünümünde gösterilir. Modül kapalı veya operasyon görünümünde güvenli nötr chip kullanılır.

### 25.2 Kur paneli modül ve role göre güvenli hale getirildi
`Kur ve Para Birimi` paneli üç ayrı güvenli davranışa çekildi:

- `multi_currency` kapalı: yalnız TL paketi mesajı ve sade açıklama
- `multi_currency` açık + operasyon: yalnız güvenli satış mesajı
- `multi_currency` açık + finans: ayrıntılı kur durumu ve uygun aksiyonlar

Bu sayede modül kapalı veya operasyon görünümünde TCMB, kur oranı, rate date, fallback/stale detayı ve manuel kur planı görünmez.

### 25.3 Arama sonuçları missing ve unsupported durumuna bağlandı
Arama dropdown metadata’sı artık `rateState` ve görünürlük politikasına göre dinamik üretilir.

- missing: `Tahmini TL maliyet: Hesaplanamadı` ve `Kur bilgisi bulunamadı`
- desteklenmeyen para birimi: `Otomatik TL maliyeti hesaplanamadı`
- fallback/stale: kısa ve güvenli durum notu

Eski sabit tahmini TL maliyeti metinleri bu durumlarda artık gösterilmez.

### 25.4 Operasyon görünümü tekrar doğrulandı
Operasyon görünümünde kur ve maliyet bağlamı tekrar sıkılaştırıldı. Kaynak fiyat, kur, tahmini alış maliyeti, `Kur detayı` ve source currency ifadeleri görünmez tutuldu.

### 25.5 Fiyat input focus problemi
Satış fiyatı alanında her tuşta tüm ürün listesini yeniden render eden akış kaldırıldı. Manuel fiyat commit’i `change` ve `blur` aşamasına alındı. Böylece kullanıcı çok haneli fiyatı kesintisiz yazabilir ve focus kaybı yaşamaz.

### 25.6 Scenario panel varsayılan kapalı
`Önizleme Senaryosu` paneli artık sayfa ilk açıldığında kapalı başlar. Böylece ana teklif formu ilk bakışta öne çıkar. Bu panelin production’a taşınmayacağı kararı korunur.

### 25.7 Doğrulanan senaryo matrisi
Aşağıdaki kombinasyonlara göre preview davranışı gözden geçirildi:

1. modül açık + finans + TL + güncel
2. modül açık + finans + USD
3. modül açık + finans + EUR
4. modül açık + finans + missing
5. modül açık + finans + desteklenmeyen para birimi
6. modül açık + operasyon + güncel
7. modül açık + operasyon + stale
8. modül kapalı + finans
9. modül kapalı + operasyon
10. satış fiyatı alanına çok haneli giriş
11. belge para birimi değişiminde toplamların tutarlılığı

### 25.8 Production dosyaları
Bu turda da production dosyalarına dokunulmadı. Yalnız preview HTML ve preview raporu güncellendi.

### 25.9 Kullanıcı onayı
Preview son güvenlik ve etkileşim düzeltmesiyle tamamlandı. Hâlâ açık kullanıcı onayı beklenmektedir. Onay olmadan `Prodelya_V1 10.16.4 — Quote Currency Conversion and Snapshot Implementation` fazına geçilmemelidir.
