# CSS Quote Detail Send Modal Narrow Hunk Prep Raporu — 2026-07-10

## 1. Özet

- Yeni CSS yazıldı mı?
  - Hayır
- Staging/commit yapıldı mı?
  - Hayır
- CSS değiştirildi mi?
  - Hayır
- Worktree korundu mu?
  - Evet

## 2. Git Durumu

- staged area
  - `git diff --cached --stat` boş
  - `git diff --cached --name-status` boş
  - karar: staged area temiz, prep fazı ilerleyebilir
- modified
  - `app/Http/Controllers/Admin/OrderController.php`
  - `app/Http/Controllers/Admin/PromotionQuoteController.php`
  - `app/Models/Order.php`
  - `config/admin_menu.php`
  - `public/css/prodelya-admin.css`
  - `routes/web.php`
  - `tests/Feature/PromotionQuoteAndOrderIndexHeaderPanelTest.php`
  - `tests/Feature/PromotionQuoteAndOrderIndexUxTest.php`
- untracked
  - `.tmp_quote_detail_commit_target.blade.php`
  - `.tmp_quote_detail_show_worktree_backup.blade.php`
  - çeşitli mevcut docs/test dosyaları
- CSS diff durumu
  - `public/css/prodelya-admin.css` diff’i çok geniş
  - quote detail/send modal alanı tek parça bir dosya diff’i içinde yer alıyor
  - bu nedenle dosya bazlı staging güvenli değildir

## 3. Quote Detail Selector Analizi

- `.promotion-quote-detail.quote-detail-compact`
  - satır aralığı: `5017-5029`, `5039-5042`, `5044-5046`
  - ilişki: unified quote detail namespace token, tipografi ve box model başlangıcı
  - alınmalı mı? Evet
  - çakışma: Product Hub/order detail/revision compare/list ile doğrudan çakışmıyor
  - shared bağımlılık: düşük, kendi `--quote-detail-*` tokenlarını tanımlıyor
  - staging notu: blok sınırı net
  - risk: düşük

- `.promotion-quote-detail`
  - satır aralığı: doğrudan tek başına yeni selector yok; etkin kullanım `.promotion-quote-detail.quote-detail-compact`
  - ilişki: quote detail container bağlamı
  - alınmalı mı? Yalnız compound selector biçimiyle
  - çakışma: hayır
  - shared bağımlılık: düşük
  - staging notu: yalnız `.quote-detail-compact` ile birlikte alınmalı
  - risk: düşük

- `.quote-page-head`, `.quote-detail-page-head`
  - satır aralığı: `5048-5072`, responsive `6143-6156`
  - ilişki: unified detail üst başlık ve aksiyon alanı
  - alınmalı mı? Evet
  - çakışma: yok
  - shared bağımlılık: `.pd-btn` kullanımına görsel olarak bağımlı
  - staging notu: responsive satırlarıyla birlikte alınmalı
  - risk: orta

- `.quote-strip`, `.quote-detail-strip`, `.quote-strip-top`, `.quote-strip-number`, `.quote-strip-subtitle`, `.quote-strip-chips`
  - satır aralığı: `5151-5196`, responsive `6143-6156`
  - ilişki: üst özet şeridi ve chip/aksiyon kümeleri
  - alınmalı mı? Evet
  - çakışma: yok
  - shared bağımlılık: sınırlı; chip alanı kendi stillerini taşıyor
  - staging notu: chip flex bloklarıyla birlikte alınmalı
  - risk: düşük

- `.quote-top-metrics`, `.quote-detail-top-metrics`
  - satır aralığı: `5198-5217`, responsive `6132-6139`, `6158-6169`
  - ilişki: üst KPI/metric grid alanı
  - alınmalı mı? Evet
  - çakışma: yok
  - shared bağımlılık: düşük
  - staging notu: iki responsive media bloğu birlikte alınmalı
  - risk: orta

- `.quote-layout`, `.quote-detail-layout`, `.quote-main-stack`, `.quote-detail-main`, `.quote-right-stack`, `.quote-detail-right`
  - satır aralığı: `5208-5239`, responsive `6120-6139`, `6158-6169`
  - ilişki: ana iki kolonlu unified view iskeleti
  - alınmalı mı? Evet
  - çakışma: order detail layout ile isim olarak değil, işlev olarak komşu; selector bazında çakışma yok
  - shared bağımlılık: düşük
  - staging notu: sticky sağ panel ve responsive dönüşlerle birlikte alınmalı
  - risk: orta

- `.quote-right-summary`
  - satır aralığı: `5048-5062`, `5241-5272`, `5958-5987`
  - ilişki: sağ özet paneli
  - alınmalı mı? Evet
  - çakışma: yok
  - shared bağımlılık: `.pd-btn` buton görünümüne bağımlı
  - staging notu: card shell ve alt buton satırları birlikte alınmalı
  - risk: orta

- `.quote-tabs`, `.quote-detail-tabs`, `.quote-tab-button`, `.quote-detail-tab`, `.quote-tab-panel`, `.quote-detail-panel`
  - satır aralığı: `5384-5393`, `5597-5633`
  - ilişki: tab yapısı ve aktif panel görünürlüğü
  - alınmalı mı? Evet
  - çakışma: `.pd-tabs` ile kavramsal yakınlık var ama selector çakışması yok
  - shared bağımlılık: düşük
  - staging notu: button state ve panel state birlikte alınmalı
  - risk: orta

- `.quote-bottom-bar`, `.quote-detail-bottom-bar`
  - satır aralığı: `5993-6035`, responsive `6145-6148`
  - ilişki: ekran altı işlem bandı
  - alınmalı mı? Evet, fakat dikkatli
  - çakışma: `.pd-sticky-bar.quote-bottom-bar` satırı shared primitive’e temas ediyor
  - shared bağımlılık: yüksek; `.pd-sticky-bar` ve `.pd-btn` ile birlikte çalışıyor
  - staging notu: `6017-6019` satırındaki `.pd-sticky-bar.quote-bottom-bar` mümkünse dışarıda bırakılmalı; kalan quote namespace satırları patch-stage edilebilir
  - risk: yüksek

- `.quote-action-band`
  - satır aralığı: `5570-5595`, responsive `6145-6148`
  - ilişki: approval/revision/send aksiyon bandı
  - alınmalı mı? Evet
  - çakışma: yok
  - shared bağımlılık: `.pd-btn` görünümüne orta düzey bağımlı
  - staging notu: band blokları alınmalı; `.quote-action-band .pd-btn` satırı opsiyonel dikkat alanı
  - risk: orta

- `.promotion-quote-lines`, `.promotion-quote-lines*`
  - satır aralığı: `5650-5864`
  - ilişki: kompakt ürün/baskı satırları, tablo/row görünümü
  - alınmalı mı? Evet
  - çakışma: quote/order list ve order detail ile selector çakışması yok
  - shared bağımlılık: düşük
  - staging notu: header, body, mobile `@media (max-width: 900px)` satırlarıyla birlikte tek blok alınmalı
  - risk: orta

- quote product row stilleri
  - satır aralığı: `5306-5383`, `5650-5804`
  - ilişki: unified detail ürün satırları ve kompakt line görünümleri
  - alınmalı mı? Evet
  - çakışma: yok
  - shared bağımlılık: düşük
  - staging notu: index/meta/note/money ile birlikte alınmalı
  - risk: orta

- quote print row stilleri
  - satır aralığı: `5354-5383`, `5709-5779`, `5866-5885`
  - ilişki: baskı satırı ve alt meta görünümü
  - alınmalı mı? Evet
  - çakışma: yok
  - shared bağımlılık: düşük
  - staging notu: print row, print chip ve print list birlikte alınmalı
  - risk: orta

- quote history/log/decision/list stilleri
  - satır aralığı: `5292-5304`, `5354-5371`, `5530-5556`, `5866-5879`
  - ilişki: approval geçmişi, log ve decision yüzeyleri
  - alınmalı mı? Evet; quote detail experience’tan ayrılması zor
  - çakışma: revision compare ile isimsel çakışma yok
  - shared bağımlılık: düşük
  - staging notu: list container ve row stilleri birlikte alınmalı
  - risk: orta

- `.quote-summary-line`
  - satır aralığı: `5514-5524`, `5866-5875`, `5939-5951`, responsive `6186-6192`
  - ilişki: sağ özet, approval ve toplam satırları
  - alınmalı mı? Evet
  - çakışma: yok
  - shared bağımlılık: düşük
  - staging notu: base row, strong/span ve mobile display birlikte alınmalı
  - risk: orta

- `.pd-quote-detail` içindeki `pd-product-line__*` ve `pd-product-print-block__*`
  - satır aralığı: `5074-5085`, `5402-5494`, responsive `6172-6175`
  - ilişki: quote detail içindeki ürün/print block yardımcı görünümleri
  - alınmalı mı? Evet, ama yalnız `pd-quote-detail` namespace altında olanlar
  - çakışma: Product Hub/order detail/revision compare/list ile selector çakışması yok
  - shared bağımlılık: orta; `pd-*` ön eki var ama global primitive değil, feature-local namespace
  - staging notu: `pd-quote-detail` ile başlayan bloklar quote detail commitine dahil edilebilir
  - risk: orta

## 4. Send Modal Selector Analizi

- `.quote-send-modal`, `.quote-detail-modal-backdrop`
  - satır aralığı: `6037-6057`, responsive `6194-6200`
  - ilişki: send modal açık/kapalı backdrop ve overlay davranışı; WhatsApp/e-posta önizleme modal kabuğu
  - alınmalı mı? Evet
  - shared karışım: `body.pd-modal-open` ile ilişkili ama doğrudan `.pd-modal` gerektirmiyor
  - staging notu: overlay/open-state ve mobile padding satırları birlikte alınmalı
  - risk: orta

- `.quote-send-modal-panel`, `.quote-detail-modal`
  - satır aralığı: `5056-5062`, `6059-6066`
  - ilişki: modal panel kutusu ve genişlik/yükseklik davranışı
  - alınmalı mı? Evet
  - shared karışım: `.pd-modal` ile kavramsal benzerlik var ama selector karışımı yok
  - staging notu: base shell satırı ve panel sizing satırları birlikte alınmalı
  - risk: orta

- `.quote-send-modal-head`
  - satır aralığı: `5110-5114`, `6068-6081`
  - ilişki: modal başlık ve açıklama alanı
  - alınmalı mı? Evet
  - shared karışım: yok
  - staging notu: head `p` ve `h2` satırları birlikte alınmalı
  - risk: düşük

- `.quote-send-modal-body`
  - satır aralığı: `6083-6085`
  - ilişki: modal içerik padding alanı
  - alınmalı mı? Evet
  - shared karışım: yok
  - staging notu: blok sınırı net
  - risk: düşük

- `.quote-send-modal-grid`, `.quote-send-modal-grid-full`
  - satır aralığı: `5206`, `6087-6093`, responsive `6167-6169`
  - ilişki: modal form grid ve tam genişlik preview alanı
  - alınmalı mı? Evet
  - shared karışım: yok
  - staging notu: base grid ile mobile tek kolon satırı birlikte alınmalı
  - risk: düşük

- `.quote-send-form-grid`
  - satır aralığı: `5205`, `5897-5900`, responsive `6137-6139`, `6166-6169`
  - ilişki: send form satır düzeni; WhatsApp/e-posta alanlarının ekran içi form yerleşimi
  - alınmalı mı? Evet
  - shared karışım: yok
  - staging notu: desktop ve responsive satırlar birlikte alınmalı
  - risk: orta

- `.quote-send-modal-actions`
  - satır aralığı: `6095-6108`, responsive `6146-6148`
  - ilişki: modal action button alanı ve açıklama küçük metni
  - alınmalı mı? Evet
  - shared karışım: butonlar `.pd-btn` görünümüne dayanıyor
  - staging notu: action shell alınmalı; shared `.pd-btn` primitive alınmamalı
  - risk: orta

- `.quote-channel-pill`
  - satır aralığı: `5184`, `5385-5393`, `5606-5617`
  - ilişki: kanal seçimi, WhatsApp/e-posta pill UI
  - alınmalı mı? Evet
  - shared karışım: `.pd-badge` ile kavramsal benzerlik var ama selector karışımı yok
  - staging notu: base style ve active state birlikte alınmalı
  - risk: düşük

- `.quote-send-grid`
  - satır aralığı: `5204`, `5887-5890`, responsive `6165-6169`
  - ilişki: send card seçim grid’i
  - alınmalı mı? Evet
  - shared karışım: yok
  - staging notu: mobile tek kolon satırıyla birlikte alınmalı
  - risk: düşük

- send modal input/textarea/helper stilleri
  - satır aralığı: `5505-5512`, `5902-5927`
  - ilişki: modal alan etiketleri, preview textarea, readonly görünümü
  - alınmalı mı? Evet
  - shared karışım: global `input/textarea` primitive’den bağımsız feature override içeriyor
  - staging notu: label, input, textarea, readonly satırları birlikte alınmalı
  - risk: orta

- modal preview textarea stilleri
  - satır aralığı: `5916-5927`
  - ilişki: e-posta/mesaj önizleme alanı
  - alınmalı mı? Evet
  - shared karışım: yok
  - staging notu: textarea min-height ve readonly durumu birlikte alınmalı
  - risk: düşük

- `.quote-modal-close`
  - satır aralığı: `6111-6118`
  - ilişki: modal kapatma butonu
  - alınmalı mı? Evet
  - shared karışım: yok
  - staging notu: blok sınırı net
  - risk: düşük

## 5. Approval / Revision Selector Analizi

- `.quote-alert`, `.quote-alert-success`, `.quote-alert-error`, `.quote-alert-warning`
  - satır aralığı: `5124-5149`
  - karar: quote detail CSS commitine dahil edilmeli
  - ayrılabilir mi? Teorik olarak evet, pratikte detail ekranın üst feedback yüzeyinden kopması istenmez
  - send modal ilişkisi: dolaylı
  - ayrı approval/revision CSS commit gerekir mi? Şimdilik gerekmez
  - risk: düşük

- `.quote-log-list`, `.quote-mini-log-list`, `.quote-history-row`, `.quote-log-row`, `.quote-mini-log`
  - satır aralığı: `5295-5296`, `5354-5371`, `5534-5556`, `5868-5869`
  - karar: quote detail CSS commitine dahil edilmeli
  - ayrılabilir mi? Zor; quote detail unified history yüzeyinin parçası
  - send modal ilişkisi: yok
  - ayrı approval/revision CSS commit gerekir mi? Hayır
  - risk: orta

- `.quote-decision-list`
  - satır aralığı: `5297-5300`
  - karar: dahil edilmeli
  - ayrılabilir mi? Sınırlı
  - send modal ilişkisi: yok
  - ayrı approval/revision CSS commit gerekir mi? Hayır
  - risk: düşük

- `.quote-send-card`
  - satır aralığı: `5259`, `5370`, `5535-5555`, `5892-5895`
  - karar: dahil edilmeli
  - ayrılabilir mi? Hayır; send modal öncesi kanal seçimiyle doğrudan ilişkili
  - send modal ilişkisi: yüksek
  - ayrı approval/revision CSS commit gerekir mi? Hayır
  - risk: orta

- `.quote-summary-line`
  - satır aralığı: `5519`, `5870`, `5939-5951`, `5974-5975`, `6190-6191`
  - karar: dahil edilmeli
  - ayrılabilir mi? Hayır; sağ panel ve approval summary ortak satırı
  - send modal ilişkisi: dolaylı
  - ayrı approval/revision CSS commit gerekir mi? Hayır
  - risk: orta

- source order banner selectorları
  - tespit: quote namespace içinde açık ayrı `source-order-*` selector bloğu görünmedi
  - karar: bu fazda ayrıca dahil edilecek bağımsız selector yok
  - risk: düşük

- revision compare action/link selectorları
  - tespit: quote detail bloğu içinde revision action görseli `quote-action-band` içinde eriyor; bağımsız `revision-*` selector bloğu yok
  - karar: `quote-action-band` içinde kaldığı ölçüde dahil olabilir
  - ayrı revision CSS commit gerekir mi? `order-revision-compare` için evet; fakat bu fazın dışında
  - risk: orta

## 6. Dışarıda Kalacak Selectorlar

- Product Hub
  - dışarıda kalanlar: `.pd-product-hub`, `.pd-request-hub-*`, `.pd-product-diagnostic-*`, `.pd-hub-preview-shell`
  - neden: farklı feature alanı ve selector komşuluğu dışında bağ yok
  - sonraki faz: Product Hub / Template Master Plan
  - quote detail/send modal için zorunlu mu? Hayır

- Order detail
  - dışarıda kalanlar: `.pd-order-layout`, `.pd-order-tabs`, `.pd-order-summary-panel`, `.pd-order-flow`, `.pd-order-flow-card`, `.pd-order-summary-grid`, `.pd-order-item-table`
  - neden: order detail feature alanı
  - sonraki faz: order detail CSS checkpoint
  - quote detail/send modal için zorunlu mu? Hayır

- Revision compare
  - dışarıda kalanlar: `.order-revision-compare`, `.orc-*`
  - neden: quote detail bloğundan sonra başlayan bağımsız revision compare yüzeyi
  - sonraki faz: revision compare CSS checkpoint
  - quote detail/send modal için zorunlu mu? Hayır

- Quote/order list
  - dışarıda kalanlar: `.pd-orders-index-*`, `.pd-quote-workspace`, list chip/counter/sticky panel stilleri
  - neden: liste ekranı stilleri
  - sonraki faz: quote/order list CSS zaten ayrı checkpoint mantığında
  - quote detail/send modal için zorunlu mu? Hayır

- Shared/global
  - dışarıda kalanlar: `:root`, `html`, `body`, `a`, `table`, `input`, `button`, `.pd-btn`, `.pd-card`, `.pd-badge`, `.pd-tabs`, `.pd-modal`, `.pd-sticky-bar`, `body.pd-modal-open`, genel utility selectorlar
  - neden: admin genel primitive katmanı; feature sınırını aşar
  - sonraki faz: CSS Template Master Plan veya ayrı primitive cleanup
  - quote detail/send modal için zorunlu mu? Kısmen dayanak sağlar ama bu committe alınması zorunlu değildir

## 7. Dar Commit Planı

- Olası Commit A
  - mesaj: `ui: add quote detail and send modal styles`
  - dahil edilecekler:
  - `5017-6202` aralığından yalnız quote detail/send modal namespace’ine ait selector blokları
  - `.promotion-quote-detail.quote-detail-compact` taban tokenları
  - `.quote-*` selectorları, yalnız quote detail/send modal ile ilişkili olanlar
  - `.promotion-quote-lines*`
  - `.pd-quote-detail` ile başlayan feature-local product/print block selectorları
  - `.quote-send-modal*`
  - `.quote-channel-pill`
  - `.quote-alert*`, `.quote-log-list`, `.quote-history-row`, `.quote-decision-list`, `.quote-send-card`, `.quote-summary-line`
  - dışarıda bırakılacaklar:
  - `:root` ve tüm shared/global `.pd-*` primitive blokları
  - `body.pd-modal-open`
  - `.pd-sticky-bar.quote-bottom-bar`
  - Product Hub, order detail, quote/order list, revision compare blokları
  - routes/config/blade/php/controller/test dosyaları
  - risk:
  - yüksek; patch staging zorunlu

- Olası Commit B
  - mesaj: `docs: add narrow quote detail send modal CSS prep report`
  - dahil edilecekler:
  - bu rapor
  - apply sonrası rapor

## 8. Patch Staging Stratejisi

- dosya bazlı staging kesinlikle kullanılmamalı
- `public/css/prodelya-admin.css` yalnız patch mode ile stage edilmeli
- önce quote detail core blokları stage edilmeli:
  - `5017-5633`
- sonra compact line / summary / right panel / bottom bar blokları stage edilmeli:
  - `5650-6202`
- `pd-quote-detail` feature-local blokları ayrı patch olarak değerlendirilmeli:
  - `5074-5085`, `5402-5494`, `6172-6175`
- `git diff --cached -- public/css/prodelya-admin.css` her patch sonrası kontrol edilmeli
- staged diff içinde aşağıdakiler görünürse commit atılmamalı:
  - `.pd-product-hub`
  - `.pd-order`
  - `.order-revision-compare`
  - `.pd-btn`
  - `.pd-modal`
  - `.pd-sticky-bar`
  - `:root`
  - `body.pd-modal-open`
- staged diff içinde yalnız quote detail/send modal selectorları kalmalı

## 9. Commit’e Alınmayacaklar

- `routes/web.php`
- `config/admin_menu.php`
- tüm blade/php/controller değişiklikleri
- test dosyaları
- Product Hub CSS
- order detail CSS
- revision compare CSS
- quote/order list CSS
- shared/global primitive katmanı

## 10. Test Sonuçları

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

## 11. Smoke Planı

- `/admin/promotion-quotes/{id}` aç
- unified quote detail üst özet görünümünü kontrol et
- ürün/baskı kompakt satırlarını kontrol et
- sağ özet panelini kontrol et
- tab yapısını ve aktif panel geçişlerini kontrol et
- `quote-bottom-bar` görünümünü kontrol et
- gönderim modalını aç
- WhatsApp Link kanal kartını kontrol et
- E-posta Önizleme alanını kontrol et
- Müşteri Onayı yüzeyini kontrol et
- Revizyon Karşılaştır aksiyonlarının yerleşimini kontrol et
- mobil/responsive görünümü `<=1180px` ve `<=760px` kırılımlarında kontrol et

## 12. Kalan Riskler

- quote detail selector sınırı
  - bazı satırlar `.quote-*` ve `.pd-quote-detail` arasında bölünmüş durumda
- send modal selector sınırı
  - modal alanı quote detail grid ve card selectorlarıyla kısmen iç içe
- shared primitive bağımlılığı
  - buton/sticky/modal davranışı shared primitive tabanına yaslanıyor
- responsive bloklar
  - masaüstü ve mobil satırları ayrı alınırsa görünüm kırılır
- Template Master Plan çakışması
  - primitive katman sonradan değişirse bu feature stillerinin ince ayarı gerekebilir

## 13. Net Karar

**CSS-QUOTE-DETAIL-SEND-MODAL-COMMIT-APPLY yapılabilir.**

Şart:

- daha dar selector/hunk planına sadık kalınmalı
- shared/global primitive kesinlikle stage edilmemeli
- patch staging sonrası cached diff elle doğrulanmalı

## 14. Sonraki Adım

- `CSS-QUOTE-DETAIL-SEND-MODAL-COMMIT-APPLY`
