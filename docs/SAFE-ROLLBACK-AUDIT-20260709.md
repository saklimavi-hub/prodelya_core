# Prodelya Guvenli Geri Alma Oncesi Audit Raporu

## 1. Mevcut Git Durumu

- Aktif branch: `feature/master-restructure-phase-2-order-flow`
- HEAD commit: `8eafa19` (`checkpoint-master-restructure-phase-1-menu-layout-20260707-1818`)
- Son commitler:
  - `8eafa19` phase 1: restructure tenant admin menu and layout
  - `b8729d5` checkpoint: before master restructure phase 0
  - `e3ee0f7` Initial Prodelya checkpoint before 10.14.4
  - `1931cb6` Initial Prodelya current baseline
- Uncommitted degisiklikler:
  - `38` adet modified dosya
  - `120` adet untracked dosya
  - Staged degisiklik yok
- Untracked dosyalar ozellikle su alanlarda yogun:
  - `app/Services/OrderRevision*`
  - `app/Services/OrderQuoteDraftCloneService.php`
  - `app/Models/OrderRevision*.php`
  - `database/migrations/2026_07_08_*`
  - `resources/views/admin/promotion-quotes/revision-compare.blade.php`
  - `app/Mail/QuoteCustomerApprovalMail.php`
  - `resources/views/emails/quote-customer-approval.blade.php`
  - cok sayida yeni `tests/Feature/*`
- `git diff --stat` ozetine gore tracked working tree farki: `38 files changed, 4234 insertions(+), 1157 deletions(-)`

Degerlendirme:
HEAD commit seviyesi zaten birincil saglam checkpoint olan `8eafa19` uzerinde. Su anki risk commit gecmisinden degil, bu checkpoint ustune binmis yerel calisma agacindan geliyor.

## 2. Saglam Checkpoint Karsilastirmasi

### 8eafa19 ile farklar

- `HEAD == 8eafa19`
- Commit seviyesi fark yok
- Farklar tamamen working tree seviyesinde
- Bu nedenle `8eafa19` hedefli tam kod rollback pratikte `git reset --hard 8eafa19` gibi bir islemle sadece yerel degisiklikleri silecektir

### b8729d5 ile farklar

- `b8729d5..8eafa19` arasi faz:
  - menu/layout yeniden yapilanmasi
  - degisen dosyalar:
    - `config/admin_menu.php`
    - `public/css/prodelya-admin.css`
    - `resources/views/layouts/prodelya-admin.blade.php`
    - `tests/Feature/AdminMenuServiceTest.php`
    - `tests/Feature/AdminMenuVisibilityTest.php`
- `b8729d5` derin fallbacke donulurse:
  - phase 1 menu/layout iyilestirmeleri kaybolur
  - mevcut yerel order/quote/revision/public approval degisiklikleri de kaybolur

### Hangi fazlar geri alinmis olur?

- `8eafa19` rollback:
  - sadece yerel degisiklikler geri alinir
  - phase 1 menu/layout korunur
- `b8729d5` rollback:
  - phase 1 menu/layout geri gider
  - mevcut revision/repeat order/quote approval/UI hotfix birikimi kaybolur
  - daha derin davranissal regresyon riski olusur

## 3. Dosya Degisiklik Kumeleleri

### Blade

- `resources/views/admin/orders/index.blade.php`
- `resources/views/admin/orders/show.blade.php`
- `resources/views/admin/promotion-quotes/index.blade.php`
- `resources/views/admin/promotion-quotes/show.blade.php`
- `resources/views/admin/promotion-quotes/edit.blade.php`
- `resources/views/admin/promotion-quotes/revision-compare.blade.php`
- `resources/views/public/quotes/approval/show.blade.php`
- `resources/views/public/graphics/approval/show.blade.php`
- `resources/views/emails/quote-customer-approval.blade.php`

Tema:
Siparis detay akisi, teklif detay ekranlari, musteri onay ekranlari, revizyon karsilastirma UI ve mail sunumu.

### Controller

- `app/Http/Controllers/Admin/OrderController.php`
- `app/Http/Controllers/Admin/PromotionQuoteController.php`
- `app/Http/Controllers/PublicQuoteApprovalController.php`

Tema:
- siparisten revizyon taslagi olusturma
- tekrar siparis taslagi olusturma
- teklif revizyon karsilastirma ve apply akisleri
- public quote approval davranis degisiklikleri

### Service

- `app/Services/OrderQuoteDraftCloneService.php`
- `app/Services/OrderRevisionApplyService.php`
- `app/Services/OrderRevisionComparisonService.php`
- `app/Services/OrderRevisionRecordService.php`
- `app/Services/QuoteApprovalService.php`
- `app/Services/PhoneNumberNormalizer.php`
- `app/Services/Notifications/*`

Tema:
- revision/repeat order domain mantigi
- quote approval sunum ve iletisim akislarinda degisiklik
- telefon/WhatsApp normalizasyonu
- SMTP/mail tarafinda custom approval mail templating

### Migration

- `database/migrations/2026_07_08_120000_add_order_copy_metadata_to_orders_table.php`
- `database/migrations/2026_07_08_150000_create_order_revisions_tables.php`

Tema:
- `orders` tablosuna `source_order_id`, `copy_type`, `revision_number`, `copied_by_user_id`, `copied_at`
- yeni tablolar: `order_revisions`, `order_revision_changes`

### Test

- cok genis yeni/degisen test grubu var
- odak alanlari:
  - order revision
  - repeat order
  - promotion quote detail UI
  - public quote approval
  - phone/WhatsApp formatlama
  - liste tab sayaçlari ve filtreler

### CSS

- `public/css/prodelya-admin.css`

Tema:
- genis UI/layout degisikligi

### Config

- `config/admin_menu.php`

Tema:
- mevcut working tree icinde menu etkisi var
- ayrica `b8729d5 -> 8eafa19` arasi da phase 1 menu/layout farklari bulunuyor

### Docs

- `docs/10.15.18-C-revizyonu-uygula-teknik-karar-plani.md`

## 4. DB ve Migration Durumu

- Aktif DB baglantisi: `sqlite`
- `.env` icinde `DB_DATABASE` tanimli degil
- `config/database.php` fallback'ine gore aktif SQLite dosyasi: `database/database.sqlite`
- Tespit edilen aktif DB dosyasi:
  - `C:\laragon\www\prodelya_core\database\database.sqlite`
  - boyut: yaklasik `486 MB`
  - son yazim zamani: `2026-07-08 17:01`
- `php artisan migrate:status` sonucuna gore pending migration yok
- Asagidaki migration dosyalari dosya sisteminde mevcut ve status'te `Ran` gorunuyor:
  - `2026_07_08_120000_add_order_copy_metadata_to_orders_table`
  - `2026_07_08_150000_create_order_revisions_tables`

### Kritik tablo durumu

- `orders`: mevcut
- `order_revisions`: mevcut
- `order_revision_changes`: mevcut
- `current_accounts`: mevcut
- `product_data_hub_sync_runs`: mevcut
- `product_data_hub_sync_changes`: mevcut
- `standard_products`: mevcut
- `tenant_catalog_products`: mevcut
- `promotion_quotes`: fiziksel tablo yok

Not:
Kod taramasina gore teklif alani buyuk olcude `orders` modeli/tablasi uzerinden yurutuluyor. Bu nedenle `promotion_quotes` tablosunun fiziksel olarak olmamasi tek basina bir bozulma kaniti degil.

### Orders tablosunda yeni alanlar

Mevcut DB'de su kolonlar bulundu:

- `source_order_id`
- `copy_type`
- `revision_number`
- `copied_by_user_id`
- `copied_at`

### Kod/DB uyumsuzluk riski

- Mevcut local kod icin:
  - dusuk-orta
  - cunku revizyon migration'lari DB'de zaten calismis ve gerekli tablolar/alanlar mevcut
- `8eafa19` koduna DB dokunmadan donulurse:
  - dusuk
  - cunku DB'deki ekstra tablo/kolonlar additive nitelikte; eski kodun bunlari kullanmamasi genelde sorun cikarmayacaktir
- Partial/selective rollback yapilirsa:
  - orta-yuksek
  - cunku route/controller/view/service setleri birbirine bagli

## 5. Risk Analizi

### Dusuk riskler

- Sadece `8eafa19` checkpoint commit'ine referans vermek, cunku HEAD zaten orada
- DB'ye dokunmadan additive kolon ve tablolarin sistemde kalmasi
- Product Data Hub ve cari tablolarinin fiziksel varligi acisindan hemen bir eksik gorunmemesi

### Orta riskler

- Selective rollback sirasinda route-controller-view uyumsuzlugu
- Public quote approval ekranlarinda UI/notification degisikligi kaynakli regresyon
- WhatsApp/telefon formatlama degisikliginin eski ekranlarla kismi uyumsuzlugu
- Menu/layout ve quote/order ekranlarinin ayni CSS dosyasina yaslanmasi nedeniyle UI yan etkileri

### Yuksek riskler

- `38` modified + `120` untracked dosya uzerinden yedek almadan hard rollback yapmak
- Revizyon akislarinin bir kismini alip bir kismini birakmak
- DB restore yapmadan derin kod fallback ile davranissal kopus yaratmak
- `git reset --hard`, `git checkout <commit>`, dosya silme gibi islerle untracked yeni kodu kaybetmek

### Veri kaybi riski

- Kod veri kaybi riski: yuksek
  - ozellikle untracked servisler, migration'lar, mail blade'leri ve testler icin
- Is verisi kaybi riski: orta
  - DB restore yapilmazsa is verisi korunur
  - ancak DB restore yapilacaksa en guncel order/current account/product data kayitlari kaybolabilir

### Tenant izolasyonu riski

- Orta
- Revizyon/compare tarafinda tenant izolasyon testleri yazilmis
- Ancak selective rollback ile route/service/model seti yarim kalirsa tenantlar arasi yetki akisi beklenmedik davranabilir

### Finans/cari riski

- Dusuk-orta
- `current_accounts` tablosu mevcut
- Working tree degisiklikleri dogrudan cari modulu degil daha cok order/quote/revision/public approval tarafini etkiliyor
- Buna ragmen order tamamlanma ve finans takibi ile ilgili yeni testler oldugu icin kismi rollbackte operasyon-finans iliskisi izlenmeli

### Product Data Hub riski

- Dusuk
- Mevcut working tree degisiklikleri Product Data Hub cekirdegine odakli degil
- Derin fallback `b8729d5` menu/layout seviyesinde super-admin erisim deneyimini dolayli etkileyebilir

## 6. Geri Alma Senaryolari

### Senaryo A: Secici rollback

- Ne zaman tercih edilmeli?
  - Sorun 1-3 dosya veya dar bir akisla sinirliysa
  - Ozellikle quote detail, public approval, CSS veya route bazli regresyon varsa
  - Yerel revizyon ozelligi tamamen kaybedilmek istenmiyorsa
- Hangi komutlar gerekir?
  - Once yedek:
    - `git diff > .tmp/safe-rollback-working-tree.patch`
    - `git ls-files --others --exclude-standard > .tmp/untracked-files.txt`
  - Sonra secili dosyalari once inceleme, sonra kontrollu geri alma:
    - `git diff -- app/Http/Controllers/Admin/PromotionQuoteController.php`
    - `git restore --source=HEAD -- app/Http/Controllers/Admin/PromotionQuoteController.php`
  - Untracked dosyalar icin silme yerine once arsiv:
    - `Compress-Archive -Path app/Services/OrderRevision* -DestinationPath .tmp/order-revision-services-backup.zip`
- Riskleri var?
  - Bagli dosyalardan biri atlanirsa uygulama yari-bozuk kalabilir
- DB uyumsuzlugu riski var mi?
  - Orta
  - Kodun bir kismi yeni kolonlari beklerken diger kismi eski davranirsa mantiksal kopus olabilir
- Geri donus sonrasi hangi smoke testler yapilmali?
  - `/admin/orders`
  - `/admin/promotion-quotes`
  - bir order detay ekrani
  - bir quote detay ekrani
  - revizyon/repeat-order butonlari

### Senaryo B: 8eafa19 kod rollback, DB'ye dokunmadan

- Ne zaman tercih edilmeli?
  - Yerel degisikliklerin tamami problemliyse
  - Amac en son bilinen saglam phase 1 koduna donmekse
- Hangi komutlar gerekir?
  - Once yedek:
    - `git diff > .tmp/safe-rollback-working-tree.patch`
    - `git ls-files --others --exclude-standard > .tmp/untracked-files.txt`
    - `Copy-Item database/database.sqlite .tmp/database-pre-rollback-20260709.sqlite`
  - Sonra onerilen uygulama komutlari:
    - `git restore --worktree --staged .`
    - untracked dosyalari tek tek arsivleyip sonra temizleme
  - Not:
    - HEAD zaten `8eafa19` oldugu icin hedef commit'e branch degistirmek gerekmez
- Riskleri var?
  - En buyuk risk yerel dosya kaybi
  - Route/view/controller seviyesindeki tum yeni iyilestirmeler kaybolur
- DB uyumsuzlugu riski var mi?
  - Dusuk
  - Mevcut bulgulara gore DB'deki ekstra schema eski koda toleransli gorunuyor
- Geri donus sonrasi hangi smoke testler yapilmali?
  - phase 1 menu/layout
  - admin dashboard ve orders/promotion-quotes liste ekranlari
  - tenant host dashboard/orders

### Senaryo C: 8eafa19 kod rollback + DB restore

- Ne zaman tercih edilmeli?
  - Ancak DB de mantiksal olarak bozulduysa
  - Uygulama davranisi sadece kod rollback ile duzelmiyorsa
  - Elinizde tarih olarak dogrulanmis saglam DB yedegi varsa
- Hangi komutlar gerekir?
  - Kod icin Senaryo B adimlari
  - DB icin:
    - `Copy-Item <safe-backup.sqlite> database/database.sqlite`
    - veya kurumun standart DB restore proseduru
- Riskleri var?
  - En yuksek is verisi kaybi riski bu senaryoda
  - 8 Temmuz sonrasi order, current account, PDH review/sync ve diger operasyon verileri kaybolabilir
- DB uyumsuzlugu riski var mi?
  - Dogru tarihli DB ile dusuk
  - Yanlis tarihli veya daha eski DB ile yuksek
- Geri donus sonrasi hangi smoke testler yapilmali?
  - URL smoke + kritik liste ekranlari
  - son is kayitlari, current account hareketleri, quote/order iliskileri
  - migration status

### Senaryo D: b8729d5 derin fallback

- Ne zaman tercih edilmeli?
  - Sadece cok ciddi sistemik bozulma varsa
  - `8eafa19` dahil phase 1 shell/menu katmani da problemli ise
- Hangi komutlar gerekir?
  - Once tam kod ve DB yedegi
  - Sonra commit hedefli restore/checkout proseduru
  - Gerekirse uygun tarihli DB yedegiyle eslestirme
- Riskleri var?
  - Menu/layout regresyonu
  - Daha genis davranissal fark
  - Kullanici akislarinda beklenmedik yan etki
- DB uyumsuzlugu riski var mi?
  - Orta-yuksek
  - Cunku kod daha eski olacak, DB ise yeni additive schema ile kalabilir
- Geri donus sonrasi hangi smoke testler yapilmali?
  - Tum admin dashboardlari
  - siparis/teklif akislari
  - tenant ve super-admin navigasyon
  - kritik permission kontrolleri

## 7. Onerilen Guvenli Yol

Net karar:

- Su anda tam rollback gerekli mi?
  - Hayir, mevcut bulgularla hemen tam rollback zorunlu gorunmuyor
- En guvenli hedef checkpoint hangisi?
  - Commit seviyesi icin zaten mevcut durum `8eafa19`
  - Bu nedenle en guvenli yol checkpoint degistirmek degil, once mevcut working tree'yi yedekleyip sorunlu alan varsa secici rollback degerlendirmek
- DB restore gerekli mi?
  - Hayir, mevcut audit bulgulariyla zorunlu gorunmuyor
- Once hangi yedekler alinmali?
  - `git diff` patch yedegi
  - untracked dosya listesi ve arsivi
  - `database/database.sqlite` kopyasi
  - ozellikle:
    - `app/Http/Controllers/Admin/OrderController.php`
    - `app/Http/Controllers/Admin/PromotionQuoteController.php`
    - `app/Models/Order.php`
    - `app/Services/OrderQuoteDraftCloneService.php`
    - `app/Services/OrderRevision*.php`
    - `database/migrations/2026_07_08_*`
    - `resources/views/admin/promotion-quotes/*`
    - `resources/views/public/quotes/approval/show.blade.php`
    - `public/css/prodelya-admin.css`
    - `routes/web.php`
- Hangi islem yapilmamali?
  - Yedek almadan `git reset --hard`
  - Yedek almadan commit/branch checkout
  - DB'nin tarih dogrulamasi olmadan restore
  - Revizyon akislarinin sadece bir kismini geri alip kalanini birakmak

Karar cumlesi:
En guvenli yol, su anda tam rollback yapmamak; once mevcut working tree ve SQLite dosyasini yedekleyip, gerekiyorsa Senaryo A ile secici rollback uygulamaktir. Tum local degisikliklerden vazgecilmesi gerekecekse ikinci tercih Senaryo B'dir. Bu audit bulgularina gore Senaryo C ve ozellikle Senaryo D ancak daha agir bozulma kaniti varsa dusunulmelidir.

## 8. Uygulanacak Komutlar Taslagi

Asagidaki komutlar oneridir. Bu audit fazinda calistirilmadi.

### Once yedek

```powershell
git diff > .tmp/safe-rollback-working-tree.patch
git ls-files --others --exclude-standard > .tmp/untracked-files.txt
Copy-Item database/database.sqlite .tmp/database-pre-rollback-20260709.sqlite
Compress-Archive -Path app/Services/OrderRevision*,app/Models/OrderRevision*,database/migrations/2026_07_08_* -DestinationPath .tmp/order-revision-local-backup.zip
```

### Secici rollback ornegi

```powershell
git diff -- app/Http/Controllers/Admin/PromotionQuoteController.php
git diff -- resources/views/admin/promotion-quotes/show.blade.php
git restore --source=HEAD -- app/Http/Controllers/Admin/PromotionQuoteController.php
git restore --source=HEAD -- resources/views/admin/promotion-quotes/show.blade.php
```

### 8eafa19 kod rollback taslagi

```powershell
# HEAD zaten 8eafa19 oldugu icin hedef commit degistirmeye gerek yok
git restore --worktree --staged .
# untracked dosyalar icin once arsiv al, sonra kontrollu temizlik yap
```

### DB dogrulama komutlari

```powershell
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan migrate:status
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter="OrderRevision|PromotionQuote|PublicQuoteApproval"
```

## 9. Geri Alma Sonrasi Smoke Plani

### Kontrol edilecek URL'ler

- `/admin/dashboard`
- `/admin/super-admin/dashboard`
- `/admin/orders`
- `/admin/promotion-quotes`
- `/admin/current-accounts`
- `/admin/super-admin/product-data-hub`
- tenant host altinda `/admin/dashboard`
- tenant host altinda `/admin/orders`

### Kontrol edilecek davranislar

- admin menu ve layout render oluyor mu
- orders listesi aciliyor mu
- order detay ekraninda tablar ve aksiyonlar gorunuyor mu
- promotion quotes listesi/detayi aciliyor mu
- public quote approval ekraninda kritik hata yok mu
- current account listesi aciliyor mu
- super-admin PDH ekranlari aciliyor mu
- tenant izolasyonu korunuyor mu

### Artisan/test onerileri

- `php artisan migrate:status`
- `php artisan test --filter="AdminMenuVisibilityTest|PromotionQuoteDetailTabsTest|PublicQuoteApprovalRouteTest"`
- `php artisan test --filter="OrderRevisionMigrationSafetyTest|OrderRevisionComparePageRendersTest|OrderRevisionApplyControllerTest"`

### Bu audit sirasinda calistirilan ornek testler

- `php artisan test --filter="OrderRevision(MigrationSafety|ComparePageRenders|ApplyController)|PromotionQuoteDetailTabsTest|PublicQuoteApprovalRouteTest|AdminMenuVisibilityTest"`
- Sonuc: `10` test gecti, `96` assertion, hata yok

## 10. Sonuc

Net karar:

- Tam rollback ile devam et: hayir
- Secici rollback yap: evet, sorun dar bir alanda ise ilk tercih bu olmali
- `8eafa19`'a don: ancak yerel calisma agacinin tamamindan vazgecilecekse
- `b8729d5`'e don: bu audit bulgularina gore su anda onerilmez; sadece cok ciddi bozulma varsa
- Once DB yedegi bulmadan islem yapma: evet, ozellikle herhangi bir DB restore ihtimali dogarsa bu zorunlu

Ozet:
Sistem commit seviyesinde zaten birincil saglam checkpoint olan `8eafa19` uzerinde. Bu nedenle asıl karar "hangi commit'e donelim?" degil, "mevcut yerel degisikliklerin hangisi korunacak?" sorusudur. Mevcut DB revizyon migration'lariyla uyumlu gorunuyor; bu audit verisine gore acil DB restore ihtiyaci yoktur. En guvenli operasyon sirasiyla: yedek al, sorunlu dosyalari daralt, gerekiyorsa secici rollback yap, ancak son care olarak tum local degisiklikleri temizle.
