# CSS Quote Detail Send Modal Hunk Staging Prep Raporu — 2026-07-10

## 1. Faz Amaci

Bu faz yeni gelistirme, staging veya commit fazi degildir.

Bu fazin amaci sadece `public/css/prodelya-admin.css` icindeki quote detail + send modal CSS alanini analiz etmek, kapsam risklerini belirlemek ve bir sonraki checkpoint icin guvenli karar raporu uretmektir.

## 2. Kontrol Edilen Git Durumu

### Staged alan

`git diff --cached --stat` cikisi bostur.

Net sonuc:
- staged alan temizdir
- bu faz baslangicinda bekleyen staged hunk yoktur

### Worktree durumu

`git status --short` ozeti:

- modified:
  - `app/Http/Controllers/Admin/OrderController.php`
  - `app/Http/Controllers/Admin/PromotionQuoteController.php`
  - `app/Models/Order.php`
  - `config/admin_menu.php`
  - `public/css/prodelya-admin.css`
  - `routes/web.php`
  - `tests/Feature/PromotionQuoteAndOrderIndexHeaderPanelTest.php`
  - `tests/Feature/PromotionQuoteAndOrderIndexUxTest.php`
- untracked:
  - `.tmp_quote_detail_commit_target.blade.php`
  - `.tmp_quote_detail_show_worktree_backup.blade.php`
  - cesitli mevcut docs/test dosyalari

`git diff --stat` ozeti:

- `public/css/prodelya-admin.css` degisimi cok buyuktur
- toplam degisim yalniz CSS ile sinirli degildir
- bu nedenle tek parca CSS checkpoint cikarimi dikkatli ayrim gerektirir

## 3. Incelenen CSS Kapsami

Inceleme `public/css/prodelya-admin.css` icinde yapildi.

### A. Quote detail cekirdek alani

Asagidaki secici gruplari quote detail cekirdek alanina dahildir:

- `.promotion-quote-detail.quote-detail-compact`
- `.quote-page-head`
- `.quote-strip`
- `.quote-top-metrics`
- `.quote-layout`
- `.quote-right-summary`
- `.quote-tabs`
- `.quote-tab-button`
- `.quote-bottom-bar`
- `.quote-action-band`
- `.promotion-quote-lines*`
- compact urun/print satir stilleri
- quote detail head/log/summary yerlesimleri

Bu bloklar agirlikli olarak yaklasik `5017-6031` satir bandinda toplanmistir.

### B. Send modal alani

Asagidaki secici gruplari send modal kapsaminda bulunmustur:

- `.quote-send-modal`
- `.quote-send-modal-panel`
- `.quote-send-modal-head`
- `.quote-send-modal-body`
- `.quote-send-modal-grid`
- `.quote-send-form-grid`
- `.quote-send-modal-actions`
- `.quote-channel-pill`
- `.quote-send-grid`
- preview/helper/input/textarea alanlari
- modal backdrop ve open-state bloklari

Bu bloklar agirlikli olarak yaklasik `6037-6111` satir bandinda bulunmaktadir; ancak oncesindeki grid, pill ve quick-action bloklariyla dogrudan baglidir.

### C. Quote detail icine gomulu approval/revision/send surface alani

Asagidaki seciciler ayni CSS bolgesinde quote detail deneyiminin parcasi olarak yer almaktadir:

- `.quote-alert*`
- `.quote-log-list`
- `.quote-history-row`
- `.quote-decision-list`
- `.quote-send-card`
- `.quote-summary-line`
- source-order / revision / action-band baglantili satirlar

Bu stiller quote detail ekraninin icinde kullanim baglamina sahip oldugu icin tamamen ayri dusunulmesi zor gorunmektedir.

## 4. Paylasilan ve Riskli Primitive Alanlar

Asagidaki seciciler quote detail fazina ozel gorunmemekte, daha genis admin UI primitive katmanina ait gorunmektedir:

- `:root`
- `html`
- `body`
- `a`
- `table`
- `input`
- `button`
- `.pd-btn`
- `.pd-card`
- `.pd-badge`
- `.pd-tabs`
- `.pd-modal`
- `.pd-sticky-bar`
- `body.pd-modal-open`

Bu primitive alanlar quote detail ile iliskili olsa da yalniz quote detail/send modal checkpointine dahil edilirse:

- Product Hub
- Order detail
- quote/order list
- revision compare
- diger admin yuzeyleri

uzerinde istenmeyen yan etki riski olusabilir.

Net tespit:
- quote detail/send modal bloklari buyuk oranda ayni namespace altinda toplanmistir
- ancak paylasilan primitive katman ayni commit kapsaminda guvenle alinabilecek kadar dar degildir

## 5. Acikca Kapsam Disi Alanlar

Asagidaki CSS bolgeleri bu faz icin kapsam disi kabul edilmistir:

- Product Hub / request hub stilleri
- order detail stilleri
- quote/order list stilleri
- revision compare stilleri
- public graphic approval temizligiyle ilgili stiller

Kod dosyalari tarafinda da su alanlar kapsam disidir:

- `routes/web.php`
- `config/admin_menu.php`
- blade/php/controller/test degisiklikleri

## 6. Test Sonuclari

Asagidaki test filtreleri bu faz kapsaminda calistirildi ve tamami gecti:

- `PromotionQuoteDetail|PromotionQuoteShowDecisionScreen|PromotionQuoteConvertCta`
  - 42 test, 335 assertion, passed
- `PromotionQuoteSendChannelHotfix|PromotionQuoteSendActionsUx|PromotionQuoteDetailSend|PromotionQuoteDetailWhatsapp|PromotionQuoteDetailPhone`
  - 20 test, 113 assertion, passed
- `PromotionQuoteDetailCustomerApprovalUx|PublicQuoteApproval|QuoteApproval`
  - 38 test, 379 assertion, passed
- `PromotionQuote`
  - 126 test, 1190 assertion, passed
- `Order`
  - 212 test, 1771 assertion, passed
- `AdminSmokeTest|FullOperationalFlowSmokeTest`
  - 60 test, 644 assertion, passed

Toplam:

- 498 test passed
- 4432 assertion passed

## 7. Dar Hunk Acisindan Degerlendirme

Mevcut gorunum su sekildedir:

- quote detail + send modal CSS kendi icinde buyuk oranda tek bir bolgede toplanmis
- approval/revision/send card stilleri bu bolgeye organik olarak bagli
- responsive bloklar da ayni alana dagilmis
- buna karsin global primitive katman ayni committe alinmaya uygun gorunmuyor

Bu nedenle dogrudan “tum quote detail + send modal CSS” secimi yapmak yerine:

- sadece `.promotion-quote-detail*`, `.quote-*` ve send modal namespace’ine ait bloklar
- bunlara bagli responsive satirlar
- fakat global `.pd-*` primitive degisiklikleri disarida kalacak sekilde

daha dar bir CSS hunk prep yapilmasi daha guvenlidir.

## 8. Net Karar

- `CSS quote detail + send modal apply yapilabilir` karari bu haliyle fazla genistir
- `once daha dar CSS hunk prep gerekir` karari uygundur
- `once order detail CSS alinmali` zorunlulugu icin teknik kanit gorulmedi
- `CSS Template Master Plan’dan sonraya birakilmali` zorunlulugu icin de guclu gerekce yoktur

Sonuc:

**En guvenli yon: once daha dar CSS hunk prep gerekir.**

Onerilen bir sonraki adim:

- `CSS-QUOTE-DETAIL-SEND-MODAL` icin sadece quote detail namespace ve send modal namespace’i hedefleyen secici bazli daraltma yapilsin
- global `.pd-*` primitive degisiklikleri ayrica degerlendirilsin

## 9. Faz Uygunluk Ozeti

- Yeni kod/CSS yazildi mi?
  - Hayir
- Staging yapildi mi?
  - Hayir
- Commit atildi mi?
  - Hayir
- Rollback/silme yapildi mi?
  - Hayir
- Testler calistirildi mi?
  - Evet
- Bir sonraki asamaya gecilebilir mi?
  - Evet, ancak daraltilmis CSS hunk prep ile gecilmelidir
