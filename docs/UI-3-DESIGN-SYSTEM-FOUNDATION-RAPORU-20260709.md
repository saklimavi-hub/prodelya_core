# UI-3 Design System Foundation Raporu — 2026-07-09

## 1. Ozet

Bu fazda Prodelya admin paneli icin merkezi design foundation katmani hazirlandi.

- Ne yapildi:
  - `public/css/prodelya-admin.css` icine daha net semantic tokenlar eklendi
  - `pd-*` primitive component standardi geriye uyumlu alias'larla guclendirildi
  - modul namespace standardi netlestirildi
  - HTML preview -> Blade entegrasyon standardi yazili hale getirildi
- Degisen dosyalar:
  - `public/css/prodelya-admin.css`
  - `tests/Feature/PromotionQuoteDetailCssNamespaceSmokeTest.php`
  - `docs/UI-3-DESIGN-SYSTEM-FOUNDATION-20260709.md`
- Yeni is kurali yazildi mi:
  - Hayir

## 2. CSS Token Standardi

Eklenen / duzenlenen tokenlar:

- `--pd-font-family`
- `--pd-soft`
- `--pd-line-soft`
- `--pd-primary`
- `--pd-primary-soft`
- `--pd-success`
- `--pd-success-soft`
- `--pd-warning`
- `--pd-warning-soft`
- `--pd-danger`
- `--pd-danger-soft`
- `--pd-space-4`
- `--pd-space-6`
- `--pd-space-8`
- `--pd-space-10`
- `--pd-space-12`
- `--pd-space-16`
- `--pd-space-20`
- `--pd-font-size-10`
- `--pd-font-size-11`
- `--pd-font-size-12`
- `--pd-font-size-13`
- `--pd-font-size-14`
- `--pd-font-size-16`
- `--pd-font-size-18`
- `--pd-radius-sm`
- `--pd-radius-md`
- `--pd-radius-lg`
- `--pd-shadow-card`
- `--pd-shadow-modal`

Font standardi:

- Merkezi font standardi `Arial, Helvetica, sans-serif`
- Smoke kontrolunde:
  - `bodyFontFamily = Arial, Helvetica, sans-serif`
  - `productTitleFontFamily = Arial, Helvetica, sans-serif`

## 3. Primitive Component Standardi

Button:

- Mevcut `pd-btn-primary`, `pd-btn-light`, `pd-btn-success`, `pd-btn-danger` siniflari korunarak su alias standardi eklendi:
  - `.pd-btn--primary`
  - `.pd-btn--ghost`
  - `.pd-btn--success`
  - `.pd-btn--danger`

Card:

- `.pd-card` sistem genelindeki temel kart primitive'i olarak korunuyor

Chip:

- `.pd-chip` primitive'i eklendi
- su semantic varyantlar tanimlandi:
  - `.pd-chip--primary`
  - `.pd-chip--success`
  - `.pd-chip--warning`
  - `.pd-chip--danger`

Tabs:

- `.pd-tabs`
- `.pd-tabs__button`
- `.pd-tabs__button--active`

Form:

- `.pd-form`
- `.pd-form__field`

Summary:

- `.pd-summary__line`

Sticky bar:

- `.pd-sticky-bar` generic font / primitive giris noktasi olarak netlestirildi

Modal:

- `.pd-modal`
- `.pd-modal__head`
- `.pd-modal__body`
- `.pd-modal__actions`

## 4. Modul Namespace Standardi

Tanimlanan standart namespace'ler:

- Quote detail:
  - `.pd-quote-detail`
- Order flow:
  - `.pd-order-flow`
- Product Hub:
  - `.pd-product-hub`
- Cari / Finans:
  - `.pd-finance`
  - `.pd-current-account`
- Super Admin:
  - `.pd-super-admin`

Ek not:

- `pd-product-print-block` teklif detay icindeki urun/baski satir blogu icin korunmustur

## 5. HTML Preview -> Blade Standardi

Bundan sonra HTML onizlemeler su sekilde entegre edilecek:

- Gorsel kopya olarak degil, component mapping kaynagi olarak ele alinacak
- Once primitive sinif secilecek
- Sonra modul namespace eklenecek
- Preview'deki `Inter` gibi fontlar production'a birebir tasinmayacak
- Global `.btn`, `.card`, `.chip`, `.tab`, `.modal` gibi siniflar yeni production standardi olarak kullanilmayacak

Net kural:

- Yeni entegrasyonlar `pd-*` primitive + modul namespace kombinasyonuyla yazilacak

## 6. UI-2C Koruma Kontrolu

Teklif detay ekrani bozuldu mu:

- Hayir

Manuel smoke sonucu:

- URL:
  - `http://prodelya_core.test/admin/promotion-quotes/21`
- Kullanici/context:
  - `admin@saklimavi.local`
- Sonuc:
  - `200` acildi
- Ekran goruntusu:
  - `.tmp/ui3-design-system-smoke-20260709.png`

Korunan noktalar:

- `Akis Ozeti` sekmelerin ustunde kaldi
- `3` urun / `3` baski satiri korundu
- sag panel `sticky` kaldi
- alt sticky bar gorunur ve kapatma sorunu olusturmadi

## 7. CSS Global Risk Analizi

Mevcut analiz:

- Exact global `.btn`, `.card`, `.chip`, `.tab`, `.modal` selector'u bulunmadi
- Yeni global class acilmadi
- Ancak dosya icinde utility benzeri genel siniflar ve bazi legacy grup selector'leri var:
  - `.stat`
  - `.module`
  - `.tenant-card`
  - `.data-card`
  - `.filter-card`
  - `.table-wrap`
  - `.rounded`, `.border`, renk utility'leri

Kritik gozlem:

- `public/css/prodelya-admin.css` icinde `quote-*` bloğu cok buyuk ve yogun
- `pd-*` primitive katmani mevcut ama gecis donemi nedeniyle legacy ve ekran-ozel secicilerle birlikte yasiyor

Yogunluk notu:

- `quote-*` selector yogunlugu hala yuksek
- `pd-quote-detail` ekrani namespaced durumda, fakat uzun vadede daha fazla primitive'e dayandirilmasi faydali olur

## 8. Turkce UI Standardi

Dokumante edilen UI dili:

- `Dashboard` -> `Gosterge Paneli`
- `Tenant` -> `Abone Firma`
- `Request` -> `Talep`
- `Apply` -> `Uygula / Uygulandi`
- `Owner` -> `Panel Yetkilisi / Firma Yetkilisi`
- `Health` -> `Sistem Sagligi`
- `Action Queue` -> `Aksiyon Gerektirenler`

Product Data Hub:

- Teknik modul adi korunabilir
- Kullanici-facing isimlendirme daha sonra:
  - `Urun Veri Merkezi`
  - `Tedarikci Urun Havuzu`
  - `Urun Aktarim Merkezi`

## 9. Hassas Veri Kontrolu

UI-3 smoke gorunur UI taramasinda sizinti yok:

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

## 10. Test Sonuclari

Calistirilan komutlar:

```bash
php artisan test --filter="PromotionQuoteDetailCssNamespaceSmokeTest|PromotionQuoteDetail|AdminMenuVisibility"
php artisan test --filter="PromotionQuote|PublicQuoteApproval|OrderRevision|RepeatOrder"
```

Sonuclar:

- Ilk komut:
  - `36 / 36` test gecti
  - `292` assertion
- Ikinci komut:
  - `190 / 190` test gecti
  - `1610` assertion

Ek not:

- Bu turda timeout veya kapsam disi kalan hata gorulmedi

## 11. Kalan Riskler

- `public/css/prodelya-admin.css` dosyasi hala buyuk ve cok modullu; foundation tanimlandi ama fiziksel ayrisma henuz yapilmadi
- `quote-*` gecis katmani halen yogun kullaniliyor; uzun vadede daha fazla `pd-*` primitive'e tasinmasi gerekebilir
- Legacy utility / grouped selector alanlari ileride modulerlestirme ister
- Lokal smoke central-host fallback ile kapanabildigi icin production-benzeri subdomain smoke icin ayrica DNS/hosts eslesmesi gerekebilir

## 12. Sonraki Oneri

Net onerim:

- `PH-1 Product Hub sadeleştirme başlangıcı`

Gerekce:

- UI foundation artik yeterli tabani sagliyor
- Bir sonraki anlamli adim, Product Hub ile ilgili preview entegrasyonlarini bu yeni `pd-*` primitive ve namespace standardina gore daha temiz baslatmak olur
