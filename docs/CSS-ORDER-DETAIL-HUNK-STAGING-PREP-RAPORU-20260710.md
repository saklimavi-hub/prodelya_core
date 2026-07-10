# CSS Order Detail Hunk Staging Prep Raporu — 2026-07-10

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
  - karar: staged area temiz
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
  - çeşitli `.tmp` ve docs/test dosyaları mevcut
- CSS diff durumu
  - `public/css/prodelya-admin.css` içinde order detail, Product Hub, shared primitive, quote detail ve revision compare aynı geniş diff içinde duruyor
  - dosya bazlı staging güvenli değil

## 3. Order Detail Selector Analizi

- `.pd-order-layout`
  - satır aralığı: `915-920`, responsive `1622-1629`
  - alınmalı mı? Evet
  - risk: orta
  - staging notu: responsive satırıyla birlikte alınmalı

- `.pd-order-stack`, `.pd-order-mini-list`, `.pd-order-history-list`, `.pd-order-step-list`, `.pd-order-package-list`
  - satır aralığı: `922-929`
  - alınmalı mı? Evet
  - risk: düşük
  - staging notu: ortak stack bloğu tek hunk olarak alınabilir

- `.pd-order-tabs`
  - satır aralığı: `931-935`
  - alınmalı mı? Evet
  - risk: düşük
  - staging notu: `.pd-order-tab*` ile birlikte alınmalı

- `.pd-order-tab`, `.pd-order-tab.is-active`, `.pd-order-tab:hover`
  - satır aralığı: `937-960`
  - alınmalı mı? Evet
  - risk: düşük
  - staging notu: base/active/hover aynı patch içinde alınmalı

- `.pd-order-grid-2`, `.pd-order-grid-3`, `.pd-order-grid-4`, `.pd-order-form-grid`, `.pd-order-flow-grid`, `.pd-order-kpi-strip`, `.pd-order-summary-grid`
  - satır aralığı: `963-993`, responsive `1669-1678`
  - alınmalı mı? Evet
  - risk: orta
  - staging notu: responsive tek kolon dönüşleriyle birlikte alınmalı

- `.pd-order-kpi`, `.pd-order-kpi-label`, `.pd-order-kpi-value`
  - satır aralığı: `995-1011`
  - alınmalı mı? Evet
  - risk: düşük
  - staging notu: KPI trio birlikte alınmalı

- `.pd-order-summary-panel`
  - satır aralığı: `1013-1018`, responsive `1631-1636`
  - alınmalı mı? Evet
  - risk: orta
  - staging notu: sticky ve mobile `position: static` birlikte alınmalı

- `.pd-order-list-row`, `.pd-order-step-row`, `.pd-order-history-row`, `.pd-order-package-card`
  - satır aralığı: `1020-1048`
  - alınmalı mı? Evet
  - risk: düşük
  - staging notu: row varyantları tek grupta alınmalı

- `.pd-order-package-items`
  - satır aralığı: `1050-1054`
  - alınmalı mı? Evet
  - risk: düşük
  - staging notu: net blok

- `.pd-order-form-grid`, `.pd-order-form-grid .full`, `.pd-order-summary-cell-full`, `.pd-order-form-grid label`, `.pd-order-form-note`
  - satır aralığı: `1056-1081`
  - alınmalı mı? Evet
  - risk: düşük
  - staging notu: form grid ve helper note birlikte alınmalı

- `.pd-order-item-table`, `.pd-order-item-table th`, `.pd-order-item-table td`
  - satır aralığı: `1083-1100`
  - alınmalı mı? Evet
  - risk: orta
  - staging notu: table primitive global `table` selectoru ile karıştırılmamalı

- `.pd-order-package-builder`, `.pd-order-package-builder .pd-order-package-card`, `.pd-order-subcard`
  - satır aralığı: `1102-1110`
  - alınmalı mı? Evet
  - risk: orta
  - staging notu: `.pd-order-subcard` order detail’e bağlı görünüyor, aynı patch’te alınabilir

- `.pd-order-package-toolbar`, `.pd-order-package-actions`, `.pd-order-flow-actions`
  - satır aralığı: `1112-1123`
  - alınmalı mı? Evet
  - risk: düşük
  - staging notu: action/toolbars birlikte alınmalı

- `.pd-order-summary-cell`, `.pd-order-summary-cell span`, `.pd-order-summary-cell strong`
  - satır aralığı: `1125-1142`
  - alınmalı mı? Evet
  - risk: düşük
  - staging notu: base/span/strong beraber alınmalı

- `.pd-order-item-name`, `.pd-order-item-meta`
  - satır aralığı: `1144-1155`
  - alınmalı mı? Evet
  - risk: düşük
  - staging notu: item table ile ilişkili olduğu için komşu alınmalı

- `.pd-order-flow-card-shell`, `.pd-order-flow-card`, `.pd-order-flow-head`, `.pd-order-flow-title`, `.pd-order-flow-text`, `.pd-order-flow-meta`, `.pd-order-flow-warning`
  - satır aralığı: `1157-1198`
  - alınmalı mı? Evet
  - risk: orta
  - staging notu: operasyon merkezi kartları çekirdek bloktur, tam grup halinde alınmalı

- `.pd-order-inline-link`, `.pd-order-inline-link:hover`
  - satır aralığı: `1200-1210`
  - alınmalı mı? Evet
  - risk: düşük
  - staging notu: revision/repeat aksiyonlarının order detail içi link görünümü için gerekli

- `.pd-table tbody tr[data-order-row]`, `.pd-order-row-selected td`, `.pd-order-row-selected td:first-child`
  - satır aralığı: `1213-1228`
  - alınmalı mı? Evet, dikkatli
  - risk: orta
  - staging notu: bunlar order row etkileşimi için gerekli; global `.pd-table` değil yalnız order row davranışı alınmalı

- `.pd-order-detail`, `.pd-order-status`, `.pd-order-warning`, `.pd-order-quick-links`, `.pd-order-side-panel`, `.pd-order-action`, `.pd-order-tab-panel`
  - satır aralığı: yeni ayrı selector olarak tespit edilmedi
  - alınmalı mı? Hayır, çünkü mevcut diff’te bağımsız blok görünmüyor
  - risk: düşük
  - staging notu: raporda “ayrı selector bulunmadı” olarak ele alınmalı

## 4. Operation Center CSS Analizi

- sipariş detay ana layout
  - bloklar: `.pd-order-layout`, `.pd-order-stack`, `.pd-order-summary-panel`
  - commit’e dahil edilmeli mi? Evet
  - ayrı CSS commit gerekir mi? Hayır
  - patch staging sınırı net mi? Evet
  - risk: orta

- operasyon merkezi kartları
  - bloklar: `.pd-order-flow-grid`, `.pd-order-flow-card*`, `.pd-order-kpi*`
  - commit’e dahil edilmeli mi? Evet
  - ayrı CSS commit gerekir mi? Hayır
  - patch staging sınırı net mi? Evet
  - risk: orta

- grafik / tedarik / üretim / teslimat / finans kartları
  - bloklar: aynı `.pd-order-flow-card*` ailesi içinde temsil ediliyor
  - commit’e dahil edilmeli mi? Evet
  - ayrı CSS commit gerekir mi? Hayır
  - patch staging sınırı net mi? Evet
  - risk: orta

- sağ panel
  - bloklar: `.pd-order-summary-panel`, `.pd-order-summary-grid`, `.pd-order-summary-cell*`
  - commit’e dahil edilmeli mi? Evet
  - ayrı CSS commit gerekir mi? Hayır
  - patch staging sınırı net mi? Evet
  - risk: orta

- kısa özet
  - bloklar: `.pd-order-summary-grid`, `.pd-order-summary-cell*`, `.pd-order-kpi*`
  - commit’e dahil edilmeli mi? Evet
  - ayrı CSS commit gerekir mi? Hayır
  - patch staging sınırı net mi? Evet
  - risk: düşük

- sıradaki işlem
  - bloklar: `.pd-order-step-list`, `.pd-order-step-row`, `.pd-order-flow-actions`
  - commit’e dahil edilmeli mi? Evet
  - ayrı CSS commit gerekir mi? Hayır
  - patch staging sınırı net mi? Evet
  - risk: düşük

- uyarılar
  - bloklar: `.pd-order-flow-warning`, `.pd-order-form-note`
  - commit’e dahil edilmeli mi? Evet
  - ayrı CSS commit gerekir mi? Hayır
  - patch staging sınırı net mi? Evet
  - risk: düşük

- hızlı bağlantılar
  - bloklar: `.pd-order-inline-link`, `.pd-order-package-toolbar`, `.pd-order-package-actions`, `.pd-order-flow-actions`
  - commit’e dahil edilmeli mi? Evet
  - ayrı CSS commit gerekir mi? Hayır
  - patch staging sınırı net mi? Evet
  - risk: düşük

- sipariş kalemleri
  - bloklar: `.pd-order-item-table*`, `.pd-order-item-name`, `.pd-order-item-meta`
  - commit’e dahil edilmeli mi? Evet
  - ayrı CSS commit gerekir mi? Hayır
  - patch staging sınırı net mi? Evet
  - risk: orta

- finans özeti
  - bloklar: `.pd-order-summary-grid`, `.pd-order-summary-cell*`, `.pd-order-kpi*`
  - commit’e dahil edilmeli mi? Evet
  - ayrı CSS commit gerekir mi? Hayır
  - patch staging sınırı net mi? Evet
  - risk: düşük

- tamamlandı / açık süreç görünürlüğü
  - bloklar: `.pd-order-row-selected*`, `.pd-order-sticky-statuses`, `.pd-order-history-row`
  - commit’e dahil edilmeli mi? Evet
  - ayrı CSS commit gerekir mi? Hayır
  - patch staging sınırı net mi? Evet
  - risk: orta

- revision / repeat UI butonları
  - bloklar: `.pd-order-inline-link`, `.pd-order-flow-actions`
  - commit’e dahil edilmeli mi? Evet, yalnız görünüm amaçlı order detail içi parçalar
  - ayrı CSS commit gerekir mi? Hayır
  - patch staging sınırı net mi? Evet
  - risk: düşük

## 5. Dışarıda Kalacak Selectorlar

- quote detail/send modal
  - dışarıda kalanlar: `.promotion-quote-detail`, `.quote-detail-compact`, `.quote-send-modal`, `.quote-channel-pill`, `.quote-strip`, `.quote-right-summary`, `.promotion-quote-lines`, diğer ilgisiz `.quote-*`
  - neden: ayrı checkpoint ile tamamlandı
  - hangi sonraki faza kalmalı: gerekirse quote detail bakım fazları
  - order detail için zorunlu mu? Hayır

- Product Hub
  - dışarıda kalanlar: `.pd-product-hub`, `.pd-request-hub-*`, `.pd-product-diagnostic-*`, `.pd-hub-preview-shell`
  - neden: farklı feature yüzeyi
  - hangi sonraki faza kalmalı: Product Hub / Template Master Plan
  - order detail için zorunlu mu? Hayır

- Revision compare
  - dışarıda kalanlar: `.order-revision-compare`, `.orc-*`
  - neden: bağımsız revision compare ekranı
  - hangi sonraki faza kalmalı: revision compare CSS checkpoint
  - order detail için zorunlu mu? Hayır

- quote/order list
  - dışarıda kalanlar: `.pd-orders-index-*`, `.pd-quote-workspace`, list chip/counter/sticky panel stilleri
  - neden: liste ekranı stilleri
  - hangi sonraki faza kalmalı: list/checkpoint bakımı
  - order detail için zorunlu mu? Hayır

- shared/global
  - dışarıda kalanlar: `:root`, `html`, `body`, `a`, `table`, `input`, `button`, `.pd-btn`, `.pd-card`, `.pd-badge`, `.pd-tabs`, `.pd-modal`, `.pd-sticky-bar`, `body.pd-modal-open`, genel utility selectorlar
  - neden: primitive katman; feature sınırını aşıyor
  - hangi sonraki faza kalmalı: CSS Template Master Plan
  - order detail için zorunlu mu? Dolaylı taban sağlar ama bu commit için zorunlu değil

## 6. Dar Commit Planı

- Olası Commit A
  - mesaj: `ui: add order detail operation center styles`
  - dahil edilecekler:
  - `893-1228` bandındaki order detail operation center selectorları
  - responsive order detail satırları `1622-1678` içinden yalnız `.pd-order-*` selectorlarına ait olanlar
  - dışarıda bırakılacaklar:
  - shared/global primitive
  - quote detail/send modal CSS
  - Product Hub CSS
  - revision compare CSS
  - quote/order list CSS
  - routes/config/blade/php/controller/test dosyaları
  - risk:
  - yüksek, patch staging şart

- Olası Commit B
  - mesaj: `docs: add order detail css prep report`
  - dahil edilecekler:
  - bu rapor
  - apply sonrası rapor

## 7. Patch Staging Stratejisi

- dosya bazlı staging kesinlikle kullanılmamalı
- yalnız `.pd-order-*` order detail operation center blokları patch stage edilmeli
- çekirdek hunk sırası önerisi:
  - `893-1228` order detail core
  - `1622-1678` order detail responsive satırları
- `git diff --cached -- public/css/prodelya-admin.css` her patch sonrası kontrol edilmeli
- staged diff içinde aşağıdakiler görünürse commit atılmamalı:
  - `.promotion-quote-detail`
  - `.quote-send-modal`
  - `.pd-product-hub`
  - `.order-revision-compare`
  - `.orc-`
  - `.pd-btn`
  - `.pd-modal`
  - `:root`
- staged diff içinde yalnız order detail selectorları kalmalı

## 8. Commit’e Alınmayacaklar

- shared/global primitive katmanı
- quote detail/send modal CSS
- Product Hub CSS
- revision compare CSS
- quote/order list CSS
- `routes/web.php`
- `config/admin_menu.php`
- blade/php/controller dosyaları
- test dosyaları

## 9. Test Sonuçları

- `OrderDetailOperationalFlowUx|OrderShowTabbedLayout|OrderCompletedDecisionSafety`
  - 5 test, 55 assertion, passed
- `Order`
  - 212 test, 1771 assertion, passed
- `OrderRevision|RepeatOrder`
  - 51 test, 314 assertion, passed
- `PromotionQuote`
  - 126 test, 1190 assertion, passed
- `AdminSmokeTest|FullOperationalFlowSmokeTest`
  - 60 test, 644 assertion, passed

Toplam:

- 454 test passed
- 3974 assertion passed

## 10. Smoke Planı

- `/admin/orders/{id}` aç
- sipariş detay operasyon merkezi layout’unu kontrol et
- grafik / tedarik / üretim / teslimat / finans kartlarını kontrol et
- sağ panel ve kısa özet hücrelerini kontrol et
- hızlı bağlantıları kontrol et
- sipariş kalemleri tablosunu kontrol et
- finans özetini kontrol et
- revizyon / tekrar sipariş link ve aksiyon yerleşimini kontrol et
- mobil/responsive görünümü `<=1024px` kırılımında kontrol et

## 11. Kalan Riskler

- order detail selector sınırı
  - `.pd-order-flow` satırı üstte `.pd-product-hub` ile aynı grupta geçtiği için tek başına alınmamalı
- shared primitive bağımlılığı
  - buton, kart, modal ve genel tokenlar shared katmana dayanıyor
- responsive bloklar
  - `@media (max-width: 1024px)` içinde shared grid selectorlarıyla karışık; yalnız `.pd-order-*` satırları dikkatle seçilmeli
- Template Master Plan çakışması
  - primitive katman sonradan değişirse order detail görsel ayarı tekrar gerekebilir

## 12. Net Karar

**CSS-ORDER-DETAIL-COMMIT-APPLY yapılabilir.**

Şart:

- `.pd-order-flow` ile başlayan shared grup dikkatle ayrılmalı
- responsive media bloğunda yalnız order detail satırları stage edilmeli
- shared/global primitive kesinlikle commit’e karışmamalı

## 13. Sonraki Adım

- `CSS-ORDER-DETAIL-COMMIT-APPLY`
