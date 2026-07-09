# UI-2B Teklif Detay Gorsel Esitleme Raporu

## 1. Ozet

Bu fazda `resources/views/admin/promotion-quotes/show.blade.php` ve `public/css/prodelya-admin.css` uzerinde sadece teklif detay ekraninin gorsel hiyerarsisini duzeltmeye yonelik bir esitleme uygulanmistir. Amac; onceki referans preview'e daha yakin, daha az tablo hissi veren, urun ve baski akislarini daha net ayiran bir detay ekranina gecmektir.

Bu kapsamda rollback, migration, veritabani degisikligi, controller refactor'u, Product Hub canli endpoint baglantisi veya servis davranisi degisikligi yapilmamistir.

## 2. Urun & Baski Blogu Duzeltmeleri

- `Akis Ozeti` sekme icinden alinip sol ana kolonun ust bolumune tasindi.
- `Urun & Baski` blogu, `Akis Ozeti` ile ayni ust akis icinde once render edilir hale getirildi.
- Urun ve baski satirlari icin yeni namespace'li blok yapisi eklendi:
  - `pd-product-print-block__row--product`
  - `pd-product-print-block__row--print`
- Satirlar daha kompakt hale getirildi; satir yuksekligi, bosluklar ve badge boyutlari azaltildi.
- Urun satirlarindaki tekrarli alt toplam blogu kaldirildi; toplamlar ana kolon akisinda tutuldu.
- Urun toplami ve baski toplami etiketleri daha acik ve daha duzenli hale getirildi.
- Baski satirlarindaki alt numaralandirma ve miktar/toplam akisi korunmustur.

## 3. Referans HTML'e Yakinlik

Referans kilidine uygun olarak ekran akisi su siraya yaklastirildi:

1. Sayfa basligi
2. Uyari / notice
3. Ust quote strip
4. Sol kolon: `Akis Ozeti` + `Urun & Baski` blogu, sag kolon: sticky ozet
5. Sekmeler

Ozellikle `Akis Ozeti` ve urun/baski blogunun sekmelerin ustune alinmasi, bu fazin ana referans esitleme kazanimidir.

## 4. CSS / Font Standardi

- Global font zinciri korunmustur; yeni bir font eklenmemistir.
- `Arial, Helvetica, sans-serif` mirasi bozulmamistir.
- Ek stiller `pd-quote-detail` ve `pd-product-print-block` namespace'i altinda tutulmustur.
- Global `btn`, `card`, `tab`, `modal` gibi genel siniflar eklenmemis, degisiklikler ekran bazinda sinirlanmistir.
- Daha ince tipografi ve daha hafif satir ritmi ile klasik tablo hissi azaltildi.

## 5. Hassas Veri Kontrolu

- Hassas alanlarin ekrana tasinmamasi korunmustur.
- Finans maskesi ve yetki tabanli toplam gorunurlugu davranisi degistirilmemistir.
- Product Hub canli veri veya teknik/snapshot alanlari bu fazda ekrana alinmamistir.

## 6. Test Sonuclari

Calistirilan komut:

```bash
php artisan test --filter="PromotionQuoteDetail|PromotionQuoteDetailCssNamespaceSmokeTest|PromotionQuote|PublicQuoteApproval"
```

Sonuc:

- `139` test gecti
- `1284` assertion gecti
- Ek UI-2B degisiklikleri bu filtre altinda regression olusturmadi

## 7. Manuel Smoke Sonucu

9 Temmuz 2026 tarihinde iki ayri yontem denendi:

1. In-app browser smoke
2. Yerel HTTP session smoke

In-app browser bu oturumda kullanilabilir degildi (`iab` secilemedi), bu nedenle gorsel tarayici smoke tamamlanamadi.

Yerel HTTP session ile `http://prodelya_core.test/login` uzerinden `admin@prodelya.local` kullanicisi ile oturum acildi ve `http://prodelya_core.test/admin/promotion-quotes/21` adresi denendi. Sonuc `403 Forbidden` ve `Bu teklife erisim yetkiniz yok` oldu.

Ardindan yerel veri kontrolunde `Order::find(21)` kaydinin:

- `tenant_id = null`
- `status = pending`
- `is_quote = null`

oldugu goruldu. Bu nedenle bu lokal verisetinde `/admin/promotion-quotes/21` icin tam gorsel smoke kapanamadi.

## 8. Kalan Riskler

- Gorsel smoke, istenen tam hedef kayit uzerinde kapanmadigi icin son piksel seviye dogrulama acik kalmistir.
- Lokalde gecerli tenant ve teklif fixture'i farkli bir kayit id'si ile calisiyorsa, ayni kontrol dogru host/tenant baglaminda tekrar edilmelidir.
- Degisiklikler namespace ile sinirli olsa da, ayni blade uzerindeki metin sirasina bagli yeni testler sonraki UI fazlarinda tekrar kosulmalidir.

## 9. Sonraki Oneri

Bir sonraki adim olarak sunlar onerilir:

1. Gecerli tenant baglaminda acilabilen bir teklif kaydi belirlenip tam browser smoke yeniden kosulsun.
2. UI-2B sonrasinda sadece gorsel dengeyi hedefleyen cok kucuk bir polish fazi ile sag sticky panel ve sekme alt bosluklari tekrar gozden gecirilsin.
