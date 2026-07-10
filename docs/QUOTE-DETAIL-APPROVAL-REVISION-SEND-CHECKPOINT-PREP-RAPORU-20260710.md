# Quote Detail Approval Revision Send Checkpoint Prep Raporu — 2026-07-10

## 1. Özet
- Yeni kod yazıldı mı?: Hayır.
- Staging/commit yapıldı mı?: Hayır.
- Staged area boş mu?: Evet. `git diff --cached --stat` ve `git diff --cached --name-status` boş döndü.
- Worktree korundu mu?: Evet. Hiçbir rollback, restore, reset veya silme yapılmadı.
- Kalıcı özellikler duruyor mu?: Evet. Quote detail send/approval/revision/source-order yüzeyleri çalışma kopyasında duruyor.

## 2. Git Durumu
- Staged: Boş.
- Modified:
  - `app/Http/Controllers/Admin/OrderController.php`
  - `app/Http/Controllers/Admin/PromotionQuoteController.php`
  - `app/Models/Order.php`
  - `config/admin_menu.php`
  - `public/css/prodelya-admin.css`
  - `resources/views/admin/promotion-quotes/show.blade.php`
  - `resources/views/public/graphics/approval/show.blade.php`
  - `routes/web.php`
  - çeşitli quote/order/public approval/product hub test dosyaları
- Untracked:
  - `.tmp_quote_detail_commit_target.blade.php`
  - `.tmp_quote_detail_show_worktree_backup.blade.php`
  - bu ve önceki checkpoint raporları
  - çok sayıda quote detail / quote order test dosyası
- Riskli dosyalar:
  - `app/Http/Controllers/Admin/PromotionQuoteController.php`
  - `resources/views/admin/promotion-quotes/show.blade.php`
  - `public/css/prodelya-admin.css`
  - `routes/web.php`
  - `config/admin_menu.php`
  - `resources/views/public/graphics/approval/show.blade.php`
- `git diff --stat` özeti: 17 tracked dosyada geniş diff var; özellikle CSS ve blade tarafında büyük churn bulunuyor.

## 3. Kalıcı Özellik Kontrolü
- `quoteSendModal`: Var. Blade içinde modal bloğu ve JS bağları mevcut.
- `sent_channel`: Var. Hidden input ve kanal akışı mevcut.
- `WhatsApp Link`: Var. Blade ve JS içinde mevcut.
- `E-posta Önizleme`: Var. Blade ve JS içinde mevcut.
- `Tekrar Gönder`: Doğrudan literal her yerde görünmüyor; ancak `sendActionLabel` üzerinden dinamik yüzey mevcut ve test beklentisi bu yüzeyi kullanıyor.
- `Public Onay Linkini Aç`: Var.
- `Müşteri Onayı`: Var.
- `revisionCompareUrl`: Var.
- `sourceOrderContext`: Var.
- `sendToCustomer()`: Var.
- `openWhatsappLink()`: Var.
- `buildSendSuccessMessage()`: Var.
- `normalizeSendRecipientData()`: Var.

Sonuç:
- Çalışma kopyasında feature kaybı tespit edilmedi.
- Mevcut sorun recovery ihtiyacı değil; checkpoint scope’unun fazla dar kurulmuş olması.

## 4. PromotionQuoteController Analizi

### `show()`
- Gerçek mantıksal delta var mı?: Evet.
- Unified checkpoint’e alınmalı mı?: Evet, ama yalnız quote detail birleşik UI yüzeyini besleyen view-data kısmı.
- Gerekli yüzeyler:
  - `showSendAction`
  - `sendActionLabel`
  - `approvalHelperUrl`
  - `recipientPhone`
  - `sendNotificationSummary`
  - `notificationLogRows`
  - `whatsappAvailable`
  - `whatsappReady`
  - `quotePdfAvailable`
  - `sourceOrderContext`
  - `revisionCompareUrl`
- Patch staging yöntemi:
  - Method içinden seçici hunk staging.
  - Whitespace/BOM churn dışarıda bırakılmalı.
- Koruyan testler:
  - `PromotionQuoteDetailCustomerApprovalUxTest`
  - `PromotionQuoteApprovalAdminUiTest`
  - `PromotionQuoteDetailReferenceStructureTest`
  - `PromotionQuoteDetailResponsiveStructureTest`
  - `PromotionQuoteDetailTabsTest`
  - `OrderRevisionComparePageRendersTest`
- Risk: Orta. Aynı method içinde approval, send, revision ve layout verileri iç içe.

### `sendToCustomer()`
- Gerçek mantıksal delta var mı?: Evet.
- Unified checkpoint’e alınmalı mı?: Tek başına layout commitine değil; ayrı send-controller commitine daha uygun.
- İçerik:
  - `sent_channel` ayrımı
  - `manual`, `email`, `whatsapp_link`
  - `force_email_preview`
  - `skip_email_send`
  - `skip_whatsapp_dispatch`
  - e-posta / telefon zorunlulukları
  - `whatsapp_result`
- Patch staging yöntemi:
  - Method bazlı seçici staging.
- Koruyan testler:
  - `PromotionQuoteSendActionsUxTest`
  - `PromotionQuoteDetailSendChannelUiTest`
  - `PromotionQuoteDetailWhatsappUiRuleTest`
  - `PromotionQuoteDetailPhoneHelperTextTest`
  - `QuoteNotificationIntegration` ilişkili akışlar
- Risk: Orta-yüksek. Runtime davranışını değiştiriyor.

### `openWhatsappLink()`
- Gerçek mantıksal delta var mı?: Evet.
- Unified checkpoint’e alınmalı mı?: Hayır, send-controller commitine alınmalı.
- Koruyan testler:
  - `PromotionQuoteSendActionsUxTest`
  - `WhatsappLinkUsesNormalizedPhone`
- Risk: Orta.

### `buildSendSuccessMessage()`
- Gerçek mantıksal delta var mı?: Evet.
- Unified checkpoint’e alınmalı mı?: Hayır, send-controller commitine alınmalı.
- Koruyan testler:
  - send action / notification summary testleri
- Risk: Düşük-orta.

### `normalizeSendRecipientData()`
- Gerçek mantıksal delta var mı?: Evet.
- Unified checkpoint’e alınmalı mı?: Hayır, send-controller commitine alınmalı.
- Koruyan testler:
  - `WhatsappLinkUsesNormalizedPhone`
  - `QuoteNotificationIntegration`
- Risk: Orta.

### `openCustomerApproval()`
- Gerçek mantıksal delta var mı?: Bu prep açısından belirgin yeni delta görünmüyor; mevcut approval yüzeyi için referans method.
- Unified checkpoint’e alınmalı mı?: Hayır, mevcut scope’ta zorunlu görünmüyor.
- Koruyan testler:
  - approval admin / public approval akışları
- Risk: Düşük.

### `buildSourceOrderContext()`
- Gerçek mantıksal delta var mı?: Evet.
- Unified checkpoint’e alınmalı mı?: Evet. Quote detail kaynak sipariş/repeat-order yüzeyi için gerekli.
- Koruyan testler:
  - `OrderRevisionComparePageRendersTest`
  - `RevisionAndRepeatOrderSourceReferenceTest`
- Risk: Orta.

### `canAccessRevisionCompare()`
- Gerçek mantıksal delta var mı?: Evet.
- Unified checkpoint’e alınmalı mı?: Evet, yalnız link görünürlüğünü besleyen kadar.
- Koruyan testler:
  - `OrderRevisionComparePageRendersTest`
- Risk: Orta.

### `edit()`
- Gerçek mantıksal delta var mı?: Evet.
- Unified checkpoint’e alınmalı mı?: Evet, yalnız `sourceOrderContext` ve `revisionCompareUrl` gibi quote detail referans yüzeyini besleyen bölüm.
- Koruyan testler:
  - `OrderRevisionComparePageRendersTest`
- Risk: Orta.

### `buildWarningPayload()`
- Gerçek mantıksal delta var mı?: Evet.
- Unified checkpoint’e alınmalı mı?: Hayır.
- Neden dışarıda?: Product Hub metin ve warning yüzeyi ile ilişkili.
- Koruyan testler:
  - Product Hub test kümesi
- Risk: Yüksek çapraz-scope riski.

### `revisionCompare()`
- Gerçek mantıksal delta var mı?: Evet.
- Unified checkpoint’e alınmalı mı?: Hayır.
- Neden dışarıda?: Revision core.
- Risk: Yüksek.

### `applyRevision()`
- Gerçek mantıksal delta var mı?: Evet.
- Unified checkpoint’e alınmalı mı?: Hayır.
- Neden dışarıda?: Revision core.
- Risk: Yüksek.

### `buildRevisionApplySummary()`
- Gerçek mantıksal delta var mı?: Evet.
- Unified checkpoint’e alınmalı mı?: Hayır.
- Neden dışarıda?: Revision apply core.
- Risk: Yüksek.

### `revisionApplyInfrastructureReady()`
- Gerçek mantıksal delta var mı?: Evet.
- Unified checkpoint’e alınmalı mı?: Hayır.
- Neden dışarıda?: Revision infrastructure core.
- Risk: Yüksek.

### `index()`
- Gerçek mantıksal delta var mı?: Ayrı liste checkpointine ait.
- Unified checkpoint’e alınmalı mı?: Hayır.
- Neden dışarıda?: Quote/order list checkpoint scope’u dışında.
- Risk: Yüksek çapraz-scope riski.

## 5. `promotion-quotes/show.blade.php` Unified UI Analizi

### Layout
- Kapsam:
  - `page_topbar_hidden`
  - `quote-page-head`
  - `quote-strip`
  - üst metrikler
  - kompakt ürün/baskı satır yapısı
  - sağ özet paneli
  - tab yapısı
  - `quote-bottom-bar`
  - convert CTA / convert modal layout
- Unified checkpoint’e alınmalı mı?: Evet.
- Blok sınırı net mi?: Büyük ölçüde evet, fakat send/approval/revision yüzeyleriyle aynı blade içinde iç içe.
- Koruyan testler:
  - `PromotionQuoteDetailReferenceStructureTest`
  - `PromotionQuoteDetailResponsiveStructureTest`
  - `PromotionQuoteShowDecisionScreenTest`
  - `PromotionQuoteConvertCtaTest`
- CSS bağımlılığı var mı?: Test geçişi için düşük; görsel kalite için yüksek.

### Send / resend UI
- Kapsam:
  - `quoteSendModal`
  - kanal pill’leri
  - hidden `sent_channel`
  - alıcı adı/e-posta/telefon alanları
  - telefon helper text
  - `E-posta Önizleme`
  - `WhatsApp Link`
  - `Standart Gönderim`
  - dinamik `Tekrar Gönder`
  - `channelValues`
  - modal JS
- Unified checkpoint’e alınmalı mı?: Evet, görünüm yüzeyi olarak.
- Ayrı commit mi?: Görünüm yüzeyi Commit A, runtime controller Commit B.
- Blok sınırı net mi?: Orta. Blade ve controller view-data bağı var.
- Koruyan testler:
  - `PromotionQuoteDetailSendChannelUiTest`
  - `PromotionQuoteDetailWhatsappUiRuleTest`
  - `PromotionQuoteDetailPhoneHelperTextTest`
  - `PromotionQuoteSendActionsUxTest`
- CSS bağımlılığı var mı?: Test geçişi için düşük.

### Public approval admin UI
- Kapsam:
  - `Müşteri Onayı` karar bandı
  - `Gönderilmedi / Görüldü / Onaylandı / Revize İstendi / Reddedildi`
  - `Public Onay Linkini Aç`
  - approval request summary
  - response history
  - güvenli helper text
- Unified checkpoint’e alınmalı mı?: Evet.
- Blok sınırı net mi?: Evet.
- Koruyan testler:
  - `PromotionQuoteDetailCustomerApprovalUxTest`
  - `PromotionQuoteApprovalAdminUiTest`
- CSS bağımlılığı var mı?: Düşük.

### Revision / source-order UI
- Kapsam:
  - `sourceOrderContext` banner
  - kaynak sipariş yüzeyi
  - `revisionCompareUrl`
  - `Revizyon Karşılaştır` butonu
  - quote detail görünümünde yer alan repeat/revision referansları
- Unified checkpoint’e alınmalı mı?: Evet, yalnız görünüm/link yüzeyi olarak.
- Ayrı commit mi?: Hayır; unified quote detail yüzeyinin parçası.
- Blok sınırı net mi?: Orta. Link yüzeyi ile revision core birbirine yakın.
- Koruyan testler:
  - `OrderRevisionComparePageRendersTest`
  - `RevisionAndRepeatOrderSourceReferenceTest`
- CSS bağımlılığı var mı?: Düşük.

### Dışarıda kalacaklar
- `resources/views/public/graphics/approval/show.blade.php`
- Product Hub canlı ürün bilgisi yüzeyleri
- order detail yüzeyleri
- quote/order list yüzeyleri
- CSS

## 6. Test Kırılma Eşlemesi

### `PromotionQuoteDetailCustomerApprovalUxTest`
- Beklediği blok: approval karar bandı, `Müşteri Onayı`, durum metinleri, `Public Onay Linkini Aç`, `Tekrar Gönder`.
- Layout-only neden yetmedi?: Approval yüzeyi send/resend ve decision bağlamıyla birlikte test ediliyor.
- Unified checkpoint içinde gereken hunk: blade approval karar bandı + `show()` view-data.
- Controller hunkı gerekiyor mu?: Evet, `show()` içindeki approval/send bağlamı.
- CSS gerekiyor mu?: Hayır.

### `PromotionQuoteApprovalAdminUiTest`
- Beklediği blok: admin approval görünümü, gönderim geçmişi, tekrar gönder yüzeyi.
- Layout-only neden yetmedi?: Yalnız iskelet değil, action ve status yüzeyi bekliyor.
- Gereken hunk: blade approval/send yüzeyi + `show()` view-data.
- Controller gerekiyor mu?: Evet.
- CSS gerekiyor mu?: Hayır.

### `PromotionQuoteDetailPhoneHelperTextTest`
- Beklediği blok: telefon helper text.
- Layout-only neden yetmedi?: Send modal bloğu birlikte alınmadığında literal kayboluyor.
- Gereken hunk: blade send modal.
- Controller gerekiyor mu?: Hayır.
- CSS gerekiyor mu?: Hayır.

### `PromotionQuoteDetailReferenceStructureTest`
- Beklediği blok: `quote-strip`, `quote-top-metrics`, `quote-tabs`, `quote-right-summary`, `quote-bottom-bar`, `quoteSendModal`.
- Layout-only neden yetmedi?: Modal ve reference yüzeyi dışarıda kalmıştı.
- Gereken hunk: blade unified layout + send modal.
- Controller gerekiyor mu?: Kısmen, tab/action durumları için `show()` verisi faydalı.
- CSS gerekiyor mu?: Hayır.

### `PromotionQuoteDetailResponsiveStructureTest`
- Beklediği blok: `quote-detail-compact`, `quote-priority-block`, `quote-send-card`, `quote-right-summary`, `quoteSendModal`.
- Layout-only neden yetmedi?: Send/priority/right-summary blokları birlikte değerlendirilmiş.
- Gereken hunk: blade unified layout + send yüzeyi.
- Controller gerekiyor mu?: Düşük seviyede.
- CSS gerekiyor mu?: Hayır.

### `PromotionQuoteDetailSendChannelUiTest`
- Beklediği blok: `Standart Gönderim`, `E-posta Önizleme`, `WhatsApp Link`, `Gönder / Link Oluştur`.
- Layout-only neden yetmedi?: Kanal seçimi yüzeyi dışarıda kaldı.
- Gereken hunk: blade send modal + send action label yüzeyi.
- Controller gerekiyor mu?: Runtime testleri için ayrı committe gerekir.
- CSS gerekiyor mu?: Hayır.

### `PromotionQuoteDetailTabsTest`
- Beklediği blok: `Ürün & Baskı`, `Gönderim`, `Müşteri Onayı`, `Geçmiş`, `Notlar`.
- Layout-only neden yetmedi?: Tab yüzeyi unified akışın parçası; approval/send tabları hariç bırakılınca test kırılıyor.
- Gereken hunk: blade tab yapısı.
- Controller gerekiyor mu?: Düşük.
- CSS gerekiyor mu?: Hayır.

### `PromotionQuoteDetailWhatsappUiRuleTest`
- Beklediği blok: WhatsApp helper metni ve telefon alanı kuralı.
- Layout-only neden yetmedi?: Send modal yoksa literal yok.
- Gereken hunk: blade send modal.
- Controller gerekiyor mu?: Runtime için ayrı commit gerekir.
- CSS gerekiyor mu?: Hayır.

### `OrderRevisionComparePageRendersTest`
- Beklediği blok: `Revizyon Karşılaştır` linki ve quote show/edit kaynak bağlantıları.
- Layout-only neden yetmedi?: Revision/source-order link yüzeyi dışarıda kaldı.
- Gereken hunk: blade revision/source-order blokları + controller `show()`, `edit()`, `buildSourceOrderContext()`, `canAccessRevisionCompare()`.
- Controller gerekiyor mu?: Evet.
- CSS gerekiyor mu?: Hayır.

### `PromotionQuoteShowDecisionScreenTest`
- Beklediği blok: decision screen layout, compact rows, action yüzeyi.
- Layout-only neden yetmedi?: Sadece iskelet değil, karar ve action bağlamı bekleniyor.
- Gereken hunk: blade unified layout + approval/send action yüzeyi.
- Controller gerekiyor mu?: Evet, `show()` verisi.
- CSS gerekiyor mu?: Hayır.

### `PromotionQuoteConvertCtaTest`
- Beklediği blok: convert CTA, compact ürün/baskı yapısı.
- Layout-only neden yetmedi?: Yeni birleşik detail görünümünde convert CTA aynı yüzeyde konumlanmış.
- Gereken hunk: blade layout + convert CTA.
- Controller gerekiyor mu?: Düşük.
- CSS gerekiyor mu?: Hayır.

## 7. CSS Planı
- Analiz edilen CSS yüzeyleri:
  - quote detail layout CSS
  - send modal CSS
  - approval admin UI CSS
  - revision/source banner CSS
- Gözlem:
  - `public/css/prodelya-admin.css` içinde quote detail namespace’i geniş ve büyük oranda `.promotion-quote-detail.quote-detail-compact ...` altında toplanmış.
  - `quote-strip`, `quote-top-metrics`, `quote-right-summary`, `quote-tab-button`, `quote-send-card`, `quote-send-modal`, `quote-bottom-bar`, `quote-action-band` gibi unified yüzey selector’ları mevcut.
- CSS olmadan feature testler geçebilir mi?: Büyük olasılıkla evet. Mevcut kırmızı kümeler içerik/DOM/literal odaklı.
- CSS yalnız smoke/görsel kalite için mi gerekli?: Büyük ölçüde evet.
- CSS ayrı commit mi olmalı?: Evet. En güvenlisi ayrı `ui` commitidir.
- Global selector riski var mı?: Evet. Dosyada büyük churn ve geniş selector alanı var. Bu yüzden aynı checkpoint’e alınmamalı.

## 8. Net Commit Planı

### Commit A
- Mesaj: `quotes: refine quote detail unified decision surface`
- Dosyalar:
  - `resources/views/admin/promotion-quotes/show.blade.php`
  - gerekirse `app/Http/Controllers/Admin/PromotionQuoteController.php` içinden yalnız unified view-data hunkları
  - ilgili quote detail / approval / revision reference / UI testleri
- Hunk notu:
  - layout
  - approval karar bandı
  - send/resend UI yüzeyi
  - revision/source-order link yüzeyi
  - tabs/reference/responsive yapı
- Dışarıda bırakılacaklar:
  - `sendToCustomer()`
  - `openWhatsappLink()`
  - `buildSendSuccessMessage()`
  - `normalizeSendRecipientData()`
  - CSS
  - Product Hub
  - revision core
  - public approval guest core
  - notification service core
- Risk: Orta.
- Test önerisi:
  - `PromotionQuoteDetail|PromotionQuoteShowDecisionScreen|PromotionQuoteConvertCta`
  - `PromotionQuoteDetailCustomerApprovalUx|PublicQuoteApproval|QuoteApproval`
  - `RevisionAndRepeatOrderSourceReference|OrderRevision|RepeatOrder`

### Commit B
- Mesaj: `quotes: wire quote send channel controller actions`
- Dosyalar:
  - `app/Http/Controllers/Admin/PromotionQuoteController.php`
  - send-channel testleri
- Hunk notu:
  - `sendToCustomer()`
  - `openWhatsappLink()`
  - `buildSendSuccessMessage()`
  - `normalizeSendRecipientData()`
- Dışarıda bırakılacaklar:
  - layout
  - CSS
  - Product Hub
  - public approval guest core
  - revision core
- Risk: Orta-yüksek.
- Test önerisi:
  - `PromotionQuoteSendChannelHotfix|PromotionQuoteSendActionsUx|PromotionQuoteDetailSend|PromotionQuoteDetailWhatsapp|PromotionQuoteDetailPhone`
  - `NotificationPublicApprovalTokenSanitization|QuoteNotificationIntegration|WhatsappLinkUsesNormalizedPhone`

### Commit C
- Mesaj: `ui: add quote detail unified styles`
- Dosyalar:
  - `public/css/prodelya-admin.css`
- Hunk notu:
  - yalnız quote detail + send modal + approval admin + revision/source banner namespace blokları
- Dışarıda bırakılacaklar:
  - order detail CSS
  - Product Hub CSS
  - revision compare core olmayan genel selector churn
  - global reset/token churn
- Risk: Yüksek diff gürültüsü.
- Test önerisi:
  - smoke testler
  - görsel kontrol

### Commit D
- Mesaj: `docs: add quote detail unified checkpoint prep report`
- Dosyalar:
  - bu rapor
  - apply sonrası rapor
- Risk: Düşük.
- Test önerisi:
  - test gerekmez

### Alternatif karar
- Eğer Commit A tek başına `PromotionQuoteSendActionsUxTest` ve detail send kümesini hâlâ kırmızı bırakıyorsa, en güvenli checkpoint `Commit A + Commit B` birlikte alınmalıdır.
- Mevcut veri bu alternatifi güçlü biçimde destekliyor.

## 9. Commit’e Alınmayacaklar
- Product Hub core
- Revision A-B-C core
- Public approval page/mail/template core
- Notification service-core
- Quote/order list checkpoint
- Order detail checkpoint
- `routes/web.php`
- `config/admin_menu.php`
- `resources/views/public/graphics/approval/show.blade.php`
- `public/css/prodelya-admin.css` bu prep fazında
- `database.sqlite`
- `.env`
- `storage`
- `vendor`
- `node_modules`
- log/screenshot/debug/temp dosyaları

## 10. Test Sonuçları
- `php artisan test --filter="PromotionQuoteDetail|PromotionQuoteShowDecisionScreen|PromotionQuoteConvertCta"`
  - Sonuç: Başarısız
  - Özet: 42 test, 33 geçti, 9 failed
  - Kırmızı küme: detail approval/send/tabs/reference yapısı
- `php artisan test --filter="PromotionQuoteDetailCustomerApprovalUx|PublicQuoteApproval|QuoteApproval"`
  - Sonuç: Başarısız
  - Özet: 38 test, 34 geçti, 4 failed
  - Kırmızı küme: admin approval ve customer approval detail yüzeyi
- `php artisan test --filter="PromotionQuoteSendChannelHotfix|PromotionQuoteSendActionsUx|PromotionQuoteDetailSend|PromotionQuoteDetailWhatsapp|PromotionQuoteDetailPhone"`
  - Sonuç: Başarısız
  - Özet: 20 test, 13 geçti, 7 failed
  - Kırmızı küme: send actions, whatsapp kuralı, phone helper text
- `php artisan test --filter="RevisionAndRepeatOrderSourceReference|OrderRevision|RepeatOrder"`
  - Sonuç: Başarısız
  - Özet: 51 test, 48 geçti, 3 failed
  - Kırmızı küme: revision compare / source-order yüzeyi
- `php artisan test --filter="PromotionQuote"`
  - Sonuç: Başarısız
  - Özet: 126 test, 111 geçti, 15 failed
  - Kırmızı küme: yukarıdaki detail/send/approval/revision cluster’ları
- `php artisan test --filter="Order"`
  - Sonuç: Başarısız
  - Özet: 212 test, 209 geçti, 3 failed
  - Kırmızı küme: revision/source reference
- `php artisan test --filter="NotificationPublicApprovalTokenSanitization|QuoteNotificationIntegration|WhatsappLinkUsesNormalizedPhone"`
  - Sonuç: Başarılı
  - Özet: 7 test geçti
- `php artisan test --filter="ProductHubLiveProductInfoEndpointTest|PromotionQuoteLiveProductInfoUiTest"`
  - Sonuç: Başarılı
  - Özet: 14 test geçti
- `php artisan test --filter="AdminSmokeTest|FullOperationalFlowSmokeTest"`
  - Sonuç: Başarılı
  - Özet: 60 test geçti

## 11. Kalan Riskler
- Unified view genişliği:
  - Blade içinde layout, approval, send, revision yüzeyleri aynı akışta birleşmiş durumda.
- Send controller runtime:
  - UI tek başına alınırsa bazı send action testleri kırmızı kalabilir.
- CSS global risk:
  - `prodelya-admin.css` diff’i büyük ve gürültülü.
- Product Hub / Public Approval / Revision core karışması:
  - Controller içinde scope sınırını dikkatle ayırmak gerekiyor.
- Whitespace/BOM churn:
  - Özellikle controller ve blade dosyalarında seçici staging şart.

## 12. Net Karar
- `QUOTE-DETAIL-UNIFIED-VIEW + SEND-CONTROLLER birlikte alınmalı`

Gerekçe:
- Layout-only yaklaşımı yeterli olmadı.
- Kırmızı testler blade yüzeyini tek başına değil, approval/send/revision/source-order bağlamı ile birlikte doğruluyor.
- Kalıcı send-channel runtime hunkları da bazı test kümelerinde doğrudan gerekli görünüyor.
- CSS bu checkpoint için bekletilebilir.

## 13. Sonraki Adım
- Kullanıcı onayı sonrası uygulanacak apply fazı adı:
  - `QUOTE-DETAIL-UNIFIED-VIEW-AND-SEND-CONTROLLER-CHECKPOINT-COMMIT-APPLY`
