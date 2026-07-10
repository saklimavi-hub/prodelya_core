# Quote / Order List Tests-Only Hardening Commit Apply Raporu — 2026-07-10

## 1. Faz Özeti

- Faz türü: iki ayrı tests-only checkpoint + bir docs-only rapor commit’i
- Production kodu değiştirildi mi: hayır
- Test içerikleri değiştirildi mi: hayır
- QuoteOrderManualSmokeRouteTest commitlendi mi: hayır
- Staging seçici yapıldı mı: evet
- Toplu staging kullanıldı mı: hayır

## 2. Prep Raporu Kararı

- Referans:
  - `docs/QUOTE-ORDER-LIST-TESTS-ONLY-HARDENING-PREP-RAPORU-20260710.md`
- Prep kararı:
  - Commit 1: Quote/Order List UX and Terminology Tests
  - Commit 2: Quote/Order List Security and Tenant Isolation Tests
  - `tests/Feature/QuoteOrderManualSmokeRouteTest.php` commit dışı

## 3. Commit 1 Hash’i ve Dosyaları

- Commit hash: `f375d08`
- Commit mesajı: `tests: harden quote and order list ux terminology`
- Commitlenen dosyalar:
  - `tests/Feature/PromotionQuoteAndOrderIndexHeaderPanelTest.php`
  - `tests/Feature/PromotionQuoteAndOrderIndexUxTest.php`
  - `tests/Feature/QuoteOrderListTurkishTerminologyTest.php`

## 4. Commit 1 Test Sonucu

- Komut:
  - `php artisan test --filter="PromotionQuoteAndOrderIndexHeaderPanelTest|PromotionQuoteAndOrderIndexUxTest|QuoteOrderListTurkishTerminologyTest"`
- Sonuç:
  - passed
  - test: `7`
  - assertion: `87`
  - risky: `0`
  - skipped: `0`
  - süre: `4428 ms`

## 5. Commit 2 Hash’i ve Dosyaları

- Commit hash: `3960598`
- Commit mesajı: `tests: protect quote and order list security isolation`
- Commitlenen dosyalar:
  - `tests/Feature/QuoteOrderListNoSensitiveLeakTest.php`
  - `tests/Feature/QuoteOrderListNoTechnicalUiLeakRegressionTest.php`
  - `tests/Feature/QuoteOrderListTenantIsolationTest.php`

## 6. Commit 2 Test Sonucu

- Komut:
  - `php artisan test --filter="QuoteOrderListNoSensitiveLeakTest|QuoteOrderListNoTechnicalUiLeakRegressionTest|QuoteOrderListTenantIsolationTest"`
- Sonuç:
  - passed
  - test: `3`
  - assertion: `66`
  - risky: `0`
  - skipped: `0`
  - süre: `2858 ms`

## 7. Birleşik Test Matrisi Sonucu

- Komut:
  - `php artisan test --filter="PromotionQuoteAndOrderIndex|QuoteOrderList"`
- Sonuç:
  - passed
  - test: `10`
  - assertion: `153`
  - risky: `0`
  - skipped: `0`
  - süre: `4857 ms`
- Not:
  - Bu filtre commitlenen altı test dosyasını kapsadı.
  - `QuoteOrderManualSmokeRouteTest.php` bu commit akışına dahil edilmedi.

## 8. Smoke Sonucu

- Komut:
  - `php artisan test --filter="AdminSmokeTest|FullOperationalFlowSmokeTest"`
- Sonuç:
  - passed
  - test: `60`
  - assertion: `644`
  - risky: `0`
  - skipped: `0`
  - süre: `13012 ms`

## 9. Commit Dışında Bırakılan Test

- Dosya:
  - `tests/Feature/QuoteOrderManualSmokeRouteTest.php`
- Durum:
  - untracked olarak korundu
- Commit dışı bırakılma gerekçesi:
  - yalnız `assertOk` kontrolü yapıyor
  - aynı route matrisi daha güçlü liste güvenlik testleriyle kapsanıyor
  - `AdminSmokeTest` ile de kısmi örtüşüyor
  - prep kararı: `D — DO NOT COMMIT`

## 10. Production Kodunun Değişmediği

- Bu apply fazında aşağıdaki production alanlarına dokunulmadı:
  - `app/Http/Controllers/**`
  - `app/Models/**`
  - `resources/views/**`
  - `public/css/**`
  - `routes/web.php`
  - `config/**`
- Yalnız seçili test dosyaları ve iki docs raporu commit akışına girdi.

## 11. Staged Alan Durumu

- Commit 1 öncesi:
  - yalnız 3 UX/terminoloji test dosyası staged
- Commit 1 sonrası:
  - staged alan boş
- Commit 2 öncesi:
  - yalnız 3 security/isolation test dosyası staged
- Commit 2 sonrası:
  - staged alan boş
- Docs commit öncesi:
  - yalnız 2 rapor dosyası staged olmalı

## 12. Kalan Modified Dosyalar

- `app/Http/Controllers/Admin/OrderController.php`
- `app/Http/Controllers/Admin/PromotionQuoteController.php`
- `app/Models/Order.php`
- `config/admin_menu.php`
- `public/css/prodelya-admin.css`
- `routes/web.php`

## 13. Kalan Untracked Dosyalar

- `docs/10.15.18-C-revizyonu-uygula-teknik-karar-plani.md`
- `docs/CSS-QUOTE-DETAIL-SEND-MODAL-HUNK-STAGING-PREP-RAPORU-20260710.md`
- `docs/CSS-TEMPLATE-HUNK-STAGING-PREP-RAPORU-20260710.md`
- `docs/FULL-SYSTEM-SCAN-20260709.md`
- `docs/ORDER-DETAIL-TEMP-CLEANUP-SAFE-RAPORU-20260710.md`
- `docs/PRODUCT-HUB-AND-TEMPLATE-INTEGRATION-MASTER-PLAN-20260709.md`
- `docs/QUOTE-DETAIL-CHECKPOINT-COMMIT-APPLY-RAPORU-20260710.md`
- `docs/QUOTE-DETAIL-FAILED-STAGING-RESET-AND-SCOPE-REALIGN-RAPORU-20260710.md`
- `docs/QUOTE-DETAIL-SEND-CHANNEL-HUNK-STAGING-PREP-RAPORU-20260710.md`
- `docs/QUOTE-ORDER-LIST-TESTS-ONLY-HARDENING-PREP-RAPORU-20260710.md`
- `docs/QUOTE-ORDER-LIST-TESTS-ONLY-HARDENING-COMMIT-APPLY-RAPORU-20260710.md`
- `docs/REVISION-CHECKPOINT-A-B-C-COMMIT-APPLY-RAPORU-20260709.md`
- `docs/REVISION-CHECKPOINT-A-B-C-HUNK-STAGING-PREP-RAPORU-20260709.md`
- `docs/REVISION-PUBLIC-APPROVAL-CHECKPOINT-PREP-RAPORU-20260709.md`
- `docs/SAFE-ROLLBACK-AUDIT-20260709.md`
- `docs/TEMP-BACKUP-FILES-SAFE-CLEANUP-APPLY-RAPORU-20260710.md`
- `docs/TEMP-BACKUP-FILES-SAFE-CLEANUP-PREP-RAPORU-20260710.md`
- `docs/WORKTREE-CHECKPOINT-STABILIZATION-PREP-RAPORU-20260710.md`
- `docs/WORKTREE-TEMP-CLEANUP-SAFE-RAPORU-20260710.md`
- `docs/ui-previews/`
- `tests/Feature/QuoteOrderManualSmokeRouteTest.php`

## 14. Sonraki Mikro Stabilizasyon Önerisi

- Sonraki mikro adım:
  - `QuoteOrderManualSmokeRouteTest.php` için commit dışı bırakma kararını koruyarak, kalan untracked docs/test birikimini ayrı bir read-only sınıflandırma fazında gruplamak
  - production worktree modified dosyalarına dokunmadan docs/test/preview kalanlarını stabilize etmek
