# Quote Currency Selective Staging Apply Raporu — 2026-07-11

## 1. Faz özeti

- V5 apply akışı başlatıldı.
- Başlangıç güvenlik kapıları doğrulandı:
  - `HEAD`: `bc07ac0`
  - branch: `feature/master-restructure-phase-2-order-flow`
  - staged alan: boş
  - zorunlu V4 safety audit raporu: mevcut
  - zorunlu implementation raporu: mevcut
- Commit 1 başarıyla tamamlandı.
- Commit 2 selective staging sırasında mixed `PromotionQuoteController.php` dosyasında line-ending / whitespace etkileşimi nedeniyle güvenli hunk izolasyonu sürdürülemedi.
- Bu nedenle Commit 2-5 uygulanmadı.
- Bloker oluştuğu için yeni commit üretilmeden duruldu.

## 2. Commit 1 — staged diff ve test kanıtı

### Commit mesajı

`quotes: add currency snapshot persistence`

### Commit hash

`2bd5d74`

### Dahil edilen dosyalar

- `app/Http/Controllers/Admin/PromotionQuoteController.php`
- `app/Models/Order.php`
- `app/Models/OrderItemPrint.php`
- `app/Services/PromotionQuote/QuoteCurrencyAccessService.php`
- `app/Services/PromotionQuote/QuoteCurrencyPricingService.php`
- `database/migrations/2026_07_10_210000_add_quote_currency_snapshot_fields.php`

### Hedefli testler

1. `php artisan test --filter=PromotionQuoteCurrencySnapshotTest`
   - Sonuç: geçti
   - Tests: 4
   - Assertions: 14
2. `php artisan test --filter=PromotionQuoteCreateEditUiRegressionTest`
   - Sonuç: geçti
   - Tests: 5
   - Assertions: 98

## 3. Commit 2 — blocker

### Hedef kapsam

- `PromotionQuoteController.php` içinde yalnız create/show/edit currency payload hunkları
- `routes/web.php` içinde yalnız currency refresh / acknowledge route hunkları
- `resources/views/admin/promotion-quotes/_form-workspace.blade.php`
- `resources/views/admin/promotion-quotes/show.blade.php`
- customer-facing currency servis/controller dosyaları

### Gözlenen sorun

- Mixed `app/Http/Controllers/Admin/PromotionQuoteController.php` dosyasında selective patch staging line-ending / whitespace farklarıyla birleşti.
- Dar controller hunkları patch ile stage edilirken currency dışı `applyRevision` whitespace hunki index’e sızdı.
- Index yalnız bu dosya için birkaç kez yeniden kuruldu ve sonunda güvenli olmayan staged durum bırakılmadı.
- Exact ve güvenli commit 2 kapsaması bu turda kanıtlanamadığı için commit oluşturulmadı.

### Güvenlik kararı

- Durum: `BLOCKED`
- Neden: `PromotionQuoteController.php` mixed hunk selective staging safety kanıtı bu turda tamamlanamadı.

## 4. Index ve worktree son durumu

- Commit 1 sonrası staged alan temizdi.
- Commit 2 denemesi sırasında oluşan staged içerikler commitlenmeden geri çıkarıldı.
- Final durumda staged alan tekrar boştur.
- Kapsam dışı worktree değişiklikleri korunmuştur.
- Order / Procurement Currency Carryover’a geçilmemiştir.
- Uygulama veya test kodunda Commit 2-5 için kalıcı yeni commit oluşturulmamıştır.

## 5. Temp patch temizliği

- Bu turda oluşturulan `.tmp/v5_quote_currency/` geçici patch klasörü temizlendi.
- Yeni `v5_quote_currency` temp patch artığı bırakılmadı.

## 6. Oluşan commitler

| Sıra | Commit | Mesaj | Durum |
| --- | --- | --- | --- |
| 1 | `2bd5d74` | `quotes: add currency snapshot persistence` | Tamamlandı |
| 2 | - | `quotes: implement multi-currency pricing snapshots` | Blocked |
| 3 | - | `finance: normalize TRY payment currency aliases` | Başlatılmadı |
| 4 | - | `tests: cover quote currency conversion snapshots` | Başlatılmadı |
| 5 | - | `docs: add quote currency checkpoint reports` | Başlatılmadı |

## 7. Net karar

- V5 apply akışı bu turda tam kapanmadı.
- Commit 1 güvenle checkpointlendi.
- Commit 2 mixed controller selective staging güvenlik blocker’ı nedeniyle süreç durduruldu.
- Final karar: `NOT COMPLETED — BLOCKED AT COMMIT 2 SELECTIVE STAGING`
