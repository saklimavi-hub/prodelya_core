# 1. Executive result

NOT VERIFIED — PRODUCT HUB TO QUOTE FRESHNESS BLOCKED

15 Temmuz 2026 itibarıyla canlı SQLite verisi üzerinde read-only doğrulama yapıldı. Raw -> standard -> tenant projection -> CatalogSearch -> ProductHub live-info zinciri TRY ve USD için kanıtlandı. Ancak iki kritik kapanış şartı henüz sağlanmadı:

- Seçilen gerçek ürünler için canlı `order_items.price_snapshot` örneği bulunmadı; save snapshot zinciri gerçek DB’de read-only olarak izlenemedi.
- Tenant projection raw/standard’dan geri kaldığında quote-facing `CatalogSearch` / `ProductHubLiveProductInfo` cevabında kullanıcı-facing `projection_outdated / stale_price / stale_stock` alarmı taşınmıyor; bu boşluk kod ve canlı response ile kanıtlandı.

Bu nedenle canlı quote gate bu turda açılmadı.

# 2. Selected TL exact variant

Quote-visible exact tenant variant bulunamadı.

Tenant `2` (`saklimavi`) içinde `tenant_catalog_product_variants` tablosunda `visible_in_quote = true` olan TRY kaynaklı quote-visible bir tenant varyant bulunamadı. Bu yüzden TRY doğrulaması gerçek quote-visible flat ürün üzerinden yapıldı:

- Tenant product: `tenant_catalog_products.id = 10235`
- SKU: `EL-KOD-35`
- Ürün: `EL-KOD-35 Duvar Takvimi`
- Tenant: `SAKLImavi`
- Quote visible: `true`
- Source currency: `TRY`
- Source price: `134`
- Visible stock: `500`
- `updated_at`: `2026-07-15 14:57:53`

Not: Bu bir veri/modelleme gerçeği olarak raporlandı; quote-visible TRY exact variant bulunamadığı için canlı TRY zinciri flat ürünle izlendi.

# 3. Selected USD exact variant

Gerçek exact variant bulundu ve quote aramasında doğrulandı:

- Tenant product: `tenant_catalog_products.id = 10060`
- Tenant variant: `tenant_catalog_product_variants.id = 32302`
- Standard product: `standard_products.id = 5159`
- Standard variant: `standard_product_variants.id = 16162`
- Raw variant: `supplier_product_variants_raw.id = 9035`
- Exact SKU: `PZ-CH60SY`
- Parent code: `PZ-CH60`
- Source currency: `USD`
- Source amount: `3.50`
- TRY display/base price: `164.12`
- Visible stock: `6500`
- Tenant variant `updated_at`: `2026-07-12 13:00:06`
- Standard variant `updated_at`: `2026-07-12 13:00:03`
- Raw variant `updated_at`: `2026-07-12 13:00:03`

# 4. Raw -> standard trace

## TRY (`EL-KOD-35`)

| Katman | Table / JSON path | ID | Exact SKU/variant | Fiyat | Para birimi | Kur | Stok | Updated at | Provenance |
|---|---|---:|---|---:|---|---:|---:|---|---|
| Raw | `supplier_products_raw.normalized_payload.list_price` | 5063 | `Kod-35` | 134.00 | TRY | identity | 500 | 2026-07-15 14:57:46 | `supplier_products_raw.source_price/source_currency` |
| Standard | `standard_products.meta.normalized_payload.list_price` | 5123 | `EL-KOD-35` | 134.00 | TRY | identity | 500 | 2026-07-15 14:57:46 | `source_type = raw_product` |
| Tenant | `tenant_catalog_products.meta.price_snapshot.*` | 10235 | `EL-KOD-35` | 134.00 | TRY | identity | 500 | 2026-07-15 14:57:53 | `meta.price_snapshot.source_price/source_currency` |

## USD (`PZ-CH60SY`)

| Katman | Table / JSON path | ID | Exact SKU/variant | Fiyat | Para birimi | Kur | Stok | Updated at | Provenance |
|---|---|---:|---|---:|---|---:|---:|---|---|
| Raw product | `supplier_products_raw.normalized_payload.list_price` | 5189 | `CH60` | 3.50 | USD | n/a | 6800 | 2026-07-12 13:00:03 | parent product raw |
| Raw variant | `supplier_product_variants_raw.normalized_payload.list_price` | 9035 | `CH60SY` | 3.50 | USD | n/a | 6500 | 2026-07-12 13:00:03 | exact variant raw |
| Standard product | `standard_products.meta.normalized_payload.list_price` | 5159 | `PZ-CH60` | 3.50 | USD | n/a | 6500 | 2026-07-12 13:00:14 | `source_type = raw_product` |
| Standard variant | `standard_product_variants.meta.price_snapshot.source_price` | 16162 | `CH60SY` | 3.50 | USD | n/a | 6500 | 2026-07-12 13:00:03 | exact standard variant |
| Tenant product | `tenant_catalog_products.meta.price_snapshot.currency_snapshot` | 10060 | `PZ-CH60` | 164.12 | TRY | 46.8927 | 6500 | 2026-07-12 13:00:06 | projected TRY product |
| Tenant variant | `tenant_catalog_product_variants.meta.price_snapshot.currency_snapshot` | 32302 | `PZ-CH60SY` | 164.12 | TRY | 46.8927 | 6500 | 2026-07-12 13:00:06 | exact tenant variant |

# 5. Standard -> tenant projection trace

## TRY

- `tenant_catalog_products.id = 10235.standard_product_id = 5123`
- `last_synced_at = 2026-07-15 14:57:53`
- `standard_products.updated_at = 2026-07-15 14:57:46`
- Tenant projection standard’dan yeni; stale alarmı gerekmiyor.

## USD

- `tenant_catalog_products.id = 10060.standard_product_id = 5159`
- `tenant_catalog_product_variants.id = 32302.standard_product_variant_id = 16162`
- `tenant_catalog_products.last_synced_at = 2026-07-12 13:00:06`
- `standard_products.updated_at = 2026-07-12 13:00:14`
- Burada product-level timestamp standard product’tan eski.
- Aynı anda quote-visible exact variant fiyat/stok değerleri eşleşiyor.
- Sonuç: projection freshness user-facing olarak özel alarm üretmiyor; yalnız timestamp farkı mevcut.

# 6. CatalogSearch attribution

Read-only canlı çağrılar `CatalogSearchController` üzerinden tinker ile yapıldı.

## TRY `q=EL-KOD-35`

Response doğrulandı:

- `tenant_catalog_product_id = 10235`
- `tenant_catalog_product_variant_id = null`
- `standard_product_id = 5123`
- `standard_product_variant_id = null`
- `quote_price_value = 134`
- `quote_currency = TRY`
- `quote_price_snapshot.source_price = 134`
- `quote_price_snapshot.source_currency = TRY`
- `visible_stock_quantity = 500`

## USD `q=PZ-CH60`

Response doğrulandı:

- `tenant_catalog_product_id = 10060`
- `tenant_catalog_product_variant_id = 32302`
- `standard_product_id = 5159`
- `standard_product_variant_id = 16162`
- `product_code = PZ-CH60SY`
- `quote_price_value = 164.12`
- `quote_price_snapshot.source_price = 3.5`
- `quote_price_snapshot.source_currency = USD`
- `quote_price_snapshot.sales_presentation.sales_rate = 46.8914`
- `visible_stock_quantity = 6500`

Sonuç:

- USD exact variant kimliği CatalogSearch’te korunuyor.
- Parent/sibling fallback kanıtı bulunmadı.
- Quote-visible contract USD exact variant için doğru.

Not:

- Top-level `source_price/source_currency` alanları canlı admin kullanıcı bağlamında da `null` döndü.
- Ancak quote UI için kritik veri `quote_price_snapshot.sales_presentation` içinde korunuyor.
- Bu davranış canlı tenant kullanıcı yetkisi `can_view_currency_details = false` olduğu için bilgi gizleme katmanından geliyor.

# 7. Quote UI attribution

`ProductHubLiveProductInfoService` canlı response doğrulaması:

## TRY `tenant_catalog_product_id=10235`

- `quote_price_value = 134`
- `quote_price_snapshot.source_price = 134`
- `quote_price_snapshot.source_currency = TRY`
- `current_stock = 500`
- `last_synced_at = 2026-07-15 14:57`

## USD `tenant_catalog_product_id=10060 & tenant_catalog_product_variant_id=32302`

- `display_code = PZ-CH60SY`
- `current_stock = 6500`
- `quote_price_value = 164.12`
- `quote_price_snapshot.source_price = 3.5`
- `quote_price_snapshot.source_currency = USD`
- `quote_price_snapshot.sales_presentation.sales_rate = 46.8914`
- `last_synced_at = 2026-07-12 13:00`

Sonuç:

- Quote UI’nin canlı meta panelini besleyen endpoint USD original currency/rate/TL bilgisini koruyor.
- Exact variant stock isolation korunuyor.

# 8. Quote save snapshot attribution

Canlı DB’de seçilen ürünler için mevcut save edilmiş satır aranmıştır:

- `order_items where tenant_catalog_product_id in (10235, 17899)` -> kayıt yok
- USD gerçek quote-visible ürün için `tenant_catalog_product_id = 10060 / tenant_catalog_product_variant_id = 32302` özel canlı order item örneği bu turda read-only bulunmadı.

Bu nedenle gerçek live DB’de seçilen ürünler için `order_items.price_snapshot` zinciri read-only doğrulanamadı.

Test katmanında mevcut güvence:

- `PromotionQuoteCurrencySnapshotTest` PASS
- `PromotionQuoteLiveProductInfoUiTest` PASS
- `PromotionQuoteSourceToTryRatePresentationTest` PASS

Ancak bunlar seçilen canlı ürünler için gerçek DB save örneği değildir. Manuel smoke ile save + DB karşılaştırması hâlâ gereklidir.

# 9. Stock isolation

USD exact variant `PZ-CH60SY` için:

- Raw variant stock: `6500`
- Standard variant stock: `6500`
- Tenant variant supplier stock: `6500`
- CatalogSearch `visible_stock_quantity = 6500`
- Live info `supplier_stock_quantity = 6500`, `fallback_stock_quantity = 6500`

Parent/sibling stok toplanmasına dair kanıt bulunmadı.

# 10. Freshness/stale behavior

Kanıtlanan boşluk:

- `ProductHubLiveProductInfoService` ve `CatalogSearchController` response’ları quote-facing seviyede yalnız `price_changed_since_snapshot` / `stock_changed_since_snapshot` karşılaştırmasını taşıyor.
- Raw/standard/tenant projection timestamp lag için kullanıcı-facing `projection_outdated`, `stale_price`, `stale_stock` alanı yok.
- Koddaki mevcut freshness mantığı ayrı bir servis içinde mevcut: `ProductHubFreshnessDiagnosticService`.
- Bu servis quote-facing endpoint’lere bağlanmamış.

Canlı kanıt:

- USD ürün `tenant_catalog_products.id = 10060` için `standard_products.updated_at = 2026-07-12 13:00:14`
- Aynı ürün `tenant_catalog_products.last_synced_at = 2026-07-12 13:00:06`
- Endpoint response buna rağmen `projection_outdated` benzeri bir alan döndürmüyor.

Sonuç:

- Sessiz stale acceptance riski user-facing seviyede kapanmamış.
- Bu tek başına live gate’i kapalı tutmak için yeterli blocker’dır.

# 11. Historical immutability

Canlı selected ürünler üzerinde mevcut quote snapshot örneği bulunmadığı için real DB historical immutability read-only doğrulanamadı.

Mevcut kod incelemesi:

- `PromotionQuoteController::resolveCatalogItemPayload()` gönderilen `price_snapshot` ve `product_snapshot` alanlarını yeniden silent overwrite etmiyor.
- Save sırasında `OrderItem.price_snapshot = array_merge(catalogPayload.price_snapshot, pricingSnapshot)` kullanılıyor.
- Bu sözleşme test katmanında korunuyor; ancak selected canlı ürünler için gerçek save + sonraki catalog change + reopen akışı bu turda yapılmadı.

# 12. Tenant security

Read-only kanıtlar:

- `tenant_supplier_access` içinde tenant `2` için supplier `5` ve `6` erişimleri açık.
- CatalogSearch tenant scope doğru çalışıyor; seçilen USD response exact tenant variant kimliği taşıyor.
- `ProductHubLiveProductInfoEndpointTest` PASS.
- Cross-tenant raw leak ya da hidden supplier bypass kanıtı bulunmadı.

# 13. Code fixes

Bu turda production kodu değiştirilmedi.

Neden:

- Canlı zincirin büyük kısmı doğru çalışıyor.
- Tek kanıtlanmış quote-facing boşluk freshness alarmının taşınmaması.
- Bu boşluk için minimal entegrasyon güvenli biçimde yapılabilir; fakat manuel smoke yapılmadan live gate açılmaz.

Önerilen hedefli patch alanları:

- `ProductHubLiveProductInfoService`
- `CatalogSearchController`
- quote workspace warning label resolver
- dedicated freshness attribution tests

# 14. Tests

Çalıştırılan hedefli testler:

- `php artisan test --filter=CatalogSearchCurrencyPayloadTest --stop-on-failure` -> PASS (`3 test, 21 assertion`)
- `php artisan test --filter=ProductHubLiveProductInfoEndpointTest --stop-on-failure` -> PASS (`13 test, 57 assertion`)
- `php artisan test --filter=PromotionQuoteSourceToTryRatePresentationTest --stop-on-failure` -> PASS (`1 test, 5 assertion`)
- `php artisan test --filter=PromotionQuoteCurrencySnapshotTest --stop-on-failure` -> PASS (`5 test, 18 assertion`)
- `php artisan test --filter=ProductDataHubCatalogQuoteFreshnessTest --stop-on-failure` -> PASS (`1 test, 23 assertion`)
- `php artisan test --filter=PromotionQuoteLiveProductInfoUiTest --stop-on-failure` -> PASS (`2 test, 78 assertion`)

Toplam:

- PASS `25 test`
- PASS `202 assertion`
- Hedefli suite failure: `0`

Broad testler bu turda çalıştırılmadı.

# 15. Manual smoke

Manual smoke bu turda yapılmadı.

Bekleyen checklist:

- TRY flat ürün seçimi (`EL-KOD-35`) -> quote row -> save -> DB snapshot karşılaştırma
- USD exact variant seçimi (`PZ-CH60SY`) -> quote row -> save -> DB snapshot karşılaştırma
- CatalogSearch ve live-info aynı tenant variant kimliği dönüyor mu
- Save snapshot `source_price/source_currency/rate/TL` alanlarını koruyor mu
- Historical quote snapshot live catalog değişince sabit kalıyor mu
- Freshness stale/projection lag uyarısı kullanıcı-facing olarak görünür mü

# 16. Data mutation status

- Canlı DB üzerinde write yapılmadı.
- Bulk sync/apply/project çalıştırılmadı.
- Staging yapılmadı.
- Commit yapılmadı.

# 17. Worktree/staging/commit

- Worktree zaten dirty.
- Bu turda yeni stage / commit yapılmadı.
- `git diff --cached --stat` başlangıçta boştı; bu tur sonunda da staging yapılmadı.

# 18. Live quote gate

NOT VERIFIED — PRODUCT HUB TO QUOTE FRESHNESS BLOCKED

Blocker özeti:

1. Seçilen gerçek ürünler için canlı `order_items.price_snapshot` kaydı read-only bulunamadı.
2. Quote-facing response’larda `projection_outdated / stale_price / stale_stock` alarmı taşınmıyor.
3. Manuel browser smoke henüz tamamlanmadı.
