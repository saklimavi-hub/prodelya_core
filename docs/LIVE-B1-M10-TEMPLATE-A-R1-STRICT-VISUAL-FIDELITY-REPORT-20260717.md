# LIVE-B1-M10 Template A R1 Strict Visual Fidelity Report

Tarih: 2026-07-17
Durum: CODE/TEST PASS, MANUAL SCREENSHOT ACCEPTANCE PENDING
Kapsam: Local Product surfaces strict visual fidelity recovery only

## Scope
- Ürün Listem
- Tedarikçiden Stoğa Alınanlar
- Yeni Ürün Ekle / Düzenle
- Dosyadan Ürün Aktar
- Ürün Detayı compact gallery
- Sadece `pd-local-products*` ve `pd-catalog-detail*` scoped görsel katman
- Business logic, stock/reservation/procurement/CSV capability ve Promotion Quote davranışı değiştirilmedi

## Root Cause Audit
- Font/scale drift: production scoped block referans HTML yerine daha büyük başlıklar ve daha sert panel tonları kullanıyordu.
- Visual token drift: hero/card/shadow/radius/pastel satır yüzeyleri referansla uyuşmuyordu.
- User-facing technical leak: `own_product`, `projection`, `Operational`, `shared field catalog`, `legacy scalar` gibi implementation metinleri Blade içinde doğrudan görünüyordu.
- Product detail image size root cause: global admin CSS içindeki `.pd-main img:not(.pd-allow-large)` clamp nedeniyle detail gallery görselleri daralabiliyordu. Bu turda detail ana görseli ve thumb görselleri scoped `pd-allow-large` + explicit contain sizing ile güvene alındı.

## Applied Recovery
- Referans aileye uygun Arial/Helvetica 14px, pastel yüzeyler, 12px radius ve `0 8px 26px rgba(21, 34, 56, 0.06)` gölge tokenları local-product/catalog-detail scoped blokta uygulandı.
- Hero/card/sticky hiyerarşisi referans sıraya yaklaştırıldı.
- Ürün Listem, supplier stock, create/edit, import ve detail sayfalarındaki teknik İngilizce/implementation metinleri temizlendi.
- Ürün detayı ana galeri 330px yüksekliğe çıkarıldı.
- Ana görsel için `max-width/max-height 96%` ve `object-fit: contain` uygulandı.
- Thumbnail boyutu 54x54 olarak sabitlendi.
- Broken image fallback eklendi.
- Promotion Quote compact metadata regression lock korunarak yeniden test edildi.

## Targeted Tests
- PASS `LocalProductsTemplateContractTest`
- PASS `SupplierLocalStockTemplateContractTest`
- PASS `TenantCatalogProductDetailTemplateTest`
- PASS `LocalProductImportTemplateContractTest`
- PASS `LocalProductVisualFidelityContractTest`
- PASS `LocalProductCreateEditFieldParityTest`
- PASS `LocalProductImageUploadTest`
- PASS `PromotionQuoteWorkspaceJavascriptContractTest`
- PASS `PromotionQuoteCompactLocalStockLabelTest`
- PASS `PromotionQuoteMetadataHydrationParityTest`

## Broad Gates
- PASS `TenantCatalog` filter suite
- PASS `CatalogSearch` filter suite
- PASS `Stock` filter suite
- PASS `LocalProducts` filter suite
- PASS `PromotionQuote` filter suite
- PASS `AdminSmoke` filter suite
- PASS `php artisan view:cache`

## Manual Gate
Henüz KAPANMADI.

Beklenen kullanıcı doğrulaması:
- Referans HTML’lere görsel yakınlık screenshot/browser ile PASS
- Font boyutu ve yoğunluğu kabulü
- Pastel yüzey ve sticky/hero hiyerarşisi kabulü
- Product detail ana görsel ve thumbnail görünümü kabulü
- Teknik metin sızıntısı kalmadığı doğrulaması

## Notes
- Staging yapılmadı.
- Commit yapılmadı.
- Screenshot acceptance olmadan PASS/ready beyanı yapılmadı.
- Promotion Quote MANUAL PASS regression lock korundu.

## 2026-07-17 Addendum — Exact Variant Supplier-Local Recovery
- Supplier-local list artık product aggregation yerine exact `tenant_local_stocks` rows kullanıyor.
- Varianted product + legacy product-scope stock normal listeden hariç; sade warning summary gösteriliyor.
- Exact variant detail route bağlandı: `/admin/catalog/{product}/variants/{variant}`.
- ET-0506 live audit: operational truth hâlâ tek product-scope 2000 row; auto correction yapılmadı.
- Promotion Quote MANUAL PASS regression lock korunuyor.
