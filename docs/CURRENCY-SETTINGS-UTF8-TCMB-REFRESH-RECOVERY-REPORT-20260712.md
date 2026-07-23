# Currency Settings UTF-8 + TCMB Refresh Recovery Report - 2026-07-12

## Özet

Bu çalışma `/admin/settings/currency` ekranındaki iki ayrı problemi hedefledi:

1. Kaynak literal seviyesinde bozulmuş Türkçe metinleri düzeltmek.
2. `Kurları Güncelle` aksiyonunda generic hatanın arkasındaki gerçek exception zincirini bulup güvenli biçimde toparlamak.

`base_currency` persistence davranışı korunmuştur. SSL doğrulaması kapatılmamıştır. Staging ve commit yapılmamıştır.

## Türkçe karakter kök nedeni

Kök neden browser charset değil, doğrudan source literal bozulmasıydı. Ön incelemede `resources/views/admin/settings/currency.blade.php` içinde kullanıcıya görünen bozuk metinler tespit edildi:

- yaklaşık satır 149: `Kur y�netimi merkeziniz`
- yaklaşık satır 189: `Varsay�lan Teklif Para Birimi`
- yaklaşık satır 200: `Kullan�labilir Teklif Para Birimleri`

Ayrıca aynı dosyada çok sayıda ASCII fallback metni vardı:

- `Kurlari Guncelle`
- `Yonetim Modu`
- `Kur Kaynagi`
- `Doviz Satis`
- `Son Kayitli Kurlar`

Bu nedenle çözüm, layout/meta yerine doğrudan Blade literal ve kullanıcı-facing servis etiketlerini UTF-8 Türkçe ile düzeltmek oldu.

## Refresh gerçek exception kanıtı

### Route ve zincir

- Route: `POST /admin/settings/currency/refresh-rates`
- Route name: `admin.settings.currency.refresh-rates`
- Controller: `App\Http\Controllers\Admin\SettingsController::refreshCurrencyRates()`
- Tenant service: `App\Services\Currency\TenantCurrencySettingsService::refreshRates()`
- Sync service: `App\Services\Currency\ExchangeRateSyncService::sync()`
- Provider: `App\Services\Currency\TcmbExchangeRateProvider::fetchForDate()`

### Ayrı kanıt 1: gerçek root exception

Tanılama scriptleri ile aşağıdaki sonuçlar doğrulandı:

- `.tmp/tcmb_refresh_diag.php`
- `.tmp/tcmb_provider_diag.php`

Bulgular:

- `2026-07-12` için sync sonucu: `App\Exceptions\Currency\ExchangeRateNotFoundException`
- güvenli özet mesaj: `Kur bulunamadı: USD/TRY 2026-07-12 (forex_selling)`
- provider seviyesi kanıt:
  - `2026-07-12` -> `ExchangeRateProviderException`, reason `rate_date_unavailable`
  - `2026-07-11` -> `ExchangeRateProviderException`, reason `rate_date_unavailable`
  - `2026-07-10` -> başarı, `resolved_rate_date=2026-07-10`, `count=2`

### Ayrı kanıt 2: neden generic save/refresh hatasına düşüyordu

`TenantCurrencySettingsService::refreshRates()` içinde `ExchangeRateSyncService::sync()` yanlış argüman sırasıyla çağrılıyordu. Bu yüzden varsayılan fallback lookback devre dışı kalıp fiilen `0` oluyordu:

- bozuk çağrı: `sync(date, provider, type, null, false)`
- etkisi: `lookback = 0`
- sonuç: hafta sonu veya yayınlanmamış günde fallback araması yapılamadan `ExchangeRateNotFoundException`

Düzeltme sonrası çağrı:

- `sync(date, provider, type, false, null, false)`

Bu değişiklik TCMB tarih fallback davranışını yeniden açtı.

## SSL / DNS / timeout / XML / DB sınıflandırması

- SSL doğrulaması kapatıldı mı: Hayır
- `verify => false` / `CURLOPT_SSL_VERIFYPEER=false` eklendi mi: Hayır
- DNS/CA/SSL mevcut refresh kök nedeni mi: Hayır, çünkü provider `2026-07-10` tarihini başarıyla çekebildi
- HTTP/timeout kök nedeni mi: Kanıtlanmadı
- XML parse hata sınıfı destekleniyor mu: Evet, controller artık `invalid_xml` / `missing_currency` reason’larını güvenli mesajla sınıflandırıyor
- DB duplicate/upsert riski: hedefli testte idempotent olarak doğrulandı, duplicate oluşmadı

Ek ortam notu:

- `php -i` içinde `curl.cainfo => C:\laragon\etc\ssl\cacert.pem`
- Ancak bu vaka için gerçek blokaj SSL değil, weekend fallback regresyonuydu.

## Düzeltilen dosyalar

- `resources/views/admin/settings/currency.blade.php`
- `app/Services/Currency/TenantCurrencySettingsService.php`
- `app/Http/Controllers/Admin/SettingsController.php`
- `tests/Feature/TenantCurrencySettingsTest.php`

## Uygulanan düzeltmeler

### UTF-8 / tasarım

- Currency Blade görünümü daha sistem uyumlu kart ve özet yapısıyla yeniden yazıldı.
- Save form sözleşmesi korundu:
  - action: `admin.settings.currency.update`
  - method spoofing: `PUT`
  - `@csrf`
  - `@method('PUT')`
- Refresh formu ayrı `POST` form olarak bırakıldı.
- Nested form bırakılmadı.
- Buton type’ları açık bırakıldı.
- Kullanıcı-facing terminoloji `Abone Firma` ile uyumlandırıldı.

### Refresh ve hata yönetimi

- `refreshRates()` argüman sırası düzeltildi.
- Controller tarafında gerçek exception sınıfına göre güvenli hata mesajı üretildi.
- Gerçek hata artık loglanıyor:
  - `tenant_id`
  - `provider`
  - `rate_type`
  - `requested_date`
  - `exception_class`
  - `exception_message`
  - uygunsa `reason`, `status`, `provider_date`
- Hassas veri, token, full response dump veya stack trace kullanıcıya gösterilmedi.

## Test komutları ve gerçek sonuçlar

### 1. TenantCurrencySettingsTest

Komut:

```powershell
php artisan test tests/Feature/TenantCurrencySettingsTest.php --stop-on-failure
```

Sonuç:

- `passed`
- `tests=18`
- `assertions=99`

Kapsanan ana senaryolar:

- doğru Türkçe metinler response içinde var
- bozuk kalıplar response içinde yok
- save form PUT sözleşmesi doğru
- refresh form ayrı POST
- nested form yok
- `base_currency` tenant default currency alanına persist oluyor
- default quote currency persist oluyor
- enabled quote currencies persist oluyor
- refresh success fallback ile `2026-07-10` tarihine düşüyor
- refresh idempotent, duplicate üretmiyor
- refresh failure mevcut kurları koruyor
- teknik detay sızıntısı yok
- module disabled davranışı korunuyor
- cross-tenant erişim yok

### 2. TenantSettings regresyon

Komut:

```powershell
php artisan test --filter=TenantSettings --stop-on-failure
```

Sonuç:

- `passed`
- `tests=6`
- `assertions=73`

### 3. AdminSmokeTest

Komut:

```powershell
php artisan test --filter=AdminSmokeTest --stop-on-failure
```

Sonuç:

- `passed`
- `tests=58`
- `assertions=213`

## Log kanıtı

Güncel test koşusunda parse-failure sınıflandırmasının gerçekten loglandığı doğrulandı:

- log event: `testing.ERROR: Currency refresh failed.`
- exception class: `App\Exceptions\Currency\ExchangeRateProviderException`
- reason: `invalid_xml`
- message: `TCMB XML verisi ayrıştırılamadı.`

Bu, kullanıcı-facing mesajın generic kalırken gerçek root cause’un logda tutulduğunu kanıtlar.

## DB mutation özeti

- Refresh success senaryosunda `exchange_rates` tablosunda USD/TRY ve EUR/TRY için 2 kayıt üretildi/güncellendi.
- Aynı refresh ikinci kez çalıştırıldığında duplicate oluşmadı.
- Failure senaryosunda mevcut 2 kayıt korundu, yarım kayıt oluşmadı.

## Manuel smoke sonucu

Promptta istenen manuel browser smoke bu oturumda tamamlanmadı.

Durum:

- bozuk Türkçe karakterlerin manuel tarayıcı doğrulaması: beklemede
- save persistence’ın manuel USD/EUR round-trip doğrulaması: beklemede
- `Kurları Güncelle` butonunun gerçek browser smoke’u: beklemede

Not:

- Kod ve hedefli testler temiz geçti.
- Buna rağmen prompt stop-line gereği manuel refresh smoke geçmeden `VERIFIED` veya `COMMIT READY` denmedi.

## Staged alan durumu

- `git status --short` çıktısında çok sayıda ilgisiz değiştirilmiş/untracked dosya var.
- Bu çalışma kapsamında staging yapılmadı.
- Commit yapılmadı.

## Commit durumu

- Staging: Hayır
- Commit: Hayır

## Carryover kapısı

- `Order / Procurement Currency Carryover` kararı verilmedi.
- Manuel refresh smoke geçmeden değerlendirme yapılmamalı.

## Konsol özeti

- A) Türkçe karakter kök nedeni: Source literal bozulması / mojibake
- B) Bozuk metinler düzeltildi mi: Evet, kod ve test düzeyinde
- C) Kaynak dosyalar UTF-8 mi: Evet, düzenlenen dosyalar UTF-8 olarak yeniden yazıldı
- D) Refresh route/method: `POST /admin/settings/currency/refresh-rates`
- E) Gerçek refresh exception sınıfı: `App\Exceptions\Currency\ExchangeRateNotFoundException` ve provider seviyesinde `App\Exceptions\Currency\ExchangeRateProviderException`
- F) Kök neden kategorisi: Weekend / unavailable date fallback regresyonu
- G) SSL doğrulaması kapatıldı mı: Hayır
- H) Başarısızlıkta eski kurlar korundu mu: Evet, hedefli test ile doğrulandı
- I) Başarılı refresh manuel doğrulandı mı: Hayır
- J) Base currency persistence korundu mu: Evet
- K) TenantCurrencySettingsTest: Passed (18 test, 99 assertion)
- L) TenantSettings regresyon: Passed (6 test, 73 assertion)
- M) AdminSmokeTest: Passed (58 test, 213 assertion)
- N) Staging: Yok
- O) Commit: Yok
- P) Final karar: `NOT READY — UTF-8 OR TCMB REFRESH FAILURE UNRESOLVED`
- Q) Order / Procurement Currency Carryover: Değerlendirilmedi
