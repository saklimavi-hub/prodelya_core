# Product Data Hub Currency Propagation Raporu — 2026-07-10

## 1. Faz özeti
- Faz tipi: Backend / Data Propagation / API Contract
- UI, Blade, CSS, preview ve public surface değiştirilmedi.
- Mevcut `multi_currency` module key korundu; yeni module/feature key oluşturulmadı.
- Kaynak para birimi ve kaynak fiyat bilgisi raw, standard, tenant projection ve quote search/live payload zincirinde ayrı metadata olarak taşındı.

## 2. Currency Core checkpoint doğrulaması
- Feature commit doğrulandı: `66976a0` `currency: add canonical exchange rate core`
- Docs commit doğrulandı: `8b39cd0` `docs: add currency core implementation report`
- Currency Core dosyaları repo içinde mevcut bulundu.

## 3. Migration/local TCMB smoke durumu
- Local DB: SQLite
- Backup alındı: `database/backups/database-before-product-hub-currency-2026-07-10.sqlite`
- Uygulanan migration: `database/migrations/2026_07_10_120000_create_exchange_rates_table.php`
- Dry-run sonucu:
  - talep tarihi `2026-07-10`
  - kullanılan kur tarihi `2026-07-10`
  - kaynak `tcmb`
  - kur tipi `forex_selling`
  - yeni `2`, güncellenen `0`, değişmeyen `0`, hata `0`
- Normal sync sonucu:
  - talep tarihi `2026-07-10`
  - kullanılan kur tarihi `2026-07-10`
  - kaynak `tcmb`
  - kur tipi `forex_selling`
  - yeni `2`, güncellenen `0`, değişmeyen `0`, hata `0`
- Controlled smoke yalnız `exchange_rates` tablosuna yazacak şekilde çalıştırıldı; live Product Hub sync/projection komutları çalıştırılmadı.

## 4. Full suite baseline
- Önceki baseline: `1809 / 1823` geçti, `14` failure
- Bu baseline kaynağı: [CURRENCY-CORE-IMPLEMENTATION-RAPORU-20260710.md](/abs/path/C:/laragon/www/prodelya_core/docs/CURRENCY-CORE-IMPLEMENTATION-RAPORU-20260710.md:197)

## 5. Mevcut Product Hub field audit

| Katman | Mevcut Alan | Gerçek Anlam | Korunacak mı | Yeni Hedef Alan |
| --- | --- | --- | --- | --- |
| Raw product | `source_price` | mevcut supplier truth fiyat referansı | Evet | `normalized_payload.source_price` ile senkron |
| Raw product | `source_currency` | canonical supplier para birimi | Evet | `normalized_payload.source_currency` |
| Raw variant | `normalized_payload.list_price` | varyant fiyat referansı | Evet | `normalized_payload.source_price` |
| Raw variant | `normalized_payload.currency` | varyant/product/default currency | Evet | `normalized_payload.source_currency` |
| Standard product | `currency` | canonical supplier currency | Evet | aynı alan + `meta.price_snapshot.source_*` |
| Standard variant | `meta.price_snapshot` | varyant fiyat snapshotı | Evet | `source_price`, `source_currency`, `currency_status`, `currency_origin` |
| Tenant catalog | `display_price` | tenant satış/projection fiyatı | Evet | tenant base currency display value |
| Tenant catalog | `currency` | tenant base currency | Evet | source currency ayrı metadata |
| Search/live payload | `display_price`, `list_price`, `currency` | mevcut UI uyumlu güvenli fiyat alanları | Evet | yeni currency contract alanları ile genişletildi |

## 6. Supplier bazlı currency mapping kararları
- `AKDENIZ`: `kur` alanı canonical currency kaynağı olarak kullanıldı.
- `ILPEN`: `ParaBirimi` alanı canonical currency kaynağı olarak kullanıldı.
- `POZITRON_JSON`: profile-level default `USD` korundu.
- `ETKIN`: mevcut legacy/default davranış `TRY` olarak korundu.
- `YENI-NESIL`: mevcut profile/business mapping korunarak legacy/default `TRY` korundu; `dolar_fiyat` otomatik source truth yapılmadı.

## 7. Yeni alan/veri sözleşmesi
- Canonical metadata config’e eklendi:
  - `currency_status`: `resolved`, `missing`, `unsupported`
  - `currency_origin`: `variant_field`, `product_field`, `mapped_field`, `source_default`, `legacy_default`
  - `conversion_status`: `not_required`, `converted`, `missing_rate`, `unsupported_currency`, `missing_currency`, `missing_source_price`, `stale_rate`, `conversion_error`
- Zincirde taşınan temel contract:
  - `source_price`
  - `source_currency`
  - `source_list_price`
  - `source_net_price`
  - `source_purchase_price`
  - `currency_origin`
  - `currency_status`
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

## 8. Source currency çözümleme sırası
1. Variant row explicit currency field
2. Product row explicit currency field
3. Source/profile default currency
4. Kanıtlı legacy default
5. Hiçbiri yoksa `missing`

## 9. Raw propagation
- `RawProductStagingService` içinde product ve variant normalized payload’larına canonical `currency_contract` eklendi.
- Raw product mevcut kolonları yeniden yorumlanmadı.
- Raw variant için yeni kolon eklenmedi; metadata `normalized_payload` içinde taşındı.
- Converted TRY fiyat raw supplier fiyatının üzerine yazılmadı.

## 10. Standard propagation
- `StandardProductBuilderService` içinde standard product ve variant snapshot’larına source currency/source price metadata taşındı.
- Standard katmanda tenant-specific conversion üretilmedi.
- Global truth olarak supplier canonical currency korunurken tenant-specific conversion projection katmanına bırakıldı.

## 11. Tenant catalog conversion
- `TenantCatalogProjectionService` artık tenant base currency’yi `TenantCurrencyPolicyService` ile çözüyor.
- `display_price` / `sale_price` tenant base currency projection değeri olarak üretiliyor.
- Source currency ve source price metadata `meta.price_snapshot` altında ayrı tutuluyor.
- Kur bulunamazsa `conversion_status=missing_rate`, `base_price=null` ve source değerler korunuyor.

## 12. Lazy read/projection kararı
- HTTP request içinde TCMB çağrısı yapılmadı.
- Tenant catalog projection metadata içinde son hesaplanan currency snapshot tutuldu.
- Search/live payload mevcut snapshot üzerinden güvenli contract döndürüyor.
- Günlük kur değişiminde tüm tenant kataloglarına zorunlu toplu yazım tasarlanmadı.

## 13. Quote search payload contract
- `CatalogSearchController` top-level payload’a yeni currency contract alanlarını ekliyor.
- `price_snapshot` sanitize edilerek `purchase_price` ve yetkisiz supplier financial details browser’a sızdırılmıyor.
- Eski `display_price`, `list_price`, `currency`, `product_snapshot`, `stock_snapshot` yapısı korunuyor.

## 14. `multi_currency` modül bağlantısı
- Yalnız mevcut `multi_currency` key kullanıldı.
- `TenantAccessService::canAccessModule($tenant, 'multi_currency')` capability flag üretiminde kullanıldı.

## 15. Modül kapalı tenant davranışı
- Platform conversion metadata yine üretiliyor.
- Browser payload:
  - `base_price`
  - `base_currency`
  - `conversion_status`
  - `conversion_available`
  döndürebiliyor.
- `source_price`, `source_currency`, applied rate detayları gizleniyor.

## 16. Modül açık tenant davranışı
- Contract hazırlandı.
- `multi_currency` açık ve finance yetkili kullanıcı için:
  - `source_price`
  - `source_currency`
  - `applied_rate`
  - `rate_date`
  - `rate_source`
  - `rate_type`
  - stale/fallback flag’leri
  görünür olacak şekilde payload builder eklendi.

## 17. Permission ve tenant isolation
- Capability üretimi module access ile finance permission birlikte kontrol edilerek yapıldı.
- Finance detail visibility için kullanılan permission listesi config’e alındı.
- Tenant scope ve supplier access mevcut search/live akışında korunmaya devam ediyor.
- Public/customer-facing surface değiştirilmedi.

## 18. Delta/freshness ayrımı
- `SupplierSourceSyncService` delta apply akışında `source_price`, `source_currency`, `currency_origin`, `currency_status` sync edildi.
- Böylece raw/standard/catalog freshness zincirinde source price ile converted/base price ayrımı korunuyor.

## 19. Migration ve backfill kararı
- Bu faz için yeni Product Hub migration eklenmedi.
- Gerekli metadata mevcut kolonlar ve JSON snapshotlar içinde taşındı.
- Toplu backfill komutu tasarlanmadı ve çalıştırılmadı.

## 20. Değişen dosyalar
- `app/Services/ProductDataHub/ProductHubCurrencyService.php`
- `app/Services/ProductDataHub/RawProductStagingService.php`
- `app/Services/ProductDataHub/StandardProductBuilderService.php`
- `app/Services/ProductDataHub/SupplierSourceSyncService.php`
- `app/Services/ProductDataHub/TenantCatalogProjectionService.php`
- `app/Services/ProductDataHub/ProductHubLiveProductInfoService.php`
- `app/Http/Controllers/Admin/ProductHubLiveProductInfoController.php`
- `app/Http/Controllers/Admin/CatalogSearchController.php`
- `config/prodelya_product_data_hub.php`

## 21. Eklenen testler
- `tests/Unit/ProductDataHub/ProductHubSupplierCurrencyNormalizationTest.php`
- `tests/Feature/CatalogSearchCurrencyPayloadTest.php`

## 22. Hedefli test sonuçları
- `tests/Unit/ProductDataHub/ProductHubSupplierCurrencyNormalizationTest.php`
  - passed `2/2`
- `tests/Feature/CatalogSearchCurrencyPayloadTest.php`
  - passed `3/3`

## 23. Modül regresyonları
- `tests/Feature/TenantCatalogContextAndSupplierFilterTest.php`
  - passed `5/5`
- `tests/Feature/ProductDataHubCatalogQuoteFreshnessTest.php`
  - passed `1/1`
- `tests/Unit/Currency/*`
  - passed `12/12`
- `tests/Feature/CatalogSearchDisplayNameTest.php`
  - standalone run içinde verified
- `tests/Feature/ProductSelectionWarningDisplayTest.php`
  - ilgili search warning testleri standalone run içinde verified

## 24. Full suite baseline karşılaştırması
- Final full suite:
  - toplam `1828`
  - passed `1814`
  - failed `14`
- Fark:
  - yeni eklenen testler nedeniyle toplam ve passed sayısı arttı
  - failure sayısı artmadı
  - yeni failure oluşmadı
- Sonuç: baseline `14 failure` korunarak bu faz yeni kırılım üretmedi.

## 25. Manuel smoke
- Currency Core smoke tamamlandı.
- Fixture smoke hedefli testlerle doğrulandı:
  - TRY/TL legacy flow
  - USD → TRY conversion contract
  - yetkisiz kullanıcıda detail gizleme
  - finance + module gate açık olduğunda detail görünürlüğü

## 26. Canlı veriye dokunulmadığı
- Çalıştırılmadı:
  - `product-data-hub:sync-sources`
  - live supplier import
  - tenant catalog bulk projection command
  - live apply-price-stock komutları
- Normal TCMB sync yalnız `exchange_rates` tablosuna yazdı.

## 27. Feature commit hash’i
- Bu rapor yazıldığı anda henüz oluşturulmadı.

## 28. Kalan riskler
- `multi_currency` modülü config’te halen `planned`; production capability flag bugün çoğu tenantta doğal olarak `false` kalacaktır.
- Mevcut full suite içindeki 14 pre-existing failure bu faz dışında kalmaya devam ediyor.
- Bazı eski test aileleri çoklu dosya karma koşusunda seed bağımlılığı gösterebiliyor; standalone hedefli koşular güvenilir referans olarak kullanıldı.

## 29. Sonraki preview fazına hazır payload contract’ı
- Quote search ve live product info backend contract artık TL/USD/EUR preview fazında kullanılabilecek currency alanlarını taşıyor.
- Mevcut quote create UI bu alanları henüz tüketmek zorunda değil.

## 30. Kesinlikle yapılmayanlar
- Blade değişikliği yapılmadı.
- CSS değişikliği yapılmadı.
- HTML preview değiştirilmedi.
- Public/customer-facing payload genişletilmedi.
- Yeni module key oluşturulmadı.
- Live supplier sync ve live tenant projection komutları çalıştırılmadı.
