# PH-2C-B2 Canli Urun Bilgisi Search/Endpoint Tutarlilik ve Kompakt UI Raporu

Tarih: 2026-07-09

## Kapsam

Bu fazda teklif urun secimindeki katalog arama sonuclari ile `/admin/product-hub/live-product-info` endpoint davranisi hizalandi, tekrar eden uyari gorunumu temizlendi ve canli urun bilgisi karti daha kompakt hale getirildi.

## Tespit

- Katalog search akisi `is_active`, `visible_in_catalog`, `visible_in_quote` ve varyant gorunurlugune gore secilebilir urun donduruyordu.
- Canli urun endpoint'i ise `tenant_catalog_status === ready` disindaki durumlari topluca pasif sayiyordu.
- Sistem testleri ve mevcut projection akisi `category_pending` durumundaki urunlerin katalogda ve teklif aramasinda gorunur/sectirilebilir oldugunu zaten dogruluyordu.
- Bu nedenle `category_pending` gibi bloklayici olmayan bir durum search tarafinda secilebilir kalirken canli kartta "Bu urun su anda aktif degil." ve "Uygun degil" sonucu uretebiliyordu.
- UI tarafinda backend `warnings[]` dizisine eklenmis mesajlar, `stock_warning` ve `product_inactive_warning` alanlariyla ikinci kez chip olarak basilabildigi icin tekrarli uyari gorunumu olusuyordu.

## Uygulanan Cozum

### 1. Search/endpoint tutarliligi

Dosya: `app/Services/ProductDataHub/ProductHubLiveProductInfoService.php`

- `tenant_catalog_status` degerlendirmesi daraltildi.
- Asagidaki durumlar bloklayici kabul edildi: `inactive`, `hidden`, `local_archived`, `archived`, `deleted`, `disabled`.
- Bunun disindaki durumlar, ozellikle `category_pending`, teklif secimiyle uyumlu sekilde aktif kabul edildi.

Sonuc:

- Search sonucunda secilebilen `category_pending` urun, canli bilgi kartinda artik haksiz sekilde pasif gorunmuyor.

### 2. Tekrarlayan uyari temizligi

Dosya: `resources/views/admin/promotion-quotes/_form-workspace.blade.php`

- `buildLiveProductInfoWarnings(payload)` eklendi.
- Uyari chip'leri tek listede toplandi:
  - fark chip'leri
  - `stock_warning`
  - `product_inactive_warning`
  - backend `warnings[]`
- `Set` ile tekillestirme yapildi.

Sonuc:

- Ayni uyari metni artik panelde ikinci kez gorunmuyor.

### 3. Kompakt live info karti

Dosya: `resources/views/admin/promotion-quotes/_form-workspace.blade.php`

- Kart paddings/gap degerleri sikilastirildi.
- Ust satira mesaj + durum etiketi tasindi.
- Alt bolum 4 metrikli kompakt grid olarak yeniden duzenlendi:
  - Guncel fiyat
  - Guncel stok
  - Son guncelleme
  - Satis durumu
- Uyari bolumu daha kisa chip akisi ile korundu.

## Testler

Calistirildi ve basarili:

- `php artisan test --filter="ProductHubLiveProductInfoEndpointTest|PromotionQuoteLiveProductInfoUiTest|PromotionQuoteHasPrintFirstRowQuantityRegressionTest"`
  - 15 test, 107 assertion
- `php artisan test --filter="ProductHub|ProductDataHub|TenantCatalog|SupplierAccess"`
  - 365 test, 2418 assertion
- `php artisan test --filter="PublicQuoteApproval|OrderRevision|RepeatOrder"`
  - 66 test, 483 assertion

Ek guvence:

- `tests/Feature/ProductHubLiveProductInfoEndpointTest.php`
  - `category_pending` urunun canli endpoint'te aktif ve uygun kalmasi test edildi.
- `tests/Feature/PromotionQuoteLiveProductInfoUiTest.php`
  - kompakt kart siniflari ve tekillestirme helper'i render edilen sayfada dogrulandi.

## Manuel Smoke

Hedef sayfa:

- `/admin/promotion-quotes/create`

Senaryo:

- `ET-0506-S Plastik Kalem Siyah` urunu secildi.

Gozlem:

- Urun secimi basarili.
- Canli urun karti yandi.
- Kart kompakt gorunumde geldi.
- Durum `Teklife uygun` olarak gosterildi.
- Tekrarlayan "aktif degil" uyarisi gorulmedi.

Ekran goruntusu:

- `.tmp/ph2c-b2-live-info-smoke-20260709.png`

## Risk Notu

- Degisiklik DB semasina, migration akisina, fiyat snapshot mantigina veya guvenlik yetkilendirmesine dokunmadi.
- Davranis yalnizca search ile endpoint arasindaki aktiflik yorumunu hizalamak ve UI tekrarini temizlemek uzere sinirli tutuldu.
