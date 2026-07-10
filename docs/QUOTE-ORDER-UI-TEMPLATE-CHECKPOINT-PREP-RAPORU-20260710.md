# Quote / Order UI Template Checkpoint Prep Raporu — 2026-07-10

## 1. Ozet

- Yeni kod yazildi mi? Hayir.
- Staging/commit yapildi mi? Hayir.
- Product Hub'a dokunuldu mu? Hayir.
- Revision A-B-C'ye dokunuldu mu? Hayir.
- Public approval'a dokunuldu mu? Hayir.
- Notification service-core'a dokunuldu mu? Hayir.

Bu faz yalniz audit, hunk siniflandirma, test ve raporlama fazidir.

## 2. Kalan Git Durumu

Modified ana dosyalar:

- `app/Http/Controllers/Admin/PromotionQuoteController.php`
- `app/Http/Controllers/Admin/OrderController.php`
- `app/Models/Order.php`
- `resources/views/admin/promotion-quotes/index.blade.php`
- `resources/views/admin/promotion-quotes/show.blade.php`
- `resources/views/admin/orders/index.blade.php`
- `resources/views/admin/orders/show.blade.php`
- `public/css/prodelya-admin.css`
- `config/admin_menu.php`
- `routes/web.php`
- `resources/views/public/graphics/approval/show.blade.php`

Modified testler:

- `tests/Feature/PromotionQuoteAndOrderIndexHeaderPanelTest.php`
- `tests/Feature/PromotionQuoteAndOrderIndexUxTest.php`
- `tests/Feature/PromotionQuoteConvertCtaTest.php`
- `tests/Feature/PromotionQuoteCreateEditUiRegressionTest.php`
- `tests/Feature/PromotionQuoteDetailCustomerApprovalUxTest.php`
- `tests/Feature/PromotionQuoteSalesStartScreenTest.php`
- `tests/Feature/PromotionQuoteSendActionsUxTest.php`
- `tests/Feature/PromotionQuoteShowDecisionScreenTest.php`
- `tests/Feature/OrderDetailOperationalFlowUxTest.php`
- `tests/Feature/OrderShowTabbedLayoutTest.php`
- `tests/Feature/PublicGraphicApprovalRouteTest.php`
- `tests/Feature/TenantProductCatalogMenuSimplificationTest.php`

Untracked quote/order UI testleri:

- quote list/detail ve order list/detail ile ilgili cok sayida yeni test dosyasi var
- ozellikle `PromotionQuoteDetail*`, `Quote*List*`, `Order*Completed*`, `OrderList*`, `RevisionAndRepeatOrderSourceReferenceTest.php`

Untracked docs:

- baska checkpoint ve master plan dokumanlari mevcut
- bu fazin disinda kalmali

Ortak dosya riskleri:

- `PromotionQuoteController.php`: cok yuksek
- `PromotionQuote show.blade.php`: cok yuksek
- `public/css/prodelya-admin.css`: cok yuksek
- `Order.php`: orta-yuksek

Ek not:

- worktree'de anlamsiz gorunen iki untracked dosya var: `how origin` ve `tatus -sb`
- bunlara bu fazda dokunulmadi; rapor disinda birakilmalidir

## 3. Degisiklik Gruplari

### Teklif liste

- `PromotionQuoteController::index()`
- `resources/views/admin/promotion-quotes/index.blade.php`
- `Order::scopeConvertedQuotes()`
- `Order::scopeArchivedQuotes()`
- `Order::scopeActiveQuotes()`
- ilgili quote list testleri

Sinif: A

### Teklif detay

- `PromotionQuoteController::show()`
- `resources/views/admin/promotion-quotes/show.blade.php`
- sag panel, sticky alt bar, tabs, action alanlari, detay metrikleri
- quote detail layout ve urun/baski gorunumu

Sinif: B

### Teklif create/edit

- bu turn diff gorunmuyor
- `create.blade.php`, `edit.blade.php`, `_form-workspace.blade.php` ve `revision-compare.blade.php` icin unstaged diff saptanmadi
- bu nedenle bu fazda aktif checkpoint malzemesi degil

Sinif: C ve K'ya aday ama su an worktree hedefi degil

### Siparis liste

- `OrderController::index()`
- `resources/views/admin/orders/index.blade.php`
- `Order` liste yardimci kapsamlarindan siparis tarafini destekleyen kisimlar
- completed/open/all ayrimi

Sinif: D

### Siparis detay

- `OrderController::show()`
- `resources/views/admin/orders/show.blade.php`
- operasyon merkezi, module link kartlari, tablar, sag panel

Sinif: E

### Send-channel UI

- `PromotionQuoteController::sendToCustomer()`
- `PromotionQuoteController::openWhatsappLink()`
- `PromotionQuoteController::buildSendSuccessMessage()`
- `PromotionQuoteController::normalizeSendRecipientData()`
- `resources/views/admin/promotion-quotes/show.blade.php` icindeki gonderim modali ve helper JS

Sinif: F

### Public approval admin UI

- quote detail icindeki approval summary / approval panel / approval helper link
- public page veya mail core degil; admin UI kalintisi

Sinif: G

### Revision/repeat UI

- `buildSourceOrderContext()`
- `revisionCompareUrl`
- order detail ve quote detail icindeki revision/repeat baglanti alanlari

Sinif: H

### CSS/template

- `public/css/prodelya-admin.css`
- `pd-order-*`
- `promotion-quote-detail.quote-detail-compact *`
- `quote-send-modal*`
- `order-revision-compare*`
- genel `pd-*` design token ve primitive katmani

Sinif: J

### Yeni template master planina birakilacaklar

- yeni PDF template istekleri
- yeni public page/mail template revizyonlari
- cari/finans/uretim/tedarik ekranlari icin yeni template tasarimlari
- henuz worktree'de olmayan yeni tasarim fikirleri

Sinif: K

## 4. PromotionQuoteController Analizi

Dosya: [PromotionQuoteController.php](/C:/laragon/www/prodelya_core/app/Http/Controllers/Admin/PromotionQuoteController.php)

### `index()`

- Grup: A teklif liste UI
- Beklenen checkpoint: quote/order UI
- Hala worktree'de kalmasi dogru mu? Evet
- UI checkpoint'e alinabilir mi? Evet
- Template master planina birakilmali mi? Hayir
- Risk: orta
- Test karsiligi:
  - `PromotionQuoteSalesStartScreenTest.php`
  - `ActiveQuotesHideConvertedOrdersTest.php`
  - `ConvertedQuotesListTest.php`
  - `QuoteArchivedStatusesAuditTest.php`
  - `QuoteListTabCountersTest.php`
  - `QuoteAndOrderSortingNewestFirstTest.php`

Not:

- `filter=active|converted|archived|all`
- converted teklifleri aktif listeden ayirma
- sayac ve durum mantigi bu checkpointin temiz adayi

### `create()`

- Grup: C teklif create/edit
- Bu turn unstaged degisiklik gorunmuyor
- UI checkpoint malzemesi degil
- Risk: dusuk

### `edit()`

- Grup: C + H
- Mevcut diff daha cok revision/source-order context baglantisi
- Revision cekirdegi commitlenmis durumda; kalan UI baglami ayri degerlendirilmeli
- Hala worktree'de kalmasi kabul edilebilir
- Risk: orta

### `show()`

- Grup: B teklif detay UI, G public approval admin UI, H revision/repeat UI
- Beklenen checkpoint: quote detail UI checkpoint
- UI checkpoint'e alinabilir mi? Evet, ama hunk ayrimi gerekir
- Template master planina birakilmali mi? Hayir, mevcut worktree change oldugu icin once checkpointlenmeli
- Risk: cok yuksek
- Test karsiligi:
  - `PromotionQuoteShowDecisionScreenTest.php`
  - `PromotionQuoteDetail*` test ailesi
  - `PromotionQuoteDetailCustomerApprovalUxTest.php`

Not:

- send modal, approval panelleri, tab yapisi, sticky alt bar ve buyuk layout ayni diffte
- bu nedenle dosya bazli commit yanlis olur

### `sendToCustomer()`

- Grup: F send-channel UI wiring
- Beklenen checkpoint: notification Commit C veya quote detail UI commit'i
- Hala worktree'de kalmasi dogru mu? Evet, cunku bilincli olarak onceki checkpointte alinmadi
- UI checkpoint'e alinabilir mi? Evet
- Template master planina birakilmali mi? Hayir
- Risk: yuksek
- Test karsiligi:
  - `PromotionQuoteSendChannelHotfixTest.php`
  - `PromotionQuoteSendActionsUxTest.php`
  - `PromotionQuoteDetailSendChannelUiTest.php`
  - `PromotionQuoteDetailSendHotfixRegressionTest.php`

### `openWhatsappLink()`

- Grup: F
- Beklenen checkpoint: send-channel UI/controller wiring
- UI checkpoint'e alinabilir mi? Evet
- Risk: yuksek
- Test karsiligi:
  - `PromotionQuoteSendChannelHotfixTest.php`
  - `PromotionQuoteDetailWhatsappUiRuleTest.php`

### `openCustomerApproval()`

- Grup: G public approval admin UI
- Beklenen checkpoint: quote detail/admin UI
- Public approval core ile karistirilmamali
- UI checkpoint'e alinabilir mi? Yalniz admin UI davranisiysa evet
- Risk: orta-yuksek

### `buildSendSuccessMessage()`

- Grup: F
- Beklenen checkpoint: send-channel UI/controller wiring
- UI checkpoint'e alinabilir mi? Evet
- Risk: orta
- Test karsiligi:
  - `PromotionQuoteSendActionsUxTest.php`

### `normalizeSendRecipientData()`

- Grup: F
- Beklenen checkpoint: send-channel UI/controller wiring
- UI checkpoint'e alinabilir mi? Evet
- Risk: orta
- Test karsiligi:
  - `PromotionQuoteSendChannelHotfixTest.php`
  - `PromotionQuoteDetailPhoneHelperTextTest.php`

### `buildWarningPayload()`

- Grup: I Product Hub kalintilari
- Beklenen checkpoint: Product Hub
- Hala worktree'de kalmasi dogru mu? Hayir, bu faz acisindan disarida tutulmali
- UI checkpoint'e alinmamali
- Template master planina da birakilmamali; Product Hub cekirdegiyle karistirmamak gerekir
- Risk: yuksek
- Test karsiligi:
  - `TenantProductCatalogMenuSimplificationTest.php`
  - `ProductHubLiveProductInfoEndpointTest`
  - `PromotionQuoteLiveProductInfoUiTest`

### `revisionCompare()`

- Grup: H ama cekirdek revision davranisi
- Beklenen checkpoint: Revision A-B-C
- Bu fazda tekrar alinmamali
- Risk: yuksek

### `applyRevision()`

- Grup: H ama cekirdek revision davranisi
- Beklenen checkpoint: Revision A-B-C
- Bu fazda tekrar alinmamali
- Risk: yuksek

### `buildSourceOrderContext()`

- Grup: H revision/repeat UI
- UI checkpoint'e alinabilir mi? Evet, ama sadece UI baglaminda gerekiyorsa
- Revision core ile karismamali
- Risk: orta-yuksek
- Test karsiligi:
  - `RevisionAndRepeatOrderSourceReferenceTest.php`

### Diger helperlar

- quote detail support helperlari buyuk UI/approval/send-modal baglaminda dagilmis durumda
- method bazli secici staging gerekir

## 5. OrderController ve Order Model Analizi

Dosyalar:

- [OrderController.php](/C:/laragon/www/prodelya_core/app/Http/Controllers/Admin/OrderController.php)
- [Order.php](/C:/laragon/www/prodelya_core/app/Models/Order.php)

### `OrderController::index()`

- Grup: D siparis liste UI
- `filter` parametresi, open/completed/all gorunumu, tabCounts, queueRows
- UI checkpoint'e temiz alinabilir
- Risk: orta
- Test karsiligi:
  - `CompletedOrdersListTest.php`
  - `ActiveOrdersHideCompletedOrdersTest.php`
  - `OrderListTabCountersTest.php`
  - `QuoteAndOrderSortingNewestFirstTest.php`

### `OrderController::show()`

- Grup: E siparis detay UI / operasyon merkezi
- aktif tab, delivery context, action linkleri, diger islemler, operation center veri hazirlama
- UI checkpoint'e alinabilir
- Risk: yuksek
- Test karsiligi:
  - `OrderDetailOperationalFlowUxTest.php`
  - `OrderShowTabbedLayoutTest.php`
  - `OrderCompletedDecisionSafetyTest.php`

### `createRevisionDraft()` / `createRepeatOrderDraft()` / `createCopiedQuoteDraft()`

- Grup: H revision/repeat UI baglantisi
- Cekirdek davranis onceki checkpointte commitlenmis olmali
- Bu worktree'de gorunen fark daha cok UI/direction tarafinda degerlendirilmeli
- Ayrica `Order.php` copy helpers ile birlikte dusunulmeli
- Risk: orta-yuksek

### `Order.php` quote kapsamlar

- `scopeConvertedQuotes()`
- `scopeArchivedQuotes()`
- `scopeActiveQuotes()`

Sinif:

- A teklif liste UI'yi destekleyen model kapsamlar

Karar:

- quote list commitine alinabilir
- notification/public approval/revision core ile karismiyor
- risk orta

### `Order.php` revision/repeat iliskileri ve helperlari

- `sourceOrder()`
- `copiedQuoteDrafts()`
- `revisions()`
- `latestRevision()`
- `orderRevision()`
- `revisionRecord()`
- `isRevisionDraft()`
- `isRepeatOrderDraft()`
- `copyTypeLabel()`
- `copyTypeWarning()`

Sinif:

- H revision/repeat UI kalintisi ve cekirdek yardimcilari

Karar:

- bunlarin buyuk bolumu onceki checkpoint mantigina bagli
- bu fazda tekrar commitlenmemeli
- ancak detail ekranlar bu helperlari kullaniyorsa UI checkpoint planinda "disarida tut" notuyla ilerlenmeli

### `Order.php` siparis liste/durum yardimcilari

- mevcut diffte ana yeni is, quote scopes tarafinda
- siparis completed/open ayrimina iliskin davranis `OrderController` ve summary service katmaninda daha baskin

## 6. Blade Template Analizi

### `promotion-quotes/index.blade.php`

- Grup: A teklif liste UI
- Icerik:
  - active / converted / archived / all gorunumu
  - chip/counter alanlari
  - converted teklifleri ayirma
  - yeni bos durum metinleri
- Notification ile ilgisiz kisim: neredeyse dosyanin tamami
- Karar:
  - quote list commitine temiz alinabilir
- Risk: dusuk-orta

### `promotion-quotes/show.blade.php`

- Grup: B + F + G + H
- Icerik:
  - genel sayfa layout
  - urun satirlari / baski satirlari kompakt yapilar
  - musteri onayi alani
  - gonderim modali
  - revizyon / repeat linkleri
  - sticky action bar
  - tabs
  - sag panel
- Karar:
  - tek committe dosya bazli alinmamali
  - quote detail, send-channel UI ve public approval admin UI birbirine karismis
- Risk: cok yuksek

### `promotion-quotes/create/edit/form`

- bu fazda unstaged diff yok
- aktif commit-plani malzemesi degil
- yeni template cleanup ancak sonraki asamada dusunulmeli

### `orders/index.blade.php`

- Grup: D siparis liste UI
- Icerik:
  - aktif/tamamlanan siparis ayrimi
  - filtre ve chipler
  - sayaclar
  - bos durum ve selected row paneli
- Karar:
  - order list commitine uygun
- Risk: orta

### `orders/show.blade.php`

- Grup: E + H
- Icerik:
  - operasyon merkezi
  - grafik/tedarik/uretim/teslimat/finans linkleri
  - revision/repeat actions
  - status kartlari
  - sag panel
  - tabs
  - sticky action alanlari
- Karar:
  - order detail commitine uygun, ama CSS ve route/context destekleriyle birlikte alinmali
- Risk: yuksek

## 7. CSS Analizi

Dosya: [prodelya-admin.css](/C:/laragon/www/prodelya_core/public/css/prodelya-admin.css)

Siniflandirma:

- quote list CSS:
  - `pd-quote-*` liste/workspace kalintilari
  - `poi-*` inline order/quote list sayfa bloklari ile etkileşimli

- quote detail CSS:
  - `promotion-quote-detail.quote-detail-compact`
  - `quote-*`, `quote-send-modal*`, `quote-bottom-bar`, `quote-tabs`, `quote-right-summary`

- order list CSS:
  - `pd-orders-index-*`
  - `poi-*` sayfa ici stil bloklariyla birlikte dusunulmeli

- order detail CSS:
  - `pd-order-*`
  - `pd-order-flow-*`
  - `pd-order-summary-panel`, `pd-order-tab*`

- send modal CSS:
  - `quote-send-modal*`
  - `quote-channel-pill`

- public approval CSS:
  - quote detail icindeki approval card ve summary bileşenlerine degiyor
  - ayrik bir namespace degil, quote detail blogu icinde

- revision compare CSS:
  - `order-revision-compare*`
  - bu checkpointte disarida tutulmali

- Product Hub CSS:
  - `pd-product-hub`
  - ilgili product diagnostic / hub classlari
  - disarida tutulmali

- generic design token / pd-* primitive CSS:
  - ust tarafta root tokens, `pd-btn`, `pd-card`, `pd-badge`, `pd-tabs`, `pd-modal`, `pd-sticky-bar`

Degerlendirme:

- Quote/order UI checkpoint'e alinabilecek bloklar:
  - `pd-orders-*`
  - `pd-order-*`
  - `promotion-quote-detail.quote-detail-compact` altindaki quote detail namespace
  - `quote-send-modal*`

- Daha once alinmis olmali veya disarida kalmali bloklar:
  - `pd-product-hub*`
  - `order-revision-compare*`
  - Product Hub ile ilgili diagnostic bloklar

- Global selector riski var mi?
  - Evet
  - dosyanin basinda `html`, `body`, `a`, `table`, `td`, `textarea` gibi global selectorlar var
  - `rounded`, `border-*` utility benzeri selectorlar da var
  - `pd-btn`, `pd-card`, `pd-badge`, `pd-tabs`, `pd-modal` tum admin genelini etkiler

- Radius/spacing Prodelya standardiyla uyumlu mu?
  - Genel olarak uyumlu
  - 8px / pill / panel mantigi tutarli
  - ancak tek dosyada cok farkli donemlerin CSS'i birikmis

- Buyuk CSS tek committe alinmali mi?
  - Hayir
  - en azindan su sekilde bolunmeli:
    - quote detail + send modal CSS
    - order detail + order list CSS
    - gerekiyorsa quote/order shared `pd-*` primitive ekleri

## 8. Commit Plani

### Commit A

- Mesaj: `quotes: refine quote list views and filters`
- Dosyalar:
  - `app/Http/Controllers/Admin/PromotionQuoteController.php` icinden yalniz `index()` hunks
  - `app/Models/Order.php` icinden `scopeConvertedQuotes`, `scopeArchivedQuotes`, `scopeActiveQuotes`
  - `resources/views/admin/promotion-quotes/index.blade.php`
  - ilgili quote list testleri
- Hunk notu:
  - tekliflerin active/converted/archived/all ayrimi
  - converted tekliflerin aktif listeden cikarilmasi
- Disarida birakilacaklar:
  - `show()` ile ilgili her sey
  - Product Hub warning metinleri
  - revision/send-channel helperlari
- Risk: orta
- Test onerisi:
  - `php artisan test --filter="PromotionQuote|QuoteList|ConvertedQuotes|ArchivedQuotes"`

### Commit B

- Mesaj: `orders: refine order list views and completed filters`
- Dosyalar:
  - `app/Http/Controllers/Admin/OrderController.php` icinden yalniz `index()` hunks
  - `resources/views/admin/orders/index.blade.php`
  - gerekiyorsa `Order.php` siparis listeyi destekleyen minimal hunklar
  - ilgili order list testleri
- Hunk notu:
  - aktif/tamamlanan/all filtreleri
  - tab counts ve queueRows
- Disarida birakilacaklar:
  - `show()` operasyon merkezi
  - repeat/revision draft action wiring
- Risk: orta
- Test onerisi:
  - `php artisan test --filter="Order|CompletedOrders|OrderList"`

### Commit C

- Mesaj: `quotes: improve quote detail send and approval actions`
- Dosyalar:
  - `PromotionQuoteController.php` icinden send-channel ve admin approval UI hunks
  - `resources/views/admin/promotion-quotes/show.blade.php` icinden gonderim modali, action alanlari, approval admin UI bloklari
  - ilgili send-channel testleri
- Hunk notu:
  - Notification checkpointten kalan Commit C wiring'i burada kapanabilir
- Disarida birakilacaklar:
  - revision compare/apply core
  - Product Hub warning metinleri
  - buyuk quote detail layout CSS hunklari
- Risk: cok yuksek
- Test onerisi:
  - `php artisan test --filter="PromotionQuoteSendChannelHotfix|PromotionQuoteSendActionsUx|PromotionQuoteDetailSend|PromotionQuoteDetailWhatsapp|PromotionQuoteDetailPhone|PublicQuoteApproval|QuoteApproval"`

### Commit D

- Mesaj: `orders: turn order detail into operation flow center`
- Dosyalar:
  - `app/Http/Controllers/Admin/OrderController.php` icinden `show()` ve ilgili operation context hunks
  - `resources/views/admin/orders/show.blade.php`
  - ilgili order detail testleri
- Hunk notu:
  - operasyon merkezi kartlari, tablar, sag panel, module linkleri
- Disarida birakilacaklar:
  - repeat/revision core davranis degisiklikleri
- Risk: yuksek
- Test onerisi:
  - `php artisan test --filter="OrderDetailOperationalFlowUx|OrderShowTabbedLayout|OrderCompletedDecisionSafety|FullOperationalFlowSmokeTest"`

### Commit E

- Mesaj: `ui: add quote order template styles`
- Dosyalar:
  - `public/css/prodelya-admin.css` icinden yalniz quote/order namespace bloklari
- Hunk notu:
  - quote detail, order detail, send modal, order list stilleri ayri ayri secilmeli
- Disarida birakilacaklar:
  - `pd-product-hub*`
  - `order-revision-compare*`
  - riskli global utility degisiklikleri
- Risk: cok yuksek
- Test onerisi:
  - hedeflenen feature testler + manuel smoke

### Commit F

- Mesaj: `docs: add quote order UI template checkpoint reports`
- Dosyalar:
  - bu prep raporu
  - ileride varsa apply raporu
- Risk: dusuk

Net teknik not:

- Eger difflar zor ayrisiyorsa once A ve B alinmali
- C ve D ayni turda alinmamali
- E en sona kalmali

## 9. Commit'e Alinmayacaklar

- Product Hub core
- Revision A-B-C core
- Public approval page/mail/template core
- Notification/WhatsApp/Phone service-core
- `database.sqlite`
- `.env`
- `.tmp`
- `storage`
- `vendor`
- `node_modules`
- log/screenshot/debug dosyalari
- ilgisiz docs
- yeni template master planina ait henuz uygulanmamis talepler
- `config/admin_menu.php`
- `routes/web.php`
- `resources/views/public/graphics/approval/show.blade.php`

## 10. Test Sonuclari

1. `php artisan test --filter="PromotionQuote"`
   - passed, 126 test / 1190 assertion

2. `php artisan test --filter="Order"`
   - passed, 212 test / 1771 assertion

3. `php artisan test --filter="OrderRevision|RepeatOrder"`
   - passed, 51 test / 314 assertion

4. `php artisan test --filter="PromotionQuoteSendChannelHotfix|PromotionQuoteSendActionsUx|PromotionQuoteDetailSend|PromotionQuoteDetailWhatsapp|PromotionQuoteDetailPhone"`
   - passed, 20 test / 113 assertion

5. `php artisan test --filter="OrderDetailOperationalFlowUx|OrderShowTabbedLayout"`
   - passed, 4 test / 52 assertion

6. `php artisan test --filter="PublicQuoteApproval|QuoteApproval"`
   - passed, 34 test / 324 assertion

7. `php artisan test --filter="NotificationPublicApprovalTokenSanitization|QuoteNotificationIntegration|WhatsappLinkUsesNormalizedPhone"`
   - passed, 7 test / 67 assertion

8. `php artisan test --filter="ProductHubLiveProductInfoEndpointTest|PromotionQuoteLiveProductInfoUiTest"`
   - passed, 14 test / 111 assertion

9. `php artisan test --filter="AdminSmokeTest|FullOperationalFlowSmokeTest"`
   - passed, 60 test / 644 assertion

Genel sonuc:

- mevcut kalan worktree degisiklikleri davranissal olarak stabil gorunuyor
- asil risk davranis degil, hunk ayrisma ve ortak dosya karisikligi

## 11. Smoke Plani / Smoke Sonucu

Bu fazda manuel browser smoke uygulanmadi.

Onerilen manuel smoke plani:

1. `/admin/promotion-quotes`
2. aktif teklifler gorunumu
3. siparise donusmus teklifler gorunumu
4. arsivlenmis teklifler gorunumu
5. `/admin/promotion-quotes/{id}`
6. gonderim modali
7. e-posta onizleme
8. WhatsApp link
9. musteri onayi linki
10. revizyon baglantisi
11. `/admin/orders`
12. acik siparisler
13. tamamlanmis siparisler
14. `/admin/orders/{id}`
15. operasyon sureci linkleri
16. revizyon/repeat order baglantilari
17. grafik/tedarik/uretim/teslimat/finans linkleri
18. Product Hub canli urun bilgisi etkilenmemis mi
19. public approval guest link etkilenmemis mi

## 12. Kalan Riskler

- Ortak controller riski:
  - `PromotionQuoteController.php` icinde quote list, quote detail, send-channel, approval admin UI, revision ve Product Hub baglamlari ayni diffte

- Buyuk CSS riski:
  - `prodelya-admin.css` tek parca staged edilirse ilgisiz Product Hub, revision compare ve global primitive degisiklikleri de surukler

- Template master plan cakismasi:
  - mevcut worktree kapatilmadan yeni template tasarimina gecilirse diff katmanlari daha da karisir

- Full suite durumu:
  - full suite calistirilmadi
  - fakat hedeflenmis genis regresyon matrisi temiz

- Migration/DB durumu:
  - bu fazda migration veya DB islemi yok

## 13. Net Karar

Net oneri:

- Once yalniz quote list / order list commitleri yapilmali

Gerekce:

- Commit A ve B en temiz ayrisan kisimlar
- Quote detail, send-channel UI ve order detail kisimlari ortak controller/blade/CSS riskleri nedeniyle daha ince hunk planina ihtiyac duyuyor
- Yeni template degisikliklerine gecmeden mevcut UI checkpoint kapatilmalidir

Ikincil not:

- quote detail ve order detail icin ayrica `QUOTE-ORDER-UI-HUNK-STAGING-PREP` yapmak en guvenli yol olur

## 14. Sonraki Adim

- kullanici onayi sonrasi `QUOTE-ORDER-UI-HUNK-STAGING-PREP`

Alternatif:

- eger hizli ilerlemek istenirse once `QUOTE-ORDER-UI-TEMPLATE-CHECKPOINT-COMMIT-APPLY` icinde sadece Commit A ve Commit B uygulanabilir

Yeni template tasarimi veya yeni ozellik gelistirme, mevcut worktree guvenli checkpointlere ayrildiktan sonra baslatilmalidir.
