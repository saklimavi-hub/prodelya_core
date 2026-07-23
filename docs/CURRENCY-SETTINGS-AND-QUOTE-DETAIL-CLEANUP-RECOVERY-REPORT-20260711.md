# Currency Settings and Quote Detail Cleanup Recovery Report — 2026-07-11

## 1. Faz özeti

Prodelya_V1 `CURRENCY-SETTINGS-AND-QUOTE-DETAIL-CLEANUP-VERIFY-RECOVERY` çalışması bu turda save-method blocker nedeniyle yeniden açıldı.

Bu turda:
- SQLite in-memory ortamında Eloquent array cast bozuluyor varsayımı kabul edilmedi.
- İzole permission tanı testleri tekrar çalıştırıldı.
- Role/UserRole/Tenant ilişki zinciri doğrulandı.
- Hedef 3 test dosyası gerçek fixture ve route sözleşmesine hizalandı.
- Currency settings route/view tarafındaki gerçek blocker'lar düzeltildi.`r`n- Currency settings save formunda eksik Laravel method spoofing (`@method('PUT')`) düzeltildi.
- Sol yönetim menüsüne eksik `Para Birimi ve Kur Ayarları` bağlantısı eklendi.
- Commit ve staging yapılmadı.

## 2. Yanlış varsayımın çürütülmesi

### 2.1 SQLite cast varsayımı

Aşağıdaki tanı testleri yeşil geçti:

- `tests/Feature/PermissionCastDiagnosticTest.php`
  - Sonuç: **PASS**
  - Test: 3
  - Assertion: 10
- `tests/Feature/PermissionRelationDiagnosticTest.php`
  - Sonuç: **PASS**
  - Test: 1
  - Assertion: 1

Sonuç:
- `permissions` alanındaki Eloquent `array` cast SQLite in-memory altında çalışıyor.
- Kök neden `SQLite cast bozuk` değildir.

### 2.2 Fixture contract doğrulaması

Doğru kontrat:
- Role create/factory sırasında `permissions` alanına `json_encode(...)` stringi değil **PHP array** verilmelidir.
- Permission kontrolü tenant bazlı relation zinciri üzerinden çalışır.

Doğrulanan risk başlıkları:
- Double encoding: gerçek risk, bu yüzden array kontratı korundu.
- Relation tenant scope: tanı testlerinde zincir çalıştı.
- Active role durumu: role `is_active=true` olarak kuruldu.
- Relation cache: hedef testler fresh fixture ile kuruldu; sahte cache kök nedeni bulunmadı.

## 3. Gerçek root cause sınıflandırması

Bu turda görülen gerçek blocker'lar şunlardı:

1. Testlerin bir kısmı yanlış kök neden teşhisi yapıyordu.
- Problem cast değil, fixture ve route/view sözleşmesi uyumsuzluğuydu.

2. `TenantCurrencySettingsTest` doğrudan permission cast yüzünden değil, currency settings ekranındaki gerçek uygulama blocker'larına çarpıyordu.
- Route middleware `multi_currency` modülünü feature gibi kontrol ediyordu.
- Currency settings Blade içinde bozuk `@selected(...)` sözdizimi vardı.
- Blade rates tablosu eski payload anahtarlarını okuyordu.

3. Quote detail hedef testleri var olmayan factory varsayımlarına dayanıyordu.
- `Order::factory()` ve `Company::factory()` beklentileri bu repo durumuyla uyumlu değildi.
- Bu testler gerçek model create + mevcut tenant fixture düzenine geçirildi.

4. WhatsApp telefon görünümü normalize ediliyor.
- Ham `+905551234567` yerine ekranda normalize biçim gösteriliyor.
- Test beklentisi buna göre güncellendi.

5. Currency settings ekranı için menü konfigürasyonunda eksik tenant görünürlüğü vardı.
- Sol menü linki yoktu.
- Final turda `Yönetim` grubu altına canonical `multi_currency` module key ve `manage_users` görünürlük sözleşmesiyle eklendi.

## 4. Uygulanan düzeltmeler

Bu turda güncellenen alanlar:

- `routes/web.php`
  - Currency settings route grubunda `feature.enabled:multi_currency` yerine `module.enabled:multi_currency` kullanıldı.
- `resources/views/admin/settings/currency.blade.php`
  - Bozuk Blade sözdizimi düzeltildi.
  - `currencySettings` anahtarları service payload'ına hizalandı.
  - `latestRates` tablosu gerçek payload alanlarına hizalandı.
- `tests/Feature/TenantCurrencySettingsTest.php`
  - Permission fixture'ları PHP array kontratıyla kuruldu.
  - Module gate fixture'ları eklendi.
  - Cross-tenant senaryo yerel host fallback yerine gerçek tenant-membership kontratını doğrulayacak şekilde düzeltildi.
  - Menü linki görünürlük, gizlilik ve active state doğrulamaları eklendi.
- `tests/Feature/PromotionQuoteCurrencyDetailConditionalUiTest.php`
  - Testler seeded tenant/customer fixture + doğrudan model create ile hizalandı.
  - Finans permission'ı explicit array olarak verildi.
- `tests/Feature/PromotionQuoteWhatsappInfoNoticeCleanupTest.php`
  - Testler seeded tenant/customer fixture + doğrudan model create ile hizalandı.
  - Telefon görünümü normalize çıktı kontratına göre güncellendi.
- `config/admin_menu.php`
  - `Yönetim` grubu altına `Para Birimi ve Kur Ayarları` linki eklendi.
  - Route `admin.settings.currency` olarak bağlandı.
  - `module_key` canonical `multi_currency` olarak kullanıldı.
  - Görünürlük `manage_users` permission'ı ile hizalandı.
  - Active pattern `admin.settings.currency` ve `admin.settings.currency.*` olarak tanımlandı.

Dokunulmadı:
- Production permission guard bypass edilmedi.
- Foreign key kontrolleri bypass edilmedi.
- Mock ile `hasPermissionInTenant()` true yapılmadı.
- Commit/staging yapılmadı.
- Order / Procurement Currency Carryover'a geçilmedi.

## 5. Hedefli test sonuçları

### 5.1 Tanı testleri

- `php artisan test tests/Feature/PermissionCastDiagnosticTest.php --stop-on-failure`
  - **PASS**
  - 3 test, 10 assertion
- `php artisan test tests/Feature/PermissionRelationDiagnosticTest.php --stop-on-failure`
  - **PASS**
  - 1 test, 1 assertion

### 5.2 Zorunlu 3 hedefli test dosyası

- `php artisan test tests/Feature/TenantCurrencySettingsTest.php --stop-on-failure`
  - **PASS**
  - 11 test, 36 assertion
- `php artisan test tests/Feature/PromotionQuoteCurrencyDetailConditionalUiTest.php --stop-on-failure`
  - **PASS**
  - 8 test, 32 assertion
- `php artisan test tests/Feature/PromotionQuoteWhatsappInfoNoticeCleanupTest.php --stop-on-failure`
  - **PASS**
  - 5 test, 25 assertion

### 5.3 Menü linki final doğrulamaları

- `php artisan test --filter=TenantSettings --stop-on-failure`
  - **PASS**
  - 6 test, 73 assertion
- `php artisan test --filter=TenantUserRolePermissionFlowTest --stop-on-failure`
  - **PASS**
  - 5 test, 17 assertion
- `php artisan test --filter=AdminSmokeTest --stop-on-failure`
  - **PASS**
  - 58 test, 213 assertion

Toplam final durum:
- Currency settings ekranı, permission zinciri ve menü linki doğrulaması hedefli testlerde yeşil.

## 6. Manual Browser Smoke ve Menü Linki Finali

Kullanıcı manuel browser smoke sonucunu **PASS** olarak kabul etti.

Görsel ve davranışsal olarak kabul edilenler:
- `/admin/settings/currency` yetkili kullanıcıda açılıyor.
- Currency settings ekranı ve mevcut değerleri doğru görünüyor.
- TRY kullanıcıya TL olarak gösteriliyor.
- USD/EUR kur bilgileri doğru görünüyor.
- `Kurları Güncelle` ve `Ayarları Kaydet` akışları kullanılabilir durumda.
- Permission/module guard davranışları doğru kabul edildi.
- TRY teklif detayı minimal görünüyor.
- USD/EUR taslak ve gönderilmiş teklif davranışları doğru kabul edildi.
- Operasyon kullanıcı görünümünde finansal sızıntı yok.
- WhatsApp sarı admin uyarısı kaldırıldı ve bağlantı davranışı korunuyor.

Bu final turda ayrıca doğrulananlar:
- Sol yönetim menüsünde `Para Birimi ve Kur Ayarları` bağlantısı eklendi.
- Link `admin.settings.currency` route'una gidiyor.
- Aktif sayfada menü item'ı active state alıyor.
- `multi_currency` erişimi olmayan kullanıcı için menü linki gizli kalıyor.
- Duplicate ayar linki oluşmadı.

## 7. Git ve çalışma ağacı durumu

Bu tur sonunda ilgili görünen dosyalar:
- `M app/Http/Controllers/Admin/SettingsController.php`
  - Önceden worktree'de modified idi; bu turda commit/stage edilmedi.
- `M config/admin_menu.php`
- `M routes/web.php`
- `?? resources/views/admin/settings/currency.blade.php`
- `?? tests/Feature/PermissionCastDiagnosticTest.php`
- `?? tests/Feature/PermissionRelationDiagnosticTest.php`
- `?? tests/Feature/PromotionQuoteCurrencyDetailConditionalUiTest.php`
- `?? tests/Feature/PromotionQuoteWhatsappInfoNoticeCleanupTest.php`
- `?? tests/Feature/TenantCurrencySettingsTest.php`
- `?? docs/CURRENCY-SETTINGS-AND-QUOTE-DETAIL-CLEANUP-RECOVERY-REPORT-20260711.md`

Staging:
- Yapılmadı
- Staged alan final kontrolde boş

Commit:
- Yapılmadı

## 8. Nihai karar

**NOT READY — SETTINGS SAVE METHOD MISMATCH**

Bu kararın anlamı:
- `SQLite cast bozuk` varsayımı doğrulanmadı, aksine çürütüldü.
- Permission fixture kök nedeni analiz edildi.
- Currency settings ekranı, quote detail temizliği ve menü linki doğrulaması tamamlandı.
- Kullanıcı manuel browser smoke sonucu PASS kabul edildi.
- Hedefli testlerde yeni regresyon görülmedi.

## 9. Carryover kapısı

- `ORDER / PROCUREMENT CARRYOVER`: **NOT STARTED**
- Sebep: Bu faz yalnız currency settings ve quote detail cleanup kapanışı içindi; carryover kararı ayrıca ele alınacak.

## 10. Konsol özeti

- A) SQLite in-memory array cast bozuk mu?
  - **Hayır.** Tanı testleri bunu çürüttü.
- B) `permissions` alanı role create/factory sırasında nasıl verilmeli?
  - **PHP array** olarak verilmeli, `json_encode(...)` string olarak değil.
- C) Double encoding kök neden mi?
  - **Risk olarak doğrulandı**, bu yüzden array kontratı korundu.
- D) Tenant relation chain çalışıyor mu?
  - **Evet.** Tanı testi yeşil.
- E) Active role etkisi var mı?
  - **Evet.** Test fixture'larında `is_active=true` açıkça kuruldu.
- F) Relation cache kök neden mi?
  - **Hayır.** Belirleyici neden bulunmadı.
- G) TenantCurrencySettings hedef testi durumu nedir?
  - **PASS** (11 test, 36 assertion)
- H) Quote currency detail hedef testi durumu nedir?
  - **PASS** (8 test, 32 assertion)
- I) WhatsApp cleanup hedef testi durumu nedir?
  - **PASS** (5 test, 25 assertion)
- J) Currency settings tarafında gerçek uygulama blocker neydi?
  - Route gate yanlış sınıflaması + Blade söz dizimi/payload uyumsuzluğu + eksik menü bağlantısı.
- K) Permission guard bypass edildi mi?
  - **Hayır.**
- L) Foreign key kontrolleri bypass edildi mi?
  - **Hayır.**
- M) Staging yapıldı mı?
  - **Hayır.**
- N) Commit yapıldı mı?
  - **Hayır.**
- O) Final durum nedir?
  - **NOT READY — SETTINGS SAVE METHOD MISMATCH**
- P) Carryover kararı verildi mi?
  - **Hayır.** Bu fazda açılmadı.
