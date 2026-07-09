# UI-2B Manual Smoke Ready Raporu — 2026-07-09

## 1. Ozet

Onceki smoke tamamlanamadi cunku `/admin/promotion-quotes/21` kaydi yanlis kullanici ile acilmaya calisildi ve onceki hizli veri kontrolunde eski/yanlis alan adi uzerinden yorum yapildi.

- Onceki 403 denemesi `admin@prodelya.local` ile yapildi.
- Bu kullanici central/super-admin baglaminda kaldigi icin tenant quote detayina erisemedi.
- Onceki veri notunda `tenant_id` / `is_quote` gibi eski varsayimlar kullanildigi icin kayit yanlislikla uygunsuz sanildi.

Bu fazda UI kodu, CSS, Blade, controller, route veya permission mantigi degistirilmedi. Sadece read-only veri tespiti, dogru erisim context'i ve manuel smoke dogrulamasi yapildi.

## 2. Uygun Teklif Kaydi

Bulundu: Evet

- Teklif ID: `21`
- Teklif no: `TK-2026-0010`
- Tenant ID: `2`
- Tenant: `SAKLImavi`
- Tenant slug / subdomain: `saklimavi`
- Durum: `pending`
- Workflow status: `quote`
- Musteri onay durumu: `waiting`
- Urun kalem sayisi: `3`
- Baski islem sayisi: `3`

Mantiksal tenant host:

- `http://saklimavi.prodelya_core.test/admin/promotion-quotes/21`

Bu oturumda fiilen calisan lokal smoke URL:

- `http://prodelya_core.test/admin/promotion-quotes/21`

Dogru kullanici:

- `admin@saklimavi.local`

Not:

- Bu lokal ortamda tenant subdomain DNS'i dogrudan cozulmedigi icin browser smoke `prodelya_core.test` uzerinden tenant owner oturumu ile tamamlandi.
- `TenantResolver` local fallback'i tenant admin route'larinda authenticated tenant kullanicisini tenant baglami olarak resolve ettigi icin bu yol 200 verdi.

## 3. 403 Analizi

Onceki `403 Forbidden` bir guvenlik arizasi degil, dogru guard davranisidir.

- `admin@prodelya.local` ile `http://prodelya_core.test/admin/promotion-quotes/21` istegi `403` dondu.
- Bu kullanici ilgili tenant quote kaydinin dogru tenant admin context'inde degildi.
- Quote show aksiyonu `tenant_account_id` esitligini ve `isPromotion() && isQuote()` kosulunu kontrol ediyor.

Sonuc:

- Yanlis kullanici + yanlis context ile `403` normaldir.
- Guvenlik/tenant guard gevsetilmeden dogru tenant owner ile ayni kayit `200` acildi.

## 4. Manuel Browser Smoke Sonucu

Sayfa acildi: Evet

- Render URL: `http://prodelya_core.test/admin/promotion-quotes/21`
- Baslik: `TK-2026-0010`
- Ekran goruntusu: `.tmp/ui2b-manual-smoke-20260709.png`

Yapisal ve gorsel kontrol sonucu:

- `Akis Ozeti` sekmelerin ustunde: Evet
- `Akis Ozeti` tekrar sayisi: `1`
- `Urun & Baski Kalemleri` sol ana akis icinde ve one alinmis: Evet
- Urun satirlari eski buyuk tablo gibi degil: Evet, daha kompakt
- Baski satirlari urun satirlarindan hiyerarsik ayriliyor: Evet
- Satir numarasi kompakt: Evet, ilk badge olcumu yaklasik `23x23`
- Fontlar asiri buyuk/bold degil: Evet, urun baslik fontu `13px / 600`
- `3 urun / 3 baski` alani daha az yer kapliyor: Evet
- Sag ozet paneli dengeli: Evet
- Alt sticky bar icerigi kapatmiyor: Ekran goruntusunda kapatma sorunu gorulmedi

Olcumler:

- Urun satiri yuksekligi: yaklasik `109.33px`
- Baski satiri yuksekligi: yaklasik `74.55px`
- Sag panel pozisyonu: `sticky`

Gorsel gozlem:

- Sol kolon akisi referans hedefe yaklasmis durumda.
- Urun ve baski bloklari okunakli ve compact.
- Sag panel artisiz veya asiri agir gorunmuyor.
- Alt aksiyon bari sayfa sonunda dengeli duruyor.

## 5. Hassas Veri Kontrolu

Visible UI taramasinda sizinti gorulmedi:

- `maliyet`: yok
- `alis fiyati`: yok
- `supplier raw`: yok
- `group_code`: yok
- `file_path`: yok
- `api_key`: yok
- `secret`: yok
- `token`: gorunur metin olarak yok

Not:

- HTML icinde framework kaynakli gizli CSRF `_token` alanlari bulunabilir; bu kullaniciya gorunen hassas veri sizintisi olarak degerlendirilmedi.
- Public approval token veya supplier raw benzeri alanlar gorunur UI'da render edilmedi.

## 6. Test Sonuclari

Calistirilan komut:

```bash
php artisan test --filter="PromotionQuoteDetail|PromotionQuoteDetailCssNamespaceSmokeTest|PromotionQuote|PublicQuoteApproval"
```

Sonuc:

- `139 / 139` test gecti
- `1284` assertion gecti

Not:

- `TenantDomainSubdomainLocalSmokeTest::test_public_tracking_quote_and_graphic_links_remain_guest_accessible_without_sensitive_leakage` bu fazda ele alinmadi.
- Bu, onceki kalan public tracking smoke problemi olarak UI-2B kapsami disinda tutuldu.

## 7. Kalan Riskler

- Tenant subdomain host'u bu lokal oturumda DNS seviyesinde dogrudan cozulmedigi icin smoke fiilen central local host fallback'i ile kapatildi.
- Bu nedenle production-benzeri birebir subdomain browser acilisi ayrica ortam bazli DNS/hosts eslesmesi gerektirebilir.
- UI davranisi dogrulandi, ancak ileride daha yogun veri setleri ile ikinci bir gorsel regression smoke faydali olur.

## 8. Sonraki Oneri

Net onerim:

- `UI-2C kucuk gorsel rotus`

Gerekce:

- UI-2B yapisal olarak kapandi.
- Manual smoke artik dogru veri ve dogru context ile tamamlandi.
- Bundan sonraki en dusuk riskli adim, sadece kucuk bosluk/denge/polish iyilestirmeleriyle sayfayi finalize etmek olur.
