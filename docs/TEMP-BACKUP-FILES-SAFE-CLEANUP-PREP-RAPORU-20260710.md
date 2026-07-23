# Temp / Backup Files Safe Cleanup Prep Raporu — 2026-07-10

## 1. Faz Özeti

- Bu faz read-only geçici dosya karşılaştırması ve güvenli silme kararı hazırlığıdır.
- Hiçbir dosya silinmedi.
- Uygulama Blade, PHP, CSS, JS, config, route ve test dosyaları değiştirilmedi.
- `git add`, `git commit`, `git reset`, `git restore`, `git checkout`, `git clean`, `git stash` çalıştırılmadı.
- Staged alan başta boştu, sonda da boş kaldı.
- Audit sırasında oluşan tek yeni dosya bu rapordur.

## 2. Ground Truth Özeti

### 2.1 Başlangıç git durumu

- Modified: `8`
- Untracked: `23`
- `git diff --cached --stat`: boş
- `git diff --cached --name-status`: boş

### 2.2 İncelenen dosyalar

- `.tmp_quote_detail_commit_target.blade.php`
- `.tmp_quote_detail_show_worktree_backup.blade.php`
- `resources/views/admin/promotion-quotes/show.blade.php`

### 2.3 İlgili commit ve rapor doğrulaması

- Karşılaştırılan commitler:
  - `HEAD`
  - `bfb4382`
  - `c567e82`
  - `21753a6`
- İncelenen raporlar:
  - `docs/ORDER-DETAIL-TEMP-CLEANUP-SAFE-RAPORU-20260710.md`
  - `docs/WORKTREE-TEMP-CLEANUP-SAFE-RAPORU-20260710.md`
  - `docs/QUOTE-DETAIL-CHECKPOINT-COMMIT-APPLY-RAPORU-20260710.md`
  - `docs/QUOTE-DETAIL-FAILED-STAGING-RESET-AND-SCOPE-REALIGN-RAPORU-20260710.md`
  - `docs/QUOTE-DETAIL-SEND-CHANNEL-HUNK-STAGING-PREP-RAPORU-20260710.md`
  - `docs/CSS-QUOTE-DETAIL-SEND-MODAL-HUNK-STAGING-PREP-RAPORU-20260710.md`

## 3. A) DOSYA KİMLİK TABLOSU

| Dosya | Boyut | Hash | Son Değişiklik | Tür |
| --- | ---: | --- | --- | --- |
| `.tmp_quote_detail_commit_target.blade.php` | `44,783` | `10d13d1e133ba813d78ab4afa2751c27868cf6a8` | `2026-07-10 12:15:26` | temp / staging hedefi |
| `.tmp_quote_detail_show_worktree_backup.blade.php` | `59,281` | `86130e61bfb10c41f73f2bdf6d690a0f09768581` | `2026-07-09 09:39:16` | temp / backup |
| `resources/views/admin/promotion-quotes/show.blade.php` | `59,281` | `86130e61bfb10c41f73f2bdf6d690a0f09768581` | `2026-07-09 09:39:16` | production Blade |

Ek kanıt:

- `HEAD:resources/views/admin/promotion-quotes/show.blade.php` hash'i: `86130e61bfb10c41f73f2bdf6d690a0f09768581`
- `bfb4382`, `c567e82`, `21753a6` içindeki aynı Blade hash'i de: `86130e61bfb10c41f73f2bdf6d690a0f09768581`

Sonuç:

- `.tmp_quote_detail_show_worktree_backup.blade.php`
  - worktree production Blade ile byte/hash düzeyinde birebir aynıdır
  - HEAD ile aynıdır
  - ilgili quote detail/send commitleriyle aynıdır

## 4. Ham ve Normalize Karşılaştırma

### 4.1 Line ending / BOM / whitespace

- Üç dosyanın ilk 16 byte incelemesinde BOM bulunmadı.
- `.tmp_quote_detail_commit_target.blade.php`
  - `CRLF=1`
  - `LF_ONLY=814`
  - trailing whitespace satırı: `0`
- `.tmp_quote_detail_show_worktree_backup.blade.php`
  - `CRLF=0`
  - `LF_ONLY=1040`
  - trailing whitespace satırı: `0`
- `resources/views/admin/promotion-quotes/show.blade.php`
  - `CRLF=0`
  - `LF_ONLY=1040`
  - trailing whitespace satırı: `0`

Normalize edilmiş yorum:

- `backup` dosyası ile production Blade arasında yalnız whitespace değil, doğrudan hiç fark yok.
- `commit_target` dosyasındaki fark yalnız line ending değil; gerçek Blade/PHP logic ve yapı kaybı içeriyor.

## 5. B) KARŞILAŞTIRMA MATRİSİ

| Temp Dosya | Worktree Blade | HEAD Blade | İlgili Commit | Sonuç |
| --- | --- | --- | --- | --- |
| `.tmp_quote_detail_commit_target.blade.php` | farklı | farklı | `bfb4382`, `c567e82`, `21753a6` hepsinden farklı | stale ve bozuk ara kopya |
| `.tmp_quote_detail_show_worktree_backup.blade.php` | aynı | aynı | `bfb4382`, `c567e82`, `21753a6` ile aynı | birebir yedek / duplicate |

## 6. Benzersiz Logic Kontrolü

### 6.1 `.tmp_quote_detail_commit_target.blade.php`

Worktree production Blade’de bulunmayan ama bu temp dosyada görülen ve korunması gereken benzersiz logic bulunmadı.

Tersine, temp dosyada eksik olan ve production’da bulunan bloklar:

- `approvalHelperUrl`
- `sourceOrderContext`
- `revisionCompareUrl`
- `data-open-send-modal`
- `route('admin.promotion-quotes.send-to-customer', $quote)`
- `route('admin.promotion-quotes.whatsapp.open', $quote)`
- `data-send-channel-input`
- `quoteSendModal`
- `bindModal('quoteSendModal', ...)`
- send tab / approval tab / action buttons
- WhatsApp link helper alanları

Bu nedenle bu dosya benzersiz logic korumuyor; aksine daha eksik bir kopya.

### 6.2 `.tmp_quote_detail_show_worktree_backup.blade.php`

Kanıt:

- Hash, boyut ve timestamp worktree Blade ile aynı.
- `git diff --no-index` çıktısı boş.
- `HEAD`, `bfb4382`, `c567e82`, `21753a6` içindeki Blade ile aynı hash.

Sonuç:

1. Güncel worktree Blade’de bulunmayan benzersiz iş mantığı yok.
2. HEAD’de bulunmayan ama güncel worktree’de bulunan özel bir ara durum yedeklemiyor; çünkü HEAD ile de aynı.
3. Aynı içerik ilgili commitlerde ve güncel production Blade’de zaten güvence altında.
4. Dosya birebir yedek/duplicate.
5. Dosya silinirse geri getirilemeyecek bir içerik kaybı olmaz.
6. Korunmasını gerektiren açık bir rollback senaryosu görünmüyor.
7. Master audit ve quote detail checkpoint raporları bu yedeğin tarihsel açıklama ihtiyacını da büyük ölçüde karşılıyor.
8. Güncel production Blade zaten istenen quote detail / send yapısını içeriyor.

## 7. C) BENZERSİZ LOGIC MATRİSİ

| Dosya | Benzersiz Blok | Production Karşılığı | Gerekli mi | Karar |
| --- | --- | --- | --- | --- |
| `.tmp_quote_detail_commit_target.blade.php` | Yok; aksine production blokları eksik | Production Blade daha güncel ve tam | Hayır | `A — SAFE DELETE` |
| `.tmp_quote_detail_show_worktree_backup.blade.php` | Yok; birebir duplicate | Production Blade, HEAD ve ilgili commitler | Hayır | `A — SAFE DELETE` |

## 8. D) BOZUKLUK MATRİSİ

| Dosya | Bozuk/Eski İçerik | Kanıt | Risk |
| --- | --- | --- | --- |
| `.tmp_quote_detail_commit_target.blade.php` | `: $calculatedPrintTotal; static function ...` birleşmesi | `git diff --no-index` farkı ile doğrulandı | Yüksek |
| `.tmp_quote_detail_commit_target.blade.php` | `}; ! $isConverted ...` birleşmesi | `git diff --no-index` farkı ile doğrulandı | Yüksek |
| `.tmp_quote_detail_commit_target.blade.php` | send modal, source-order, revision compare, approval helper blokları eksik | worktree Blade karşılaştırması | Yüksek |
| `.tmp_quote_detail_show_worktree_backup.blade.php` | bozuk/eski içerik yok | hash birebir eşleşme | Düşük |

Ek parse göstergesi:

- `.tmp_quote_detail_commit_target.blade.php`
  - `OPEN_DIV=145`
  - `CLOSE_DIV=147`
  - `IF=21`
  - `ENDIF=22`

Bu tek başına parser hatası kanıtı değildir; fakat bozuk birleşme kanıtlarıyla birlikte dosyanın güvenilir production kaynağı olmadığını destekler.

## 9. Tarihsel ve Checkpoint Kanıtı

- `docs/QUOTE-DETAIL-FAILED-STAGING-RESET-AND-SCOPE-REALIGN-RAPORU-20260710.md`
  - worktree kopyasında approval, revision/source-order ve send modal bloklarının durduğunu açıkça kaydediyor
- `docs/WORKTREE-CHECKPOINT-STABILIZATION-PREP-RAPORU-20260710.md`
  - `commit_target` için `stale/bozuk`
  - `backup` için `gerçek worktree yedeği`
  - fakat bu fazdaki yeniden doğrulama sonrası `backup` dosyası artık `manual review` değil, doğrudan duplicate olarak kanıtlandı
- `docs/QUOTE-DETAIL-SEND-CHANNEL-HUNK-STAGING-PREP-RAPORU-20260710.md`
  - quote detail ve send modal zincirinin aynı yüzeyde yaşadığını doğruluyor

## 10. E) NİHAİ KARAR

| Dosya | Karar | Gerekçe | Sonraki İşlem |
| --- | --- | --- | --- |
| `.tmp_quote_detail_commit_target.blade.php` | `A — SAFE DELETE` | benzersiz logic yok, bozuk birleşmeler var, güncel Blade’den geri ve eksik | ayrı apply fazında silinebilir |
| `.tmp_quote_detail_show_worktree_backup.blade.php` | `A — SAFE DELETE` | production Blade ile byte/hash düzeyinde birebir aynı, rollback değeri ayrı değil | ayrı apply fazında silinebilir |

## 11. F) SAFE DELETE APPLY PLANI

| Dosya | Ön Kontrol | Silme Sonrası Beklenti | Rollback |
| --- | --- | --- | --- |
| `.tmp_quote_detail_commit_target.blade.php` | hash tekrar doğrulanır, worktree Blade hash'i `86130e61...` olarak korunur | `git status --short` içinde yalnız bu `??` satırı kaybolur | güncel production Blade + HEAD + `bfb4382/c567e82/21753a6` + prep raporları |
| `.tmp_quote_detail_show_worktree_backup.blade.php` | worktree Blade ile hash eşitliği tekrar doğrulanır | `git status --short` içinde yalnız bu `??` satırı kaybolur | güncel production Blade + HEAD + aynı hash kanıtı + prep raporu |

Önerilen sonraki apply fazı notu:

- Bu fazda silme yapılmadı.
- Ayrı apply fazında önerilecek silme komutları PowerShell tarafında doğrudan hedefli olmalı:
  - `Remove-Item -LiteralPath '.tmp_quote_detail_commit_target.blade.php'`
  - `Remove-Item -LiteralPath '.tmp_quote_detail_show_worktree_backup.blade.php'`
- Test zorunlu değil; çünkü untracked temp dosya temizliği uygulama davranışını değiştirmiyor.

## 12. Nihai Değerlendirme

- İncelenen iki temp dosyanın da production için gerekli benzersiz logic taşımadığı kanıtlandı.
- `commit_target` dosyası bozuk/stale geçici staging hedefidir.
- `backup` dosyası ise tarihsel olarak “yedek” niyetli olsa da bugün artık güncel production Blade’in birebir kopyasıdır.
- Bu nedenle iki dosya da güvenli silme adayıdır.
