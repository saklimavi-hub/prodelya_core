# Quote / Order UI Hunk Staging Prep Raporu — 2026-07-10

## 1. Ozet
- Yeni kod yazildi mi?: Hayir
- Staging/commit yapildi mi?: Hayir
- Product Hub'a dokunuldu mu?: Hayir
- Revision A-B-C'ye dokunuldu mu?: Hayir
- Public approval'a dokunuldu mu?: Hayir
- Notification service-core'a dokunuldu mu?: Hayir
- Quote/order list checkpoint'e dokunuldu mu?: Hayir

## 2. Kalan Git Durumu
- Toplam worktree satiri: 65
- Modified: 20
- Untracked: 45
- Quote detail dosyalari:
  - `app/Http/Controllers/Admin/PromotionQuoteController.php`
  - `resources/views/admin/promotion-quotes/show.blade.php`
  - ilgili modified/untracked `PromotionQuoteDetail*`, `PromotionQuoteShowDecisionScreenTest.php`, `PromotionQuoteConvertCtaTest.php`
- Order detail dosyalari:
  - `app/Http/Controllers/Admin/OrderController.php`
  - `resources/views/admin/orders/show.blade.php`
  - ilgili modified/untracked `OrderDetailOperationalFlowUxTest.php`, `OrderShowTabbedLayoutTest.php`, `OrderCompletedDecisionSafetyTest.php`
- Send-channel dosyalari:
  - `app/Http/Controllers/Admin/PromotionQuoteController.php`
  - `resources/views/admin/promotion-quotes/show.blade.php`
  - `public/css/prodelya-admin.css`
  - ilgili `PromotionQuoteSendActionsUxTest.php`, `PromotionQuoteDetailSend*`, `PromotionQuoteDetailWhatsapp*`, `PromotionQuoteDetailPhone*`
- CSS dosyalari:
  - `public/css/prodelya-admin.css`
- Docs/test dosyalari:
  - untracked docs raporlari ve planlar
  - untracked quote detail/order detail testleri
- Quote/order index dosyalari:
  - `resources/views/admin/promotion-quotes/index.blade.php`: kalan hunk yok
  - `resources/views/admin/orders/index.blade.php`: kalan hunk yok

## 3. PromotionQuoteController Hunk Analizi
- Quote detail:
  - `show()` icinde `sourceOrderContext`, `revisionCompareUrl`, notification log ozetleri ve detail view data genisletmeleri var.
  - Grup: quote detail + approval admin UI + revision/repeat UI karisimi
  - Commit adayligi: `quotes: refine quote detail send and approval actions`
  - Guvenli ayrisma: Orta. `show()` icinde send/approval/revision context ayni return payload'da toplaniyor; method-icinden secici patch gerekir.
  - Risk: Yuksek
  - Koruyan testler: `PromotionQuoteShowDecisionScreenTest`, `PromotionQuoteDetail*`, `RevisionAndRepeatOrderSourceReferenceTest`
- Send-channel:
  - `buildSendSuccessMessage()`, `normalizeSendRecipientData()`, `openWhatsappLink()`, `sendToCustomer()`
  - Kapsam: `sent_channel` ayrimi, email preview, whatsapp_link akisi, telefon/e-posta fallback, `whatsapp_result` session
  - Commit adayligi: quote detail commitine girebilir ama daha guvenli yol ayri mini prep
  - Guvenli ayrisma: Evet, method bazli ayrisiyor
  - Risk: Cok yuksek. Notification/public approval baglantilarina dokunuyor gibi gorunmese de runtime akis yogun
  - Koruyan testler: `PromotionQuoteSendActionsUxTest`, `PromotionQuoteDetailSendChannelUiTest`, `PromotionQuoteDetailWhatsappUiRuleTest`, `PromotionQuoteDetailPhoneHelperTextTest`, `PromotionQuoteSendChannelHotfixTest`
- Public approval admin UI:
  - `openCustomerApproval()` bu diffte method-govdesi degismiyor; ancak `show()` icine approval helper/context ve send summary baglanti verileri tasiniyor.
  - Commit adayligi: quote detail commiti icinde admin approval UI
  - Guvenli ayrisma: Orta
  - Risk: Yuksek, cunku send modal ve approval panel ayni blade'de ic ice
  - Koruyan testler: `PromotionQuoteDetailCustomerApprovalUxTest`, `PublicQuoteApproval|QuoteApproval`, `PromotionQuoteShowDecisionScreenTest`
- Revision/repeat UI:
  - `buildSourceOrderContext()`, `canAccessRevisionCompare()`, `show()` ve `edit()` icindeki `revisionCompareUrl`
  - Commit adayligi: quote detail commitine alinabilir; ayri revision core commitine gerek yok
  - Guvenli ayrisma: Evet, ama `show()` return array ve blade banner/linkleri birlikte alinmali
  - Risk: Orta
  - Koruyan testler: `RevisionAndRepeatOrderSourceReferenceTest`, `OrderRevision|RepeatOrder`
- Disarida kalacaklar:
  - `revisionCompare()`, `applyRevision()`, `buildRevisionApplySummary()`, `revisionApplyInfrastructureReady()`
  - Product Hub warning/metin hunklari `buildWarningPayload()`
  - index/list hunklari artik commitlendi
  - BOM/whitespace churn

## 4. promotion-quotes/show.blade.php Hunk Analizi
- Quote detail layout:
  - `promotion-quote-detail quote-detail-compact` ana kabugu, topbar gizleme, quote strip, metrikler, urun & baski tabi, right summary, sticky bottom bar
  - Commit: quote detail commiti
  - Guvenli patch staging: Evet, fakat buyuk bir tekil blade oldugu icin blok bazli patch sart
- Send modal:
  - `#quoteSendModal`, channel pills, hidden `sent_channel`, preview textarea, helper text, `channelValues`, modal JS, escape/backdrop logic
  - Commit: quote detail/send-channel commiti
  - Guvenli patch staging: Evet, controller send hunklariyla birlikte alinmali
- Public approval admin UI:
  - approval tab/panel, admin tarafinda public link acma ve approval summary bloklari
  - Commit: quote detail commiti
  - Guvenli patch staging: Evet, ama send modal ile ayni tab sistemine bagli
- Revision/repeat UI:
  - `sourceOrderContext` banner, `revisionCompareUrl` butonu, kaynak siparis uyarilari
  - Commit: quote detail commiti veya ayri mini hunk prep
  - Guvenli patch staging: Evet
- Disarida kalacaklar:
  - Product Hub canli urun bilgisiyle ilgili bir hunk bu blade diffinde hedef disi
  - guest public approval page ile ilgili hunk yok
  - tam dosya yerine blok-bazli patch onerilir; cunku send modal, detail layout ve revision linkleri ayni dosyada

## 5. OrderController Hunk Analizi
- Order detail operasyon merkezi:
  - `show()` icine eklenen `canCreateQuoteDraft`
  - Commit adayligi: `orders: turn order detail into operation flow center`
  - Guvenli ayrisma: Evet. `show()` icinde tek net veri genislemesi bu
  - Risk: Orta
  - Koruyan testler: `OrderDetailOperationalFlowUxTest`, `OrderShowTabbedLayoutTest`
- Revision / repeat UI:
  - `createRevisionDraft()`, `createRepeatOrderDraft()`, `createCopiedQuoteDraft()` diffte gorunen kisimler davranissal olarak daha onceki checkpointle iliskili; kalan fark agirlikla whitespace churn
  - Commit adayligi: Bu fazda disarida
  - Risk: Yuksek, core action davranisiyla karisabilir
  - Koruyan testler: `OrderRevision|RepeatOrder`, `RevisionAndRepeatOrderSourceReferenceTest`
- Disarida kalacaklar:
  - `index()` artik commitlendi
  - revision/repeat core action davranisi
  - whitespace churn

## 6. orders/show.blade.php Hunk Analizi
- Order detail operasyon merkezi:
  - sayfa basligi ve aksiyonlari
  - `statusLine`, `flowCards`, `priorityFlowCard`, `quickLinks`, `warnings`
  - yeni `Siparis Ozeti`, `Siparis Kalemleri`, `Finans Ozeti`, `Siparis Akisi`, `Kisa Ozet` bloklari
  - sag panelin operasyon merkezi davranisina donusmesi
  - Commit: order detail commiti
  - Guvenli patch staging: Evet. Bu dosya order detail icin gorece temiz ayrisiyor
  - Risk: Yuksek ama quote detail'den daha temiz
- Revision / repeat UI:
  - `canCreateQuoteDraft` kosullu `Revizyon Olustur` ve `Tekrar Siparis Olustur` formlari
  - Commit: order detail commiti icine alinabilir
  - Guvenli patch staging: Evet
  - Risk: Orta
- Disarida kalacaklar:
  - index/list bloklari yok
  - core revision/repeat behavior yok; yalniz link/form UI var

## 7. CSS Hunk Analizi
- Quote detail CSS:
  - `promotion-quote-detail.quote-detail-compact` namespace'i yaklasik `5017+` satirlarindan basliyor
  - quote layout, quote strip, metrics, tabs, item/print rows, right summary, sticky bar
  - Commit: `ui: add quote order detail template styles`
  - Blok siniri: Buyuk oranda net
  - Global risk: Orta. Ayni diffte root token ve ortak button/badge/card degisiklikleri de var
  - Dosya bazli staging: Hayir
  - Patch staging: Evet, zorunlu
- Send modal CSS:
  - `quote-send-modal`, `quote-channel-pill`, modal head/body/actions, helper/preview stilleri
  - Commit: CSS commiti; quote detail CSS ile birlikte alinabilir
  - Blok siniri: Net
  - Global risk: Dusuk-orta
  - Dosya bazli staging: Hayir
  - Patch staging: Evet
- Order detail CSS:
  - `pd-order-layout`, `pd-order-tabs`, `pd-order-summary-panel`, `pd-order-flow-*`, `pd-order-summary-grid`, `pd-order-item-table`
  - Commit: order detail CSS veya ortak CSS commiti
  - Blok siniri: Cogu net
  - Global risk: Orta
  - Dosya bazli staging: Hayir
  - Patch staging: Evet
- Shared quote/order UI CSS:
  - `:root` token ekleri, `.pd-btn*`, `.pd-badge*`, `.pd-tabs`, `.pd-modal`, `.pd-sticky-bar`, `.pd-card` iyilestirmeleri
  - Commit: CSS en sona birakilmali
  - Blok siniri: Tam net degil; quote/order detail namespace disina tasiyor
  - Global risk: Cok yuksek
  - Dosya bazli staging: Hayir
  - Patch staging: Evet, dikkatli
- Disarida kalacaklar:
  - `.pd-product-hub*`
  - `.order-revision-compare*`
  - public graphic approval guest stil alanlari
  - global reset benzeri genis selector degisiklikleri, zorunlu olmadikca

## 8. routes/web.php / config/admin_menu.php Analizi
- `routes/web.php`:
  - Detail UI icin zorunlu yeni route yok
  - Diffte gorev-disi iki grup var:
    - revision/repeat route satirlari cogu whitespace churn
    - `catalog.search` Product Hub ile ilgili yeni route
  - Karar: Bu faza alinmamali
- `config/admin_menu.php`:
  - Finans menusu etiket/izin duzeyi ve Product Hub etiket encoding duzeltmesi
  - Quote/order detail UI icin zorunlu degil
  - Karar: Bu faza alinmamali
- `resources/views/public/graphics/approval/show.blade.php`:
  - Public guest route binding duzeltmesi
  - Quote/order detail prep ile ilgili degil
  - Karar: Bu faza alinmamali

## 9. Net Commit Plani
- Commit A
  - Mesaj: `quotes: refine quote detail send and approval actions`
  - Dosyalar:
    - `app/Http/Controllers/Admin/PromotionQuoteController.php`
    - `resources/views/admin/promotion-quotes/show.blade.php`
    - ilgili `PromotionQuoteDetail*`, `PromotionQuoteSend*`, `PromotionQuoteShowDecisionScreenTest.php`, `PromotionQuoteConvertCtaTest.php`
    - muhtemelen `tests/Feature/Concerns/BuildsPromotionQuoteDetailFixture.php`
  - Hunk notu:
    - `show()` icinden quote detail + approval + revision context
    - send-channel methodleri ayni committe veya once mini prep ile
    - blade icinden detail layout + send modal + approval tab + revision banner
  - Disarida birakilacaklar:
    - Product Hub warning metinleri
    - `revisionCompare/applyRevision` core
    - BOM/encoding churn
  - Risk: Cok yuksek
  - Test onerisi:
    - `PromotionQuoteDetail|PromotionQuoteShowDecisionScreen|PromotionQuoteConvertCta`
    - `PromotionQuoteSendChannelHotfix|PromotionQuoteSendActionsUx|PromotionQuoteDetailSend|PromotionQuoteDetailWhatsapp|PromotionQuoteDetailPhone`
    - `PromotionQuote`
- Commit B
  - Mesaj: `orders: turn order detail into operation flow center`
  - Dosyalar:
    - `app/Http/Controllers/Admin/OrderController.php` icinden `show()` hunku
    - `resources/views/admin/orders/show.blade.php`
    - `OrderDetailOperationalFlowUxTest.php`
    - `OrderShowTabbedLayoutTest.php`
    - `OrderCompletedDecisionSafetyTest.php`
  - Hunk notu:
    - operasyon merkezi kartlari, sag panel, quick links, revizyon/tekrar siparis UI butonlari
  - Disarida birakilacaklar:
    - revision/repeat core action davranislari
    - whitespace churn
  - Risk: Yuksek ama en temiz ayrisan grup
  - Test onerisi:
    - `OrderDetailOperationalFlowUx|OrderShowTabbedLayout|OrderCompletedDecisionSafety`
    - `Order`
    - `OrderRevision|RepeatOrder`
- Commit C
  - Mesaj: `ui: add quote order detail template styles`
  - Dosyalar:
    - `public/css/prodelya-admin.css` icinden yalniz quote detail, send modal ve order detail namespace bloklari
  - Hunk notu:
    - namespace tabanli selectorlar alinmali
    - paylasilan `.pd-btn/.pd-badge/.pd-card/:root` hunklari zorunlu degilse ayri tutulmali
  - Disarida birakilacaklar:
    - `.pd-product-hub*`
    - `.order-revision-compare*`
    - global token/reset churn
  - Risk: Cok yuksek
  - Test onerisi:
    - `PromotionQuoteDetail*`
    - `OrderDetailOperationalFlowUx|OrderShowTabbedLayout`
    - `AdminSmokeTest|FullOperationalFlowSmokeTest`
- Commit D
  - Mesaj: `docs: add quote order UI hunk staging report`
  - Dosyalar:
    - `docs/QUOTE-ORDER-UI-HUNK-STAGING-PREP-RAPORU-20260710.md`
  - Hunk notu: apply sonrasi ayrica docs commit olabilir
  - Risk: Dusuk

## 10. Commit'e Alinmayacaklar
- `routes/web.php`
- `config/admin_menu.php`
- `resources/views/public/graphics/approval/show.blade.php`
- Product Hub route/uyari/metin hunklari
- revision compare/apply core davranisi
- public approval guest page core'u
- global CSS token/reset churn, zorunlu olmadikca
- liste/index checkpoint ile ilgili hicbir dosya

## 11. Test Sonuclari
- `PromotionQuoteDetail|PromotionQuoteShowDecisionScreen|PromotionQuoteConvertCta`: gecti, 42 test, 335 assertion
- `PromotionQuoteSendChannelHotfix|PromotionQuoteSendActionsUx|PromotionQuoteDetailSend|PromotionQuoteDetailWhatsapp|PromotionQuoteDetailPhone`: gecti, 20 test, 113 assertion
- `OrderDetailOperationalFlowUx|OrderShowTabbedLayout|OrderCompletedDecisionSafety`: gecti, 5 test, 55 assertion
- `PromotionQuote`: gecti, 126 test, 1190 assertion
- `Order`: gecti, 212 test, 1771 assertion
- `OrderRevision|RepeatOrder`: gecti, 51 test, 314 assertion
- `PublicQuoteApproval|QuoteApproval`: gecti, 34 test, 324 assertion
- `NotificationPublicApprovalTokenSanitization|QuoteNotificationIntegration|WhatsappLinkUsesNormalizedPhone`: gecti, 7 test, 67 assertion
- `ProductHubLiveProductInfoEndpointTest|PromotionQuoteLiveProductInfoUiTest`: gecti, 14 test, 111 assertion
- `AdminSmokeTest|FullOperationalFlowSmokeTest`: gecti, 60 test, 644 assertion

## 12. Smoke Plani
- `/admin/promotion-quotes/{id}`
- quote detail ust ozet
- urun/baski kompakt gorunum
- gonderim modali
- e-posta preview
- WhatsApp link
- musteri onayi linki
- revizyon karsilastirma baglantisi
- kaynak siparis banneri
- `/admin/orders/{id}`
- operation center
- grafik linki
- tedarik linki
- uretim linki
- teslimat linki
- finans linki
- revizyon/tekrar siparis alanlari
- sag panel
- sticky action bar
- public approval guest link etkilenmemis mi?
- Product Hub canli urun bilgisi etkilenmemis mi?

## 13. Kalan Riskler
- Quote detail karmasikligi: `show.blade.php` ve `PromotionQuoteController.php` icinde detail/send/approval/revision bloklari ic ice
- Send-channel karmasikligi: controller akis ve modal JS birlikte alinmali
- CSS global risk: `public/css/prodelya-admin.css` icinde namespace disina tasan ortak token/button/badge degisiklikleri var
- Route/menu karismasi: `routes/web.php` ve `config/admin_menu.php` ayni worktree'de ama bu checkpointle ilgili degil
- Full suite durumu: istenen hedef test matrisi gecti, tam suite yine de calistirilmadi

## 14. Net Karar
- Once yalniz `order detail` commit'i yapilmali.
- Quote detail/send-channel hala daha karmasik ve kendi icinde ayri mini hunk prep fayda saglar.
- CSS ayri birakilmali ve en sona patch staging ile alinmali.

## 15. Sonraki Adim
- `ORDER-DETAIL-CHECKPOINT-COMMIT-APPLY`
- veya `QUOTE-DETAIL-SEND-CHANNEL-HUNK-STAGING-PREP`

Son not:
Bu faz sonunda commit apply icin en temiz siralama:
1. Order detail
2. Quote detail + send-channel
3. CSS
