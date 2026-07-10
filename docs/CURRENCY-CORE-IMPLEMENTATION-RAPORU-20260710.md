# Currency Core Implementation Raporu — 2026-07-10

## Faz özeti

Bu fazda Prodelya için ortak para birimi çekirdeği eklendi. Kapsam yalnız backend/foundation ile sınırlandı; Blade, CSS, HTML preview, quote/order UI akışları ve Product Data Hub propagation alanlarına dokunulmadı.

## Mevcut currency audit sonucu

- `tenant_accounts.default_currency` tenant ana para birimi için mevcut temel alan olarak kullanılıyor.
- Kod tabanında legacy `TL` ve canonical `TRY` birlikte bulunuyor.
- `current_account_transactions.currency` ve bazı yeni operasyon alanları zaten `TRY` kullanıyor.
- `MoneyFormatter` katmanında `TL` ve `TRY` birlikte okunabiliyor.
- Product Data Hub, quote snapshot ve order/procurement taşıma alanlarında sonraki fazları ilgilendiren currency temas noktaları mevcut, ancak bu fazda değiştirilmedi.
- Mevcut kayıtlar için toplu backfill bu fazda güvenli görülmedi; compatibility normalizer yaklaşımı benimsendi.

## Canonicalization kararı

- Canonical servis katmanı currency kodları: `TRY`, `USD`, `EUR`
- Legacy eşlemeler:
  - `TL` -> `TRY`
  - `₺` -> `TRY`
  - `$`, `DOLAR` -> `USD`
  - `€`, `EURO`, `AVRO` -> `EUR`
- Tanınmayan currency değerleri sessiz fallback yerine `UnsupportedCurrencyException` üretir.

## Tenant base currency kararı

- Tenant ana para birimi çözümü için yeni alan eklenmedi.
- `TenantCurrencyPolicyService` mevcut `tenant_accounts.default_currency` alanını kullanır.
- `null` veya boş değerler güvenli varsayılan olarak `TRY` çözülür.
- Legacy `TL` değeri uyumluluk için `TRY` olarak yorumlanır.

## Eklenen model ve migration

- Model: `App\Models\ExchangeRate`
- Migration: `database/migrations/2026_07_10_120000_create_exchange_rates_table.php`
- Tablo: `exchange_rates`
- Ana alanlar: `provider`, `rate_type`, `source_currency`, `target_currency`, `rate_date`, `source_unit`, `rate`, `fetched_at`, `payload_hash`, `meta_json`
- Unique kural: provider + rate_type + pair + rate_date
- İndeksler: pair/date, provider/date, date

## Exchange rate veri sözleşmesi

- `ExchangeRateData`
- `ExchangeRateBatch`
- `ResolvedExchangeRate`
- `CurrencyConversionResult`
- `ManualExchangeRateOverrideData`

Bu DTO ailesi normalize edilmiş, snapshot’a uygun ve raw provider payload taşımayan veri sözleşmesini sağlar.

## TCMB provider yaklaşımı

- Sözleşme: `ExchangeRateProviderInterface`
- Implementasyon: `TcmbExchangeRateProvider`
- Özellikler:
  - allowlist kontrollü endpoint
  - timeout ve retry
  - XML parse sırasında `LIBXML_NONET`
  - raw XML loglamama
  - `USD` ve `EUR` kurlarını `TRY` hedefi için normalize etme
  - `source_unit` bilgisine göre 1 birim kur üretme
  - desteklenmeyen ekstra currency düğümlerini atlama
  - belirsiz `Date/Tarih` formatlarında fallback tarihe en yakın güvenli çözümleme

## Sync servisi

- Servis: `ExchangeRateSyncService`
- Davranış:
  - provider batch alma
  - config tabanlı lookback fallback
  - gelecekteki tarihe kontrolsüz gitmeme
  - idempotent upsert
  - `dry-run` transaction rollback
  - created/updated/unchanged/failed özetleme

## Artisan komutu

- Komut: `prodelya:currency-rates-sync`
- Seçenekler:
  - `--date`
  - `--provider`
  - `--rate-type`
  - `--dry-run`
  - `--lookback`
  - `--force`
- Çıktı Türkçe ve sade tutuldu.
- `failed_count > 0` olduğunda komut kontrollü biçimde failure döndürür.

## Scheduler yaklaşımı

- `routes/console.php` içine config kontrollü schedule kaydı eklendi.
- Komut kullanıcı HTTP request’inden bağımsız çalışır.
- `withoutOverlapping()` ile çakışma engellenir.

## Resolver

- Servis: `ExchangeRateResolver`
- Desteklenen akışlar:
  - same-currency identity
  - direct pair
  - reverse pair
  - `TRY` pivot ile cross conversion
  - stale/fallback metadata
  - hard-fail threshold sonrası controlled exception

## Conversion service

- Servis: `CurrencyConversionService`
- Tek nokta final rounding
- cross-currency legs korunur
- negatif ve sıfır tutarlar desteklenir
- manuel override DTO ile çalışabilir

## Snapshot contract

- Builder: `CurrencySnapshotBuilder`
- Sonuç nesnesi: `CurrencyConversionResult`
- Snapshot alanları sonraki quote/order snapshot fazına hazır şekilde korunur.
- Bu fazda hiçbir quote/order tablosuna yazım yapılmadı.

## Manual override contract

- `ManualExchangeRateOverrideData`
- reason zorunlu
- actor ve timestamp metadata’sı desteklenir
- resmi TCMB kaydını mutate etmez

## Rounding/precision

- Money precision: `2`
- Rate precision: `8`
- Calculation precision: `12`
- BCMath tabanlı hesap
- ara bacaklarda erken para yuvarlaması yapılmaz

## Backward compatibility

- Legacy `TL` okuması korunur.
- Mevcut quote/order/current-account kayıtları değiştirilmedi.
- Product Data Hub propagation yapılmadı.
- UI katmanında zorunlu `TRY` gösterimi başlatılmadı.
- Güvensiz toplu DB backfill sonraki fazlara bırakıldı.

## Tenant/security

- Global TCMB kurları tenant bağımsız tutuldu.
- Tenant base currency çözümü tenant-scope servis üzerinden kaldı.
- raw payload DB’ye yazılmadı.
- provider allowlist ve timeout aktif.

## Eklenen testler

### Unit

- `tests/Unit/Currency/CurrencyCodeNormalizerTest.php`
- `tests/Unit/Currency/TenantCurrencyPolicyServiceTest.php`
- `tests/Unit/Currency/TcmbExchangeRateProviderTest.php`

### Feature

- `tests/Feature/Currency/ExchangeRateModelTest.php`
- `tests/Feature/Currency/ExchangeRateResolverTest.php`
- `tests/Feature/Currency/CurrencyConversionServiceTest.php`
- `tests/Feature/Currency/SyncCurrencyRatesCommandTest.php`

### Fixture

- `tests/Fixtures/Currency/tcmb-20260710.xml`
- `tests/Fixtures/Currency/tcmb-20260709-unit100.xml`
- `tests/Fixtures/Currency/tcmb-malformed.xml`
- `tests/Fixtures/Currency/tcmb-missing-eur.xml`

## Hedefli test sonuçları

- Currency Core test paketi: `37/37` geçti
- Komut: `php artisan test tests/Unit/Currency tests/Feature/Currency`

## Regresyon sonuçları

Seçilen mevcut modül regresyonları geçti:

- `tests/Feature/ProductHubStorageDiskConfigTest.php`
- `tests/Feature/TenantCompanyProfileSettingsTest.php`
- `tests/Feature/ProductDataHubCatalogQuoteFreshnessTest.php`
- `tests/Feature/PromotionQuoteShowPolishTest.php`
- `tests/Feature/OrderIndexRealListTest.php`
- `tests/Feature/CurrentAccountCoreTest.php`
- `tests/Feature/AdminSmokeTest.php`
- `tests/Feature/FullOperationalFlowSmokeTest.php`

Sonuç: `82/82` geçti

## Full suite sonucu

- Komut: `php artisan test`
- Sonuç: `1809/1823` geçti, `14` test failed
- Gözlenen örnek failure:
  - `Tests\Feature\CompanyContactAddressActionsTest::test_company_detail_shows_active_contact_and_address_actions_with_clean_copy`
- Görünen failure içeriği Currency Core yerine mevcut UI/metin beklentileriyle ilişkilidir.
- Bu fazda full suite tamamen yeşil olmadığı için durum rapora açıkça işlendi.

## Manuel smoke

- Komut: `php artisan prodelya:currency-rates-sync --dry-run`
- Son durum:
  - kur tarihi doğru çözüldü
  - komut kontrollü çıktı üretti
  - aktif uygulama veritabanında `exchange_rates` migration’ı henüz `Pending` olduğu için kayıt işleme aşaması başarısız raporlandı
- `php artisan migrate:status --path=database/migrations/2026_07_10_120000_create_exchange_rates_table.php`
  - durum: `Pending`

## Değişen dosyalar

- `config/prodelya_currency.php`
- `app/Console/Commands/SyncCurrencyRatesCommand.php`
- `app/Contracts/Currency/ExchangeRateProviderInterface.php`
- `app/DTOs/Currency/*`
- `app/Exceptions/Currency/*`
- `app/Models/ExchangeRate.php`
- `app/Services/Currency/*`
- `database/migrations/2026_07_10_120000_create_exchange_rates_table.php`
- `routes/console.php`
- `tests/Unit/Currency/*`
- `tests/Feature/Currency/*`
- `tests/Fixtures/Currency/*`

## Commit hashleri

- Feature commit: `66976a0`
- Docs commit: `PENDING`

## Kalan riskler

- Aktif uygulama veritabanında yeni migration uygulanmadan gerçek sync yazımı doğrulanamaz.
- Full suite’de bu faz dışında kalan mevcut UI/metin kaynaklı failure’lar bulunuyor.
- Product Data Hub propagation ve quote/order snapshot entegrasyonu henüz yapılmadı.

## Sonraki faz için hazır contract’lar

- canonical currency normalization
- tenant base currency policy
- exchange rate persistence modeli
- TCMB provider adapter
- sync command/schedule
- resolver
- conversion result / snapshot DTO ailesi
- manual override DTO contract

## Kesinlikle yapılmayanlar

- Blade değişikliği
- CSS değişikliği
- HTML preview üretimi
- Product Data Hub propagation
- quote/order tablosu currency entegrasyonu
- finans ekranı dönüştürmesi
- mevcut kayıt backfill’i
- canlı request içinde TCMB çağrısı
