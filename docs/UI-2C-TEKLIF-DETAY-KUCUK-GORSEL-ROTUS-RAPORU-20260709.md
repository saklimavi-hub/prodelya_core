# UI-2C Teklif Detay Kucuk Gorsel Rotus Raporu — 2026-07-09

## 1. Ozet

Bu fazda teklif detay ekraninda sadece kucuk gorsel rotuslar yapildi.

- Ne duzeltildi:
  - Urun ve baski satirlari biraz daha kompakt hale getirildi
  - Sag ozet paneli daha sakin ve daha az baskin gorunecek sekilde dengelendi
  - Alt aksiyon bari ve sekmeler hafifce kucultuldu
  - Baslik agirliklari, paddings ve satir yukseklikleri ince ayarlandi
- Degisen dosyalar:
  - `public/css/prodelya-admin.css`
  - `tests/Feature/PromotionQuoteDetailCssNamespaceSmokeTest.php`
- Yeni is kurali yazildi mi:
  - Hayir

`show.blade.php`, controller, route, DB, Product Hub endpoint, permission ve tenant guard mantigina dokunulmadi.

## 2. Gorsel Rotus Detaylari

Urun & Baski blogu:

- Grid kolonlari daraltildi ve satirlar daha kompakt hale getirildi.
- Urun satiri yuksekligi hafif azaldi.
- Baski satiri urun satirindan daha hafif arka plan ve daha sakin ayirici cizgi ile ayrildi.
- Urun adi agirligi `600` seviyesinde korundu, font boyu hafif kucultuldu.
- Meta ve not alani satir araligi sikistirildi.
- Toplam bandi ve satir basliklari daha kompakt hale getirildi.

Sag ozet paneli:

- Kart ic bosluklari azaltildi.
- Basliklarin agirligi dusuruldu.
- Ozet satirlari daha kisa dikey ritimle duzenlendi.
- Hizli aksiyon butonlari daha kontrollu boyuta cekildi.

Alt sticky bar:

- Buton boyutlari hafif kucultuldu.
- Metin agirligi azaltildi.
- Gorunur kapatma problemi olusturmadan daha sakin bir alt bant elde edildi.

Sekmeler:

- Sekme paddings ve gap daraltildi.
- Aktif sekme vurgusu korundu ama daha sakin hale getirildi.
- Sekmeler Akis Ozeti ve Urun & Baski blogundan sonra kalmaya devam etti.

Font ve bosluklar:

- Font standardi korunarak sadece boyut/agirlik/spacing ince ayari yapildi.
- Cok bold gorunen alanlar azaltildi.
- Gereksiz dikey bosluklar kisildi.

## 3. Referans HTML'e Yakinlik

Yaklasan alanlar:

- Akis Ozeti kartinin daha kompakt ritmi
- Urun & Baskı kalemleri blogunun daha sakin ve daha temiz satir yapisi
- Sag panelin referanstaki gibi ikincil rol hissettirmesi
- Alt aksiyon bandinin daha hafif gorunmesi
- Sekmelerin daha buton-benzeri ama daha sakin yapisi

Bilerek farkli kalan alanlar:

- Production admin layout ve mevcut `pd-btn` sistemi korundu
- Referanstaki `Inter` fontu production'a tasinmadi
- Mevcut buton/CTA metinleri buyuk olcude korundu

Farklarin nedeni:

- Bu faz pilot production ekraninda mevcut sistem primitive'lerini bozmadan polish yapmak icin tasarlandi
- Referans HTML bir gorsel hedef olarak kullanildi, birebir CSS kopyasi yapilmadi

## 4. CSS Namespace ve Font Standardi

Kullanilan namespace'ler:

- `.pd-quote-detail`
- `.pd-product-print-block`
- `.pd-summary`
- `.pd-sticky-bar`
- `.pd-btn`

Arial standardi:

- `public/css/prodelya-admin.css` icinde teklif detay namespace'i ve ilgili pd primitive'leri `Arial, Helvetica, sans-serif` zinciriyle uyumlu kaldi

Global class riski:

- Yeni global `.btn`, `.card`, `.chip`, `.tab`, `.modal` sinifi eklenmedi
- UI-2C eklemeleri namespaced alanlarda tutuldu

## 5. Hassas Veri Kontrolu

Manuel smoke gorunur UI taramasinda sizinti gorulmedi:

- `maliyet`: yok
- `alis fiyati`: yok
- `supplier raw`: yok
- `group_code`: yok
- `file_path`: yok
- `physical_path`: yok
- `token`: yok
- `api_key`: yok
- `secret`: yok
- `smtp_password`: yok

## 6. Test Sonuclari

Calistirilan komut:

```bash
php artisan test --filter="PromotionQuoteDetail|PromotionQuoteDetailCssNamespaceSmokeTest|PromotionQuote|PublicQuoteApproval"
```

Sonuc:

- `139 / 139` test gecti
- `1286` assertion gecti

Ek not:

- `TenantDomainSubdomainLocalSmokeTest::test_public_tracking_quote_and_graphic_links_remain_guest_accessible_without_sensitive_leakage` bu fazda ele alinmadi
- Bu onceki public tracking smoke problemi olarak kapsam disi birakildi

## 7. Manuel Smoke Sonucu

- Kullanilan URL:
  - `http://prodelya_core.test/admin/promotion-quotes/21`
- Kullanici/context:
  - `admin@saklimavi.local`
- `200` acildi mi:
  - Evet
- Yeni ekran goruntusu:
  - `.tmp/ui2c-manual-smoke-20260709.png`

Gorsel degerlendirme:

- UI-2B'ye gore ekran daha dengeli gorunuyor
- Urun ve baski satirlari daha kompakt
- Ilk urun satiri yuksekligi yaklasik `103.97px`
- Ilk baski satiri yuksekligi yaklasik `71.69px`
- Ilk index alani `23px`
- Ilk urun baslik fontu yaklasik `12.8px / 600`
- `Akis Ozeti` sekmelerin ustunde ve tekil kaldi
- Sag panel `sticky` olarak dengeli gorunuyor
- Alt bar gorunur ve icerik kapatma sorunu olusturmuyor

## 8. Kalan Riskler

- Lokal ortamda tenant subdomain DNS'i dogrudan cozulmedigi icin smoke yine local fallback ile kapatildi
- Production-benzeri birebir subdomain browser acilisi icin ayrica hosts/DNS eslesmesi gerekebilir
- Bundan sonraki fazlarda teklif detay ekranina tekrar tekrar lokal polish eklemek yerine daha sistematik bir UI standardi tanimlamak daha verimli olur

## 9. Sonraki Oneri

Net onerim:

- `UI-3 Design System Foundation`

Gerekce:

- Teklif detay ekrani artik pilot kalite seviyesine yeterince yaklasti
- Bundan sonraki surekli mikro-rotuslar yerine ortak kart, buton, sekme, ozet paneli ve sticky bar kurallarini design system seviyesine tasimak daha dogru olur
