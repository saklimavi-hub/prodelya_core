# CSS Quote Detail Send Modal Commit Apply Raporu — 2026-07-10

## 1. Özet

- Yeni CSS yazıldı mı?
  - Hayır
- Kaç commit oluşturuldu?
  - 2 hedeflendi
- Migration çalıştırıldı mı?
  - Hayır
- DB’ye dokunuldu mu?
  - Hayır
- Sadece `public/css/prodelya-admin.css` mi alındı?
  - Commit A için evet
- Dosya bazlı staging yapıldı mı?
  - Hayır
- Patch staging yapıldı mı?
  - Evet

## 2. Commit Listesi

- Commit A
  - mesaj: `ui: add quote detail and send modal styles`
  - hash: `889f661`
  - dosyalar: `public/css/prodelya-admin.css`
  - test sonucu: geçti

- Commit B
  - mesaj: `docs: add quote detail send modal css report`
  - hash: bu raporun commit’i oluşturulduğunda üretilecek
  - dosyalar:
    - `docs/CSS-QUOTE-DETAIL-SEND-MODAL-NARROW-HUNK-PREP-RAPORU-20260710.md`
    - `docs/CSS-QUOTE-DETAIL-SEND-MODAL-COMMIT-APPLY-RAPORU-20260710.md`
  - test sonucu: test gerekmiyor

## 3. Hunk Staging Notları

- alınan selector grupları
  - `.promotion-quote-detail.quote-detail-compact`
  - `.quote-page-head`, `.quote-detail-page-head`
  - `.quote-strip`, `.quote-detail-strip`, `.quote-strip-top`, `.quote-strip-number`, `.quote-strip-subtitle`, `.quote-strip-chips`
  - `.quote-top-metrics`, `.quote-detail-top-metrics`
  - `.quote-layout`, `.quote-detail-layout`, `.quote-main-stack`, `.quote-detail-main`, `.quote-right-stack`, `.quote-detail-right`, `.quote-right-summary`
  - `.quote-tabs`, `.quote-detail-tabs`, `.quote-tab-button`, `.quote-detail-tab`, `.quote-tab-panel`, `.quote-detail-panel`
  - `.quote-action-band`
  - `.promotion-quote-lines*`
  - quote product row, print row ve compact row blokları
  - `.pd-quote-detail` içindeki feature-local `pd-product-line__*` ve `pd-product-print-block__*`
  - `.quote-alert*`, `.quote-log-list`, `.quote-mini-log-list`, `.quote-history-row`, `.quote-log-row`, `.quote-mini-log`, `.quote-decision-list`, `.quote-send-card`, `.quote-summary-line`
  - `.quote-send-modal*`, `.quote-detail-modal-backdrop`, `.quote-channel-pill`, `.quote-send-grid`, send modal input/textarea/helper blokları, `.quote-modal-close`
  - ilgili responsive satırlar

- dışarıda bırakılan selector grupları
  - `body.pd-modal-open`
  - `.promotion-quote-detail.quote-detail-compact .quote-action-band .pd-btn`
  - `.promotion-quote-detail.quote-detail-compact .quote-right-summary .pd-btn`
  - `.pd-sticky-bar.quote-bottom-bar`
  - `.promotion-quote-detail.quote-detail-compact.quote-bottom-bar .pd-btn`
  - `.promotion-quote-detail.quote-detail-compact .quote-bottom-bar .pd-btn`

- shared/global primitive kontrolü
  - cached diff yalnız eklenen satırlar üzerinde grep ile doğrulandı
  - `:root`, `.pd-btn`, `.pd-card`, `.pd-badge`, `.pd-tabs`, `.pd-modal`, `.pd-sticky-bar`, `body.pd-modal-open`, `.pd-product-hub`, `.pd-order`, `.order-revision-compare`, `.orc-*`, `.pd-orders-index*`, `.pd-quote-workspace` eklenen satırlarda yok

## 4. Davranış / Görsel Sonuç

- quote detail layout
  - iki kolonlu unified detail düzeni ve mobil kırılımları commit’e alındı
- send modal
  - backdrop, panel, head, body, grid ve actions blokları commit’e alındı
- WhatsApp Link
  - kanal pill ve send grid stilleri commit’e alındı
- E-posta Önizleme
  - modal field / textarea / readonly preview stilleri commit’e alındı
- Müşteri Onayı
  - alert, log, decision, summary ve action-band yüzeyleri commit’e alındı
- Revizyon Karşılaştır
  - quote detail içindeki action-band yerleşimi alındı
  - bağımsız revision compare CSS alınmadı
- responsive görünüm
  - `max-width: 1180px`, `900px`, `760px` ilgili quote-detail/send-modal satırları dahil edildi

## 5. Dışarıda Bırakılanlar

- shared/global primitive
  - `:root`, `html`, `body`, `a`, `table`, `input`, `button`, `.pd-btn`, `.pd-card`, `.pd-badge`, `.pd-tabs`, `.pd-modal`, `.pd-sticky-bar`
- Product Hub CSS
  - `.pd-product-hub`, `.pd-request-hub-*`, `.pd-product-diagnostic-*`, `.pd-hub-preview-shell`
- Order detail CSS
  - `.pd-order-layout`, `.pd-order-tabs`, `.pd-order-summary-panel`, `.pd-order-flow`, `.pd-order-flow-card`, `.pd-order-summary-grid`, `.pd-order-item-table`
- Revision compare CSS
  - `.order-revision-compare`, `.orc-*`
- Quote/order list CSS
  - `.pd-orders-index-*`, `.pd-quote-workspace`, list chip/counter/sticky panel stilleri
- routes/config/blade/php/controller/test dosyaları
  - staging ve commit dışı bırakıldı

## 6. Final Test Sonuçları

- `PromotionQuoteDetail|PromotionQuoteShowDecisionScreen|PromotionQuoteConvertCta`
  - 42 test, 335 assertion
- `PromotionQuoteSendChannelHotfix|PromotionQuoteSendActionsUx|PromotionQuoteDetailSend|PromotionQuoteDetailWhatsapp|PromotionQuoteDetailPhone`
  - 20 test, 113 assertion
- `PromotionQuoteDetailCustomerApprovalUx|PublicQuoteApproval|QuoteApproval`
  - 38 test, 379 assertion
- `PromotionQuote`
  - 126 test, 1190 assertion
- `Order`
  - 212 test, 1771 assertion
- `AdminSmokeTest|FullOperationalFlowSmokeTest`
  - 60 test, 644 assertion

Toplam:

- 498 test
- 4432 assertion

## 7. Full Suite Durumu

- Full suite zorunlu değildi
- Bu fazda full suite çalıştırılmadı

## 8. Kalan Worktree Durumu

- order detail CSS kaldı mı?
  - Evet, daha geniş `public/css/prodelya-admin.css` worktree farkı içinde kalıyor
- Product Hub CSS kaldı mı?
  - Evet
- revision compare CSS kaldı mı?
  - Evet
- quote/order list CSS kaldı mı?
  - Evet
- route churn kaldı mı?
  - Evet, `routes/web.php` ve `config/admin_menu.php` modified durumda
- docs/test cleanup kaldı mı?
  - Evet, çeşitli untracked docs/test dosyaları mevcut

## 9. Net Karar

- CSS quote detail/send modal checkpoint tamamlandı mı?
  - Evet, dar selector planına uygun commit alındı
- Sonraki checkpoint grubuna geçilebilir mi?
  - Evet

## 10. Sonraki Öneri

- `CSS-ORDER-DETAIL-HUNK-STAGING-PREP`
