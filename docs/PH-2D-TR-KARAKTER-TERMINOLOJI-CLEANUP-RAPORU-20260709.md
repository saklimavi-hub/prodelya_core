# PH-2D Türkçe Karakter ve Terminoloji Cleanup Raporu — 2026-07-09

## 1. Özet

- Bekleyen Kontroller ekranındaki kullanıcı-facing Türkçe karakter sorunları düzeltildi.
- Product Hub terminolojisi, kullanıcı arayüzünde daha doğal ve tutarlı hale getirildi.
- Değişen dosyalar:
  - `resources/views/super-admin/product-data-hub/product-panel.blade.php`
  - `tests/Feature/SuperAdminProductPanelTest.php`
  - `tests/Feature/ProductHubSellableTruthDiagnosticsTest.php`
  - `tests/Feature/ProductHubProductRoleAndCatalogVisibilityLabelTest.php`
- İş kuralı değişmedi.

## 2. Türkçe Karakter Düzeltmeleri

- `Supheli` → `Şüpheli`
- `Urun` → `Ürün`
- `Tumu` → `Tümü`
- `Akis` → `Akış`
- `Guncel` → `Güncel`
- `Gorunurluk` → `Görünürlük`
- `Yayin` → `Yayın`
- `Kaydi Ac` → `Kaydı Aç`
- `Detay Gor` → `Detay Gör`
- `Gelismis` → `Gelişmiş`

## 3. Terminoloji Düzeltmeleri

- `Freshness` → `Güncellik`
- `Projection` → `Satış Listesi`
- `Tenant` → `Abone Firma`
- `Review` → `Kontrol`
- `Truth katmanı` → `Kaynak katmanı`
- `Standard product` → `Standart ürün`
- `Variant` → `Varyant`
- `Supplier ürün` → `Tedarikçi ürünü`
- `Supplier varyant` → `Tedarikçi varyantı`

## 4. Teknik Detay Alanı

- Teknik terimler tamamen silinmedi.
- Gelişmiş teknik detay alanında, operasyonu destekleyen sınırlı teknik ifadeler korunuyor.
- Ana görünümde `Projection`, ASCII Türkçe kırıkları ve gereksiz teknik terimler temizlendi.

## 5. UI-3 Uyumu

- Font yaklaşımı korundu.
- Mevcut `.pd-*` namespace yapısı korundu.
- Yeni global class riski oluşturulmadı.

## 6. Hassas Veri Kontrolü

- Ana görünümde hassas veri sızıntısı tespit edilmedi.
- `group_code`, supplier internal id, alış fiyatı, maliyet, token benzeri alanlar gösterilmedi.

## 7. Test Sonuçları

- Hedefli Product Hub testleri güncellendi ve geçti.
- `php artisan test --filter="ProductHub|ProductDataHub|TenantCatalog|SupplierAccess|PromotionQuoteDetailCssNamespaceSmokeTest"` çalıştırıldı.
- `php artisan test --filter="PromotionQuote|PublicQuoteApproval|OrderRevision|RepeatOrder"` çalıştırıldı.

## 8. Manuel Smoke Sonucu

- Manuel browser smoke bu fazda çalıştırılmadı.
- Blade ve feature test çıktıları üzerinden Türkçe karakter ve terminoloji temizliği doğrulandı.

## 9. Kalan Riskler

- Gelişmiş teknik detay alanında bazı teknik kelimeler operasyon desteği için yaşamaya devam ediyor.
- İleride yeni kart veya durum etiketi eklenirse aynı Türkçe UI standardı korunmalı.

## 10. Sonraki Öneri

- PH-2C Teklif/Sipariş Canlı Ürün Bilgisi Endpoint

Bu cleanup tamamlanmadan PH-2C’ye geçilmemeliydi; kullanıcı-facing uyarı dili ve Türkçe karakter standardı artık daha güvenli bir tabana oturdu.
