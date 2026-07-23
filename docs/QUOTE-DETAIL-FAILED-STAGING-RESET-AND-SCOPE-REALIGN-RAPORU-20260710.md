# Quote Detail Failed Staging Reset And Scope Realign Raporu — 2026-07-10

## 1. Özet
- Bu fazda yeni feature kodu yazılmadı.
- Commit yapılmadı.
- Yalnız başarısız quote detail checkpoint denemesine ait staged set güvenli şekilde index'ten çıkarıldı.
- Worktree değişiklikleri korundu.
- Kaynak dosya silinmedi.
- Sonuç: sorun staging kalıntısı değil, checkpoint kapsamının gerçek bağımlılıkları eksik bırakmasıdır.

## 2. Başlangıç Git Durumu

### HEAD
- `e8cb3bc` `docs: add order detail checkpoint report`
- `b07daed` `orders: turn order detail into operation flow center`
- `0014a61` `docs: add quote order list checkpoint report`
- `fd141db` `orders: refine order list views and completed filters`
- `bf053ca` `quotes: refine quote list views and filters`

### Cleanup öncesi index durumu
- `git diff --cached --stat` çıktısı 27 dosyalık staged bir set gösterdi.
- Set yalnız başarısız quote-detail checkpoint denemesine aitti:
  - `resources/views/admin/promotion-quotes/show.blade.php`
  - quote detail fixture/test dosyaları
  - `PromotionQuoteConvertCtaTest`
  - `PromotionQuoteCreateEditUiRegressionTest`
  - `PromotionQuoteShowDecisionScreenTest`

### Cleanup öncesi worktree durumu
- Worktree içinde quote detail dışı mevcut kirli dosyalar korunuyordu:
  - `app/Http/Controllers/Admin/OrderController.php`
  - `app/Http/Controllers/Admin/PromotionQuoteController.php`
  - `app/Models/Order.php`
  - `config/admin_menu.php`
  - `public/css/prodelya-admin.css`
  - `routes/web.php`
  - `resources/views/public/graphics/approval/show.blade.php`
  - çeşitli test dosyaları

## 3. Staged Reset Sonucu
- Yalnız başarısız denemeye ait staged dosyalar `git restore --staged` ile index'ten çıkarıldı.
- `git diff --cached --stat` sonrası boş döndü.
- `git diff --cached --name-status` sonrası boş döndü.
- Worktree korunarak devam edildi.

### Reset sonrası kısa durum
- Staged dosya kalmadı.
- `resources/views/admin/promotion-quotes/show.blade.php` artık yalnız worktree değişikliği olarak kaldı.
- Quote detail testlerinin önemli kısmı untracked olarak worktree'de duruyor.
- Geçici dosyalar silinmedi:
  - `.tmp_quote_detail_commit_target.blade.php`
  - `.tmp_quote_detail_show_worktree_backup.blade.php`

## 4. Worktree Koruma Kontrolü

### Blade içinde korunan bloklar
`resources/views/admin/promotion-quotes/show.blade.php`

- Source order / revision context korunuyor:
  - satır `232-243` civarı `sourceOrderContext`
  - satır `254` `data-testid="quote-revision-compare-link"`
- Send action butonları korunuyor:
  - satır `257`, `511`, `520`, `583`, `669`
- Public approval yardımcı aksiyonları korunuyor:
  - satır `523`, `580`, `675`
- Send modal korunuyor:
  - satır `750` `id="quoteSendModal"`
  - satır `755` `Kanalı seçin. WhatsApp link için e-posta şartı yoktur.`
  - satır `946-949` modal JS binding

### Net yorum
- Worktree kopyasında approval, revision/source-order ve send modal blokları duruyor.
- Başarısızlık mevcut worktree'nin bozulmasından değil, önceki staged checkpoint denemesinin bu blokları kapsam dışı bırakmasından kaynaklandı.

## 5. Test Sonuçları

### 5.1 `PromotionQuoteDetail|PromotionQuoteShowDecisionScreen|PromotionQuoteConvertCta`
- Sonuç: `42` test, `33` geçti, `9` başarısız
- Başarısız testler:
  - `PromotionQuoteDetailCustomerApprovalUxTest::test_detail_shows_decision_band_and_not_sent_state_with_safe_copy`
  - `PromotionQuoteDetailCustomerApprovalUxTest::test_detail_shows_viewed_and_approved_states_with_safe_public_helper_and_convert_cta`
  - `PromotionQuoteDetailCustomerApprovalUxTest::test_detail_shows_revision_and_reject_states_and_hides_helper_when_feature_is_closed`
  - `PromotionQuoteDetailPhoneHelperTextTest::test_phone_helper_is_short_and_example_based`
  - `PromotionQuoteDetailReferenceStructureTest::test_reference_structure_blocks_are_present`
  - `PromotionQuoteDetailResponsiveStructureTest::test_layout_has_expected_structure_classes`
  - `PromotionQuoteDetailSendChannelUiTest::test_send_channel_ui_texts_are_visible`
  - `PromotionQuoteDetailTabsTest::test_tab_titles_are_visible`
  - `PromotionQuoteDetailWhatsappUiRuleTest::test_whatsapp_ui_rules_are_explained`

### 5.2 `PromotionQuoteDetailCustomerApprovalUx|PublicQuoteApproval|QuoteApproval`
- Sonuç: `38` test, `34` geçti, `4` başarısız
- Ana kırılım:
  - `PromotionQuoteApprovalAdminUiTest::test_show_screen_displays_simple_customer_response_send_history_and_hides_technical_fields`
  - approval/revision ekranında `Tekrar Gönder` ve sadeleştirilmiş response-history beklentileri dar checkpoint dışında kalıyor

### 5.3 `RevisionAndRepeatOrderSourceReference|OrderRevision|RepeatOrder`
- Sonuç: `51` test, `48` geçti, `3` başarısız
- Ana kırılım:
  - `OrderRevisionComparePageRendersTest::test_revision_compare_page_renders_and_links_from_quote_pages`
- Test açıkça quote show/edit ekranında `Revizyon Karşılaştır` linkini bekliyor.

### 5.4 `PromotionQuote`
- Sonuç: `126` test, `111` geçti, `15` başarısız
- Başarısız kümeler:
  - approval admin UI
  - customer approval / decision band
  - detail send-channel UI
  - send actions card / resend / helper state
  - responsive/reference/tab structure
  - show polish

### 5.5 `AdminSmokeTest|FullOperationalFlowSmokeTest`
- Sonuç: `60` test, `60` geçti

## 6. Başarısızlık Analizi

### A. Approval karar bandı ve customer response alanı
- `PromotionQuoteDetailCustomerApprovalUxTest.php:47-70`
- Bu test `Müşteri Onayı`, `Gönderilmedi`, karar bandı ve helper görünürlüğü gibi detayları bekliyor.
- Yani quote detail layout tek başına ayrılamıyor; approval-state gösterimi aynı ekranın doğal parçası.

### B. Send / resend aksiyonları
- `PromotionQuoteApprovalAdminUiTest.php:194-200`
- `Tekrar Gönder` beklentisi var.
- `PromotionQuoteSendActionsUxTest` kümesi de aynı ekranın gönderim aksiyonlarıyla birlikte ele alınması gerektiğini gösteriyor.

### C. Revision / source-order referansı
- `OrderRevisionComparePageRendersTest.php:28-40`
- Quote show ve edit ekranlarında `Revizyon Karşılaştır` linki zorunlu.
- Bu yüzden revision/source-order bağı quote detail checkpoint'inden koparılamıyor.

### D. Detail yapı testleri
- Responsive/reference/tab/send-channel testleri doğrudan `show.blade.php` yapısına bağlı.
- Dar layout-only checkpoint, aynı dosyanın fonksiyonel bloklarını dışarıda bıraktığı için yapısal testleri de kırıyor.

### E. Sistemik risk seviyesi
- Smoke testlerin tamamı geçti.
- Bu, uygulamanın genel akışının çalıştığını; problemin kapsam bölme stratejisinde olduğunu gösteriyor.

## 7. Kapsam Seçenekleri

### Seçenek A
- Yalnız quote detail compact layout checkpoint'i
- Karar: uygun değil
- Neden: approval, send, revision ve tab/reference beklentilerini kırıyor

### Seçenek B
- Quote detail layout + approval karar bandı + public approval yardımcı blokları
- Karar: kısmi iyileşme sağlar ama tek başına yetersiz kalabilir
- Neden: revision compare ve resend/send action beklentileri hâlâ dışarıda kalır

### Seçenek C
- Quote detail layout + approval UI + revision/source-order linkleri + send/resend UI aynı checkpoint içinde
- Karar: en dengeli seçenek
- Neden: kırılan test kümelerinin büyük çoğunluğu aynı ekranın bu birleşik yüzeyine bağlı

### Seçenek D
- Quote detail ile birlikte CSS, route, config ve diğer bağımsız alanları da aynı commit'e almak
- Karar: önerilmez
- Neden: checkpoint çok büyür, ayrıştırma değeri düşer

## 8. Net Öneri
- Bir sonraki checkpoint, `layout-only` değil `quote detail unified UI surface` mantığıyla hazırlanmalı.
- En doğru kapsam:
  - quote detail blade
  - approval karar / helper yüzeyi
  - send / resend / channel UI yüzeyi
  - revision compare / source-order referans yüzeyi
- CSS, route, config ve unrelated controller/service değişiklikleri mümkünse ayrı tutulmalı; fakat quote show ekranını besleyen zorunlu minimal backend bağları aynı checkpoint içinde kalabilir.

## 9. Sonuç
- Başarısız staged deneme güvenli şekilde temizlendi mi: evet
- Worktree korundu mu: evet
- Staged index temiz mi: evet
- Sorunun ana nedeni bulundu mu: evet
- Bir sonraki faza geçmeden önce kapsam realign gerekli mi: evet

## 10. Önerilen Sonraki Prompt
- `Prodelya için QUOTE-DETAIL-APPROVAL-REVISION-SEND-CHECKPOINT-PREP fazını uygula. Amaç: quote detail show ekranındaki layout, approval state, revision compare ve send/resend UI yüzeylerini aynı checkpoint altında test-kırmadan seçici staging için hazırlamak.`
