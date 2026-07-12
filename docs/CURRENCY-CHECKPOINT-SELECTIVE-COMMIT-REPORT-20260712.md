# Currency Checkpoint Selective Commit Report - 2026-07-12

## 1. Preflight

- Prompt read and applied: `docs/prompts/PRODELYA_V1_CURRENCY_CHECKPOINT_SELECTIVE_COMMIT_PROMPT.md`
- Manual browser smoke: user-confirmed `PASS`
- Initial staged area: empty
- Current HEAD before feature commit: `2bd5d74 quotes: add currency snapshot persistence`
- `php artisan migrate:status`: read-only checked, no migration action taken

## 2. Worktree Riskleri

Worktree currency dışı çok sayıda karışık değişiklik içeriyordu:

- Product Data Hub servis ve testleri
- quote/order currency snapshot ve carryover hattı
- public approval
- customer-facing price display
- promotion quote UI
- genel CSS ve çeşitli docs/test artefaktları
- Process Depth audit dosyaları

Bu nedenle dosya bazlı toplu staging yapılmadı. Karışık dosyalarda yalnız currency hunkları seçildi.

## 3. Manual Smoke

Kullanıcı tarafından `PASS` doğrulananlar:

- UTF-8 Türkçe görünüm: `PASS`
- Firma Ana Para Birimi USD persistence: `PASS`
- Firma Ana Para Birimi EUR persistence: `PASS`
- Varsayılan Teklif Para Birimi persistence: `PASS`
- Kullanılabilir Teklif Para Birimleri persistence: `PASS`
- Kurları Güncelle: `PASS`
- Kullanılan fallback tarihi: `2026-07-10`
- 405 hatası yok: `PASS`
- Sayfa yenilemesinden sonra değerler korunuyor: `PASS`

## 4. Diff Attribution

### Dahil edilenler

- `CURRENCY_SETTINGS_CORE`
  - `app/Http/Controllers/Admin/SettingsController.php`
  - `app/Services/Currency/TenantCurrencySettingsService.php`
  - `resources/views/admin/settings/currency.blade.php`
- `CURRENCY_MENU_ROUTE`
  - `routes/web.php`
  - `config/admin_menu.php`
  - `config/prodelya_modules.php`
- `CURRENCY_TEST`
  - `tests/Feature/TenantCurrencySettingsTest.php`
  - `database/factories/RoleFactory.php`
  - `database/factories/TenantAccountFactory.php`
  - `database/factories/UserRoleFactory.php`

### Dışarıda bırakılanlar

- `UNRELATED_EXISTING_WORKTREE`
  - quote/order currency snapshot
  - Product Data Hub currency propagation
  - public approval
  - customer-facing price display
  - promotion quote detail/UI cleanup
  - unrelated CSS hunks
  - Process Depth dosyaları
  - unrelated diagnostics and preview docs

### Karışık dosya kararı

- `routes/web.php` içinden yalnız `/settings/currency` route bloğu patch staging ile alındı.
- quote currency route hunkları alınmadı.
- BOM / line-ending kaynaklı unrelated route hunks alınmadı.

## 5. Dahil Edilen Hunklar

Feature commit kapsamı:

- currency settings ekranı
- ayrı save/refresh form sözleşmesi
- tenant base/default/enabled currency persistence
- TCMB refresh fallback düzeltmesi
- güvenli hata mesajı ve log context
- currency menü ve route erişimi
- hedefli currency testleri

## 6. Dışarıda Bırakılan Hunklar

- quote currency refresh/acknowledge route’ları
- order/procurement carryover
- Product Data Hub propagasyonları
- unrelated `AdminSmokeTest` diffi
- unrelated feature test değişiklikleri
- public/customer ekran değişiklikleri
- Process Depth ve diğer docs artefaktları

## 7. Test Sonuçları

Staging öncesi:

- `php artisan test tests/Feature/TenantCurrencySettingsTest.php --stop-on-failure`
  - passed, `18 test`, `99 assertion`
- `php artisan test --filter=TenantSettings --stop-on-failure`
  - passed, `6 test`, `73 assertion`
- `php artisan test --filter=TenantUserRolePermissionFlowTest --stop-on-failure`
  - passed, `5 test`, `17 assertion`
- `php artisan test --filter=AdminSmokeTest --stop-on-failure`
  - passed, `58 test`, `213 assertion`
- `php artisan test --filter=Currency --stop-on-failure`
  - failed, but only due untracked diagnostic artifact:
    - `tests/Feature/TenantCurrencySettingsDiagnosticTest`
    - `roles.key` unique constraint failure
  - commit kapsamına alınmadı, checkpoint dışı diagnostic olarak ayrıştırıldı

Commit sonrası minimum tekrar:

- `php artisan test tests/Feature/TenantCurrencySettingsTest.php --stop-on-failure`
  - passed, `18 test`, `99 assertion`
- `php artisan test --filter=AdminSmokeTest --stop-on-failure`
  - passed, `58 test`, `213 assertion`

Full suite:

- çalıştırılmadı

## 8. Staged Diff Doğrulaması

Feature staging sırasında cache’e alınan dosyalar yalnız şunlardı:

- `app/Http/Controllers/Admin/SettingsController.php`
- `app/Services/Currency/TenantCurrencySettingsService.php`
- `config/admin_menu.php`
- `config/prodelya_modules.php`
- `database/factories/RoleFactory.php`
- `database/factories/TenantAccountFactory.php`
- `database/factories/UserRoleFactory.php`
- `resources/views/admin/settings/currency.blade.php`
- `routes/web.php`
- `tests/Feature/TenantCurrencySettingsTest.php`

Yanlışlıkla staged edilen Product Data Hub, Process Depth, carryover veya unrelated CSS hunkı yoktu.

## 9. Feature Commit

- Commit message: `settings: finalize tenant currency settings and tcmb refresh`
- Commit hash: `14907fd`

## 10. Docs Commit

Bu rapor ayrı docs commit olarak hazırlanmıştır.

- Planned message: `docs: add currency settings refresh recovery report`

## 11. Commitlenen Dosyalar

Feature commit dosyaları:

- `app/Http/Controllers/Admin/SettingsController.php`
- `app/Services/Currency/TenantCurrencySettingsService.php`
- `config/admin_menu.php`
- `config/prodelya_modules.php`
- `database/factories/RoleFactory.php`
- `database/factories/TenantAccountFactory.php`
- `database/factories/UserRoleFactory.php`
- `resources/views/admin/settings/currency.blade.php`
- `routes/web.php`
- `tests/Feature/TenantCurrencySettingsTest.php`

Docs commit dosyası:

- `docs/CURRENCY-CHECKPOINT-SELECTIVE-COMMIT-REPORT-20260712.md`

## 12. Korunan İlgisiz Worktree Değişiklikleri

Korundu:

- Process Depth audit dokümanı
- quote/order currency snapshot hattı
- Product Data Hub ve customer-facing price display hattı
- unrelated route/controller/test değişiklikleri
- preview ve diagnostic artefaktları

Hiçbir toplu reset, restore veya clean uygulanmadı.

## 13. Final Staged Durumu

- Feature commit sonrası staged area: empty
- Docs commit öncesi staged area: yalnız bu rapor olmalı

## 14. Process Depth Gate Kararı

Karar:

- Currency checkpoint selective olarak ayrıştırıldı
- Manual smoke `PASS`
- Feature commit alındı
- Docs commit sonrası staged alan boş kalırsa:
  - `PROCESS DEPTH CORE GATE: OPEN`
