# Emergency Database Data Source Loss Recovery Raporu — 2026-07-11

## 1. Yönetici özeti

- Runtime uygulama doğru SQLite development dosyasına bağlanıyordu: `database/database.sqlite`
- Sorun yanlış bağlantı değil, aktif development DB'nin demo düzeyine düşmüş/resetlenmiş olmasıydı
- Doğru veri, `database/backups/database-before-quote-currency-2026-07-10.sqlite` içinde sağlam bulundu
- Mevcut küçük DB önce timestamp'li olarak yedeklendi
- Ardından doğru backup aktif development DB'ye restore edildi
- Restore sonrası:
  - Abone Firma sayısı `1` -> `6`
  - Tenant katalog ürün sayısı `0` -> `18032`
  - `0506` ürün ailesi tekrar bulundu
  - SAKLImavi tenant supplier access kayıtları geri geldi
- Test runtime izolasyonu development DB'den ayrı kaldı
- Nihai karar: `DATA RESTORED — MANUAL CONTROL READY`

## 2. Başlangıç Git durumu

- `HEAD`: `2bd5d749abbdb7c3e61b6b8024d5150287df6aa8`
- Branch: `feature/master-restructure-phase-2-order-flow`
- Staged alan başlangıçta boşdu
- Kullanıcıya ait kirli worktree korunmuştur

## 3. Runtime database bağlantısı

- `.env` içindeki `DB_CONNECTION`: `sqlite`
- `config/database.php` default bağlantı: `env('DB_CONNECTION', 'sqlite')`
- Config cache: yok
- Laravel runtime `config('database.default')`: `sqlite`
- `DB::connection()->getDriverName()`: `sqlite`
- `DB::connection()->getDatabaseName()`: `C:\laragon\www\prodelya_core\database\database.sqlite`
- Web runtime development DB ile aynı SQLite dosyasını kullanıyordu

## 4. `.env` / `.env.testing` / phpunit ayrımı

- `.env.testing`: mevcut değil
- `phpunit.xml`:
  - `DB_CONNECTION=sqlite`
  - `DB_DATABASE=:memory:`
- Sonuç:
  - Web runtime: dosya tabanlı development SQLite
  - Test runtime: in-memory SQLite
  - Test runtime development DB'den ayrıdır

## 5. Bulunan DB ve backup adayları

Ana adaylar:

| Yol | Boyut | Durum |
| --- | ---: | --- |
| `database/database.sqlite` | 2,166,784 bytes | Aktif runtime DB, demo düzeyine düşmüş |
| `database/backups/database-before-quote-currency-2026-07-10.sqlite` | 560,685,056 bytes | En güçlü restore kaynağı |
| `database/backups/database-before-product-hub-currency-2026-07-10.sqlite` | 560,664,576 bytes | Güçlü ama daha eski aday |

Ek eski backup adayları da bulundu; ancak güncellik ve veri kapsamı açısından 2026-07-10 quote-currency öncesi backup en uygun kaynak olarak seçildi.

## 6. Aday DB karşılaştırma tablosu

| Ölçüt | Aktif DB | Quote Currency Öncesi Backup | Product Hub Currency Öncesi Backup |
| --- | ---: | ---: | ---: |
| Integrity | `ok` | `ok` | `ok` |
| Table sayısı | 111 | 111 | 110 |
| Tenant sayısı | 1 | 6 | 6 |
| Users | 1 | 8 | 8 |
| Companies | 2 | 16 | 16 |
| Suppliers | 4 | 6 | 6 |
| Orders | 0 | 30 | 30 |
| Order items | 0 | 40 | 40 |
| Order item prints | 0 | 39 | 39 |
| Tenant catalog products | 0 | 18032 | 18032 |
| Quote currency migration | 1 | 0 | 0 |
| `0506` izleri | yok | var | var |
| SAKLImavi tenant kaydı | yok | var | var |

## 7. Kök neden

`DEVELOPMENT_DATABASE_REPLACED_OR_RESET`

Gerekçeler:

- Runtime bağlantı doğru dosyaya işaret ediyordu
- Config cache yanlış DB'ye yönlendirmiyordu
- Aktif DB'de yalnız `Demo Şirketi` ve sıfır tenant katalog ürünü vardı
- Aynı proje içindeki 2026-07-10 backup dosyasında 6 tenant, 18032 tenant katalog ürünü, 30 sipariş ve `0506` ürün ailesi mevcut bulundu
- Aktif küçük DB'de quote currency migration vardı; restore kaynağında yoktu. Bu da küçük DB'nin sonradan migration uygulanmış, fakat gerçek veriden kopmuş ayrı bir development kopya olduğunu gösteriyor

## 8. Uygulanan yedekleme

Restore öncesi aktif DB şu dosyaya yedeklendi:

- `database/backups/database-before-emergency-recovery-20260711-142008.sqlite`

Kontroller:

- Restore kaynağı integrity: `ok`
- Restore öncesi aktif DB bağımsız kopyalandı
- Restore kaynağı SHA-256 hash'i restore edilen aktif DB ile eşleşti

## 9. Uygulanan kurtarma veya bağlantı düzeltmesi

Uygulanan işlem:

1. Aktif `database/database.sqlite` timestamp'li backup olarak kopyalandı
2. `database/backups/database-before-quote-currency-2026-07-10.sqlite` doğrulanmış restore kaynağı seçildi
3. Kaynak hash doğrulaması yapıldı
4. Backup kaynağı aktif `database/database.sqlite` üzerine kontrollü olarak kopyalandı
5. Final hash doğrulandı
6. `php artisan config:clear` çalıştırıldı

Not:

- `.env` değiştirilmedi
- MySQL/production bağlantısı bulunmadı
- Migration veya seeder çalıştırılmadı
- Quote Currency migration restore kaynağında yok; bu turda migration çalıştırılmadı

## 10. Abone Firma doğrulaması

Restore sonrası tenant sayısı: `6`

Bulunan tenant örnekleri:

- Demo Şirketi
- SAKLImavi
- Panel Reklam
- ABC Reklam Ajansı
- Demo Tenant Müşteri
- SAKLImavi Reklam Matbaa

## 11. Product Hub ve tenant katalog doğrulaması

- `tenant_catalog_products`: `18032`
- `tenant_supplier_access` içinde SAKLImavi (`tenant_account_id=2`) kayıt sayısı: `6`
- SAKLImavi için `visible_in_quote=1` ve `is_active=1` tenant katalog ürün sayısı: `238`
- Product Hub / tenant katalog veri tabanı katmanında geri gelmiştir

## 12. Teklif ürün arama doğrulaması

DB/query seviyesi doğrulama:

- `0506` ürün ailesi eşleşme sayısı: `7`
- Örnek eşleşmeler:
  - `SAKLIMAVI-ET-0506`
  - `PANEL-ET-0506`
  - `DEMO-ET-0506`
  - `SAKLIMAVI-YN-460506`

Not:

- Özellikle `ET-0506` kayıtları backup içinde mevcut
- Bazı `0506` kayıtları `visible_in_quote=0`; buna rağmen SAKLImavi için genel görünür teklif ürün sayısı `238` olduğu için katalog/tenant veri kaybı sorunu çözülmüştür

## 13. Test database izolasyonu

- PHPUnit runtime `:memory:` SQLite kullanıyor
- Development DB test runtime tarafından paylaşılmıyor
- Test öncesi development DB sayıları:
  - tenant: `6`
  - tenant katalog: `18032`
- Test sonrası development DB sayıları:
  - tenant: `6`
  - tenant katalog: `18032`
- Sonuç: dar testler development DB'yi değiştirmedi

## 14. HTTP/manual smoke sonuçları

Browser tabanlı manuel smoke bu turda çalıştırılmadı.

Yerine kullanılan kanıtlar:

- Runtime DB bağlantısı doğrudan Laravel içinde doğrulandı
- Tenant listesi DB seviyesinde doğrulandı
- `0506` ürün araması DB seviyesinde doğrulandı
- Tenant supplier access ve tenant katalog görünürlüğü DB seviyesinde doğrulandı
- Dar güvenli regresyon testleri geçti

Çalıştırılan dar testler:

1. `CatalogSearchCurrencyPayloadTest|ProductHubLiveProductInfoEndpointTest|PromotionQuoteCreateEditUiRegressionTest`
   - Sonuç: geçti
   - Tests: `20`
   - Assertions: `170`
2. `TenantSupplierAccessIdempotentTest|TenantSupplierAccessBackfillExistingActiveAccessTest`
   - Sonuç: geçti
   - Tests: `2`
   - Assertions: `11`

## 15. Değişen dosyalar

Bu turda uygulama koduna dokunulmadı.

Veri / dokümantasyon tarafında değişenler:

- `database/database.sqlite`
- `database/backups/database-before-emergency-recovery-20260711-142008.sqlite`
- `docs/EMERGENCY-DATABASE-DATA-SOURCE-LOSS-RECOVERY-RAPORU-20260711.md`

Geçici inceleme scriptleri `.tmp/` altında oluşturulup temizlendi.

## 16. Final Git durumu

- `HEAD`: değişmedi
- Branch: değişmedi
- Staged alan: boş
- Kullanıcıya ait kirli worktree korunmuştur
- Bu turda commit oluşturulmadı

## 17. Kalan riskler

- Restore kaynağında `2026_07_10_210000_add_quote_currency_snapshot_fields` migration kaydı yok
- Bu nedenle quote currency worktree değişiklikleriyle development DB şeması tekrar hizalanmadan ilgili akışlara devam edilmemeli
- Browser/manual ekran smoke henüz insan gözüyle doğrulanmadı
- `0506` ürün ailesi geri geldi; ancak hangi tenantta hangi ürünlerin `visible_in_quote=1` olması gerektiği kullanıcı tarafında ayrıca doğrulanmalı

## 18. Nihai karar

`DATA RESTORED — MANUAL CONTROL READY`

## 19. Sonraki kesin adım

1. Tarayıcıda şu iki ekranı manuel doğrula:
   - `/admin/super-admin/tenants`
   - Tenant admin `/admin/promotion-quotes/create`
2. Manuel doğrulama tamamlandıktan sonra quote currency worktree ile restore edilmiş DB arasında gerekli migration uyumu ayrı kontrollü fazda ele alınmalı
