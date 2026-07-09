# UI-3 Design System Foundation — 2026-07-09

## 1. Amaç

Bu dokuman Prodelya admin panelinde yeni HTML preview -> Blade entegrasyonlari icin temel tasarim standardini tanimlar.

Bu standardin amaci:

- gorsel kopya yerine sistemli entegrasyon yapmak
- `pd-*` namespace etrafinda primitive component seti kullanmak
- ortak token, font ve spacing kurallari belirlemek
- tenant / permission / hassas veri kurallarini UI seviyesinde de korumak

## 2. Font Standardi

Prodelya admin panelinde aksi acikca belirtilmedikce ana font ailesi:

`Arial, Helvetica, sans-serif`

Bu standart su alanlarda korunur:

- `body`
- `input`
- `select`
- `textarea`
- `button`
- `table`
- `chip / badge`
- `card`
- `sidebar`
- `modal`
- `sticky bar`
- `summary panel`

Preview dosyalarinda `Inter` kullanilsa bile production'a tasinmaz.

## 3. Token Listesi

Temel tokenlar:

- `--pd-font-family`
- `--pd-bg`
- `--pd-card`
- `--pd-soft`
- `--pd-line`
- `--pd-line-soft`
- `--pd-text`
- `--pd-muted`
- `--pd-primary`
- `--pd-primary-soft`
- `--pd-success`
- `--pd-success-soft`
- `--pd-warning`
- `--pd-warning-soft`
- `--pd-danger`
- `--pd-danger-soft`

Radius tokenlari:

- `--pd-radius-sm`
- `--pd-radius-md`
- `--pd-radius-lg`

Spacing tokenlari:

- `--pd-space-4`
- `--pd-space-6`
- `--pd-space-8`
- `--pd-space-10`
- `--pd-space-12`
- `--pd-space-16`
- `--pd-space-20`

Font-size tokenlari:

- `--pd-font-size-10`
- `--pd-font-size-11`
- `--pd-font-size-12`
- `--pd-font-size-13`
- `--pd-font-size-14`
- `--pd-font-size-16`
- `--pd-font-size-18`

Shadow tokenlari:

- `--pd-shadow-card`
- `--pd-shadow-modal`

Ek not:

- Eski `--pd-blue`, `--pd-green`, `--pd-amber`, `--pd-red` gibi tokenlar geriye uyumluluk icin korunabilir.
- Yeni entegrasyonlarda once semantic tokenlar tercih edilmelidir:
  - `--pd-primary`
  - `--pd-success`
  - `--pd-warning`
  - `--pd-danger`

## 4. Primitive Component Class Listesi

Button:

- `.pd-btn`
- `.pd-btn--primary`
- `.pd-btn--ghost`
- `.pd-btn--success`
- `.pd-btn--danger`

Card:

- `.pd-card`

Chip / Badge:

- `.pd-chip`
- `.pd-chip--primary`
- `.pd-chip--success`
- `.pd-chip--warning`
- `.pd-chip--danger`

Tabs:

- `.pd-tabs`
- `.pd-tabs__button`
- `.pd-tabs__button--active`

Table:

- `.pd-table`

Form:

- `.pd-form`
- `.pd-form__field`

Summary:

- `.pd-summary`
- `.pd-summary__line`

Sticky bar:

- `.pd-sticky-bar`

Modal:

- `.pd-modal`
- `.pd-modal__head`
- `.pd-modal__body`
- `.pd-modal__actions`

Kurallar:

- Eski class'lar bir anda silinmez.
- Yeni entegrasyonlar once `pd-*` primitive seti uzerinden kurulmalidir.
- `pd-*` primitive + modul namespace birlikte kullanilabilir.

Ornekler:

- `.pd-card.pd-quote-detail__summary-card`
- `.pd-btn.pd-btn--primary`
- `.pd-chip.pd-chip--warning`
- `.pd-tabs .pd-tabs__button.pd-tabs__button--active`

## 5. Modul Namespace Listesi

Teklif detay:

- `.pd-quote-detail`

Siparis akisi:

- `.pd-order-flow`

Urun & Baski satirlari:

- `.pd-product-print-block`

Product Hub:

- `.pd-product-hub`

Cari / Finans:

- `.pd-finance`
- `.pd-current-account`

Super Admin:

- `.pd-super-admin`

Kural:

- Primitive class + modul namespace birlikte kullanilabilir.
- Gorsel kararlar once primitive sinifta, modul-ozel farklar namespace altinda tanimlanir.

## 6. HTML Preview Olusturma Kurallari

Yeni preview olusturulurken:

- Global `.btn`, `.card`, `.chip`, `.tab`, `.modal` siniflari production hedefi olarak dusunulmez.
- Preview sadece yapisal ve gorsel referans olarak kullanilir.
- Font preview'de farkli olsa bile production standardi `Arial, Helvetica, sans-serif` olarak dusunulur.
- Preview component'leri su primitive setlere map edilecek sekilde tasarlanir:
  - button -> `.pd-btn`
  - card -> `.pd-card`
  - chip -> `.pd-chip`
  - tabs -> `.pd-tabs`
  - summary -> `.pd-summary`
  - modal -> `.pd-modal`

Preview icinde teknik veya hassas veri ornegi kullanilsa bile bunlar production'a birebir tasinmaz.

## 7. Blade'e Tasinirken Uyulacak Kurallar

- Blade entegrasyonu gorsel kopya olarak degil component mapping olarak yapilir.
- Once primitive class secilir.
- Sonra ilgili ekranin modul namespace'i eklenir.
- Gerekirse kucuk ekran-ozel hook siniflari eklenir.
- Controller veya is kurali katmanina UI gerekmedikce dokunulmaz.
- Tenant, permission ve finans gorunurlugu mantigi bozulmaz.

Ornek:

- Yanlis:
  - `.card .btn .chip`
- Dogru:
  - `.pd-card`
  - `.pd-btn.pd-btn--primary`
  - `.pd-chip.pd-chip--warning`
  - `.pd-quote-detail__summary-card`

## 8. Hassas Veri ve Permission Kurallari

Kullaniciya gorunen UI'da asagidaki alanlar render edilmez:

- maliyet
- alis fiyati
- supplier raw
- `group_code`
- `file_path`
- `physical_path`
- `token`
- `api_key`
- `secret`
- `smtp_password`

Ek kurallar:

- Finans gorunurlugu permission bazli korunur.
- Public link gosterilebilir ama ham token ayrica sergilenmez.
- Product Hub internal veya supplier raw alanlari customer-facing ya da genel admin akisinda ham haliyle gosterilmez.

## 9. CSS Global Risk Kurallari

Asagidaki global class isimlerinin yeni kullanimini acma:

- `.btn`
- `.card`
- `.chip`
- `.tab`
- `.modal`
- `.item-row`
- `.priority-block`

Eger eski sistemde benzer genel isimler varsa:

- mevcut kullanim bozulmadan kalabilir
- yeni CSS bu isimlerle genisletilmez
- yeni stil sadece `pd-*` namespace ile acilir

## 10. Turkce UI Standardi

Kullanici-facing dil standardi:

- `Dashboard` yerine `Gosterge Paneli`
- `Tenant` yerine `Abone Firma`
- `Request` yerine `Talep`
- `Apply` yerine `Uygula` / `Uygulandi`
- `Owner` yerine `Panel Yetkilisi` / `Firma Yetkilisi`
- `Health` yerine `Sistem Sagligi`
- `Action Queue` yerine `Aksiyon Gerektirenler`

Product Data Hub:

- Teknik modul adi olarak korunabilir
- Kullanici-facing menude ileride su karsiliklar degerlendirilebilir:
  - `Urun Veri Merkezi`
  - `Tedarikci Urun Havuzu`
  - `Urun Aktarim Merkezi`

## 11. Product Hub Icin UI Namespace Hazirligi

Ileride Product Hub sadeleştirme veya UI entegrasyonu yapilirken temel namespace:

- `.pd-product-hub`

Beklenen kullanim:

- `.pd-card.pd-product-hub__panel`
- `.pd-chip.pd-product-hub__status-chip`
- `.pd-summary.pd-product-hub__summary`

Kural:

- Product Hub canli endpoint, raw supplier payload veya internal teknik alanlar bu dokumanin kapsami degildir
- Bu dokuman yalniz UI foundation standardini tanimlar
