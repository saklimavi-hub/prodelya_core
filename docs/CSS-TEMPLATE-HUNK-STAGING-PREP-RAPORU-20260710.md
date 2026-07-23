# CSS / Template Hunk Staging Prep Raporu — 2026-07-10

## 1. Özet
- Yeni kod yazıldı mı?: Hayır. Bu faz yalnız audit, sınıflandırma, test ve raporlama için kullanıldı.
- Staging/commit yapıldı mı?: Hayır. Staged area başta boştu, faz sonunda da boş kaldı.
- CSS değiştirildi mi?: Hayır
- Route/menu değiştirildi mi?: Hayır
- Worktree korundu mu?: Evet. Hiçbir mevcut değişiklik restore edilmedi, silinmedi veya topluca taşınmadı.

## 2. Kalan Git Durumu
- Modified:
  - `app/Http/Controllers/Admin/OrderController.php`
  - `app/Http/Controllers/Admin/PromotionQuoteController.php`
  - `app/Models/Order.php`
  - `config/admin_menu.php`
  - `public/css/prodelya-admin.css`
  - `resources/views/public/graphics/approval/show.blade.php`
  - `routes/web.php`
  - `tests/Feature/PromotionQuoteAndOrderIndexHeaderPanelTest.php`
  - `tests/Feature/PromotionQuoteAndOrderIndexUxTest.php`
  - `tests/Feature/PublicGraphicApprovalRouteTest.php`
  - `tests/Feature/TenantProductCatalogMenuSimplificationTest.php`
- Untracked:
  - geçici çalışma dosyaları: `.tmp_quote_detail_commit_target.blade.php`, `.tmp_quote_detail_show_worktree_backup.blade.php`
  - docs raporları ve plan dosyaları
  - quote/order list ile ilgili kalan test dosyaları
- CSS dosyaları:
  - `public/css/prodelya-admin.css`
- route/menu dosyaları:
  - `routes/web.php`
  - `config/admin_menu.php`
- public graphic dosyaları:
  - `resources/views/public/graphics/approval/show.blade.php`
  - `tests/Feature/PublicGraphicApprovalRouteTest.php`
- docs/test dosyaları:
  - docs tarafında birden çok untracked checkpoint/cleanup raporu
  - tests tarafında çoğunlukla quote/order list ve menu/public graphic doğrulama testleri

## 3. CSS Blok Analizi
- Quote detail CSS
  - dosya/satır veya selector: `public/css/prodelya-admin.css:5017-6203`, `.promotion-quote-detail.quote-detail-compact`, `.quote-page-head`, `.quote-strip`, `.quote-top-metrics`, `.quote-layout`, `.quote-right-summary`, `.quote-tabs`, `.quote-bottom-bar`, `.quote-action-band`
  - ilişkili checkpoint: `quotes: refine quote detail unified decision surface`
  - risk: orta. Kendi namespace'i güçlü ama `.pd-btn`, `.pd-sticky-bar`, `.pd-modal` primitive’lerine dayanıyor.
  - staging önerisi: tek başına dosya bazlı staging güvenli değil; selector bazlı patch staging gerekir.
  - değerlendirme: mevcut checkpointi tamamlayan görsel katman. Fonksiyonel testten çok görsel kalite ve okunabilirlik için gerekli.
- Send modal CSS
  - dosya/satır veya selector: `public/css/prodelya-admin.css:5887-6118`, `.quote-send-grid`, `.quote-send-form-grid`, `.quote-send-modal`, `.quote-send-modal-panel`, `.quote-send-modal-head`, `.quote-send-modal-grid`, `.quote-send-modal-actions`, `.quote-channel-pill`
  - ilişkili checkpoint: `quotes: wire quote send channel controller actions` ve unified quote detail UI
  - risk: orta. Quote detail namespace altında ama shared modal/button primitive’leriyle bağlı.
  - staging önerisi: quote detail CSS ile aynı commit içinde patch staging daha güvenli.
  - değerlendirme: gönderim yüzeyinin tamamlayıcı stili. Özellikle WhatsApp Link, E-posta Önizleme ve modal ergonomisi için önemli.
- Order detail CSS
  - dosya/satır veya selector: `public/css/prodelya-admin.css:915-1191`, responsive devamı `1627-1676`, `.pd-order-layout`, `.pd-order-tabs`, `.pd-order-summary-panel`, `.pd-order-flow-grid`, `.pd-order-summary-grid`, `.pd-order-item-table`, `.pd-order-flow-card`
  - ilişkili checkpoint: `orders: turn order detail into operation flow center`
  - risk: orta. Blok sınırı büyük ölçüde net, ama aynı dosya içindeki shared pd primitive’lere dayanıyor.
  - staging önerisi: ayrı commit olabilir; patch staging uygun. Dosya bazlı staging güvenli değil.
  - değerlendirme: fonksiyonel test değil, operasyon merkezi ekranının okunabilirliği için gerekli.
- Quote/order list CSS
  - dosya/satır veya selector: `public/css/prodelya-admin.css:877-910`, `1953-1961`, `2253`, `.pd-orders-index-layout`, `.pd-orders-sticky-panel`, `.pd-order-sticky-actions`, `.pd-quote-workspace`
  - ilişkili checkpoint: `quotes: refine quote list views and filters`, `orders: refine order list views and completed filters`
  - risk: orta-yüksek. Liste görünümü ile detail workspace yardımcı stilleri karışık duruyor.
  - staging önerisi: daha küçük prep gerekir; doğrudan commit yerine mini hunk prep önerilir.
  - değerlendirme: quote/order UI tamamlayıcısı, ama mevcut büyük CSS diff içinde ayırmak dikkat ister.
- Public approval admin UI CSS
  - dosya/satır veya selector: quote detail bloğu içinde `quote-alert`, `quote-log-list`, `quote-history-row`, `quote-decision-list`, `quote-send-card`, `quote-summary-line`
  - ilişkili checkpoint: public approval + quote detail unified checkpoint
  - risk: orta. Quote detail ile iç içe.
  - staging önerisi: quote detail/send modal CSS ile birlikte ele alınmalı; ayrı commit önerilmez.
  - değerlendirme: müşteri onayı / response history admin yüzeyinin görsel tamamlayıcısı.
- Revision/repeat UI CSS
  - dosya/satır veya selector: quote detail bloğu içinde `source order`, `revision compare`a açılan action bantları; ayrıca `.quote-action-band`, `.quote-channel-pill`, quick action alanları
  - ilişkili checkpoint: revision/repeat metadata ve quote detail unified view
  - risk: orta. Quote detail ile birlikte anlamlı.
  - staging önerisi: quote detail CSS commit’inin içinde kalabilir; ayrı almak gerekmez.
  - değerlendirme: fonksiyonel değil, yön bulma ve CTA netliği için yararlı.
- Product Hub CSS
  - dosya/satır veya selector: `public/css/prodelya-admin.css:803-809`, `2337-2422`, `3744`, `4399-4422` ve `pd-product-hub`, `pd-request-hub-*`, `pd-product-diagnostic-*`, `pd-hub-preview-shell`
  - ilişkili checkpoint: Product Hub release/checkpoint zinciri
  - risk: yüksek. Product Hub shell, preview ve form primitive’leri shared katmanla karışmış.
  - staging önerisi: bu fazda commit edilmemeli; ya ayrı cleanup ya da Template Master Plan sonrası.
  - değerlendirme: Product Hub kalıntısı ve template sistemiyle çakışma riski taşıyor.
- Revision compare CSS
  - dosya/satır veya selector: `public/css/prodelya-admin.css:6204-6415`, `.order-revision-compare`, `.orc-*`
  - ilişkili checkpoint: `quotes: add revision compare and apply flow`
  - risk: düşük-orta. Blok sınırı net.
  - staging önerisi: ayrı commit olabilir ama quote/order template fazına değil, revision compare cleanup fazına daha uygun.
  - değerlendirme: görsel kalite için gerekli; fonksiyonel testten çok layout bütünlüğü sağlar.
- Public graphic approval CSS
  - dosya/satır veya selector: admin CSS içinde anlamlı `ga-*` veya public graphic guest bloğu bulunmadı
  - ilişkili checkpoint: public graphic approval guest sayfası
  - risk: düşük
  - staging önerisi: CSS tarafında alınacak blok görünmüyor
  - değerlendirme: public graphic cleanup route/blade seviyesinde; admin CSS ile aynı faza alınmamalı.
- Shared/global CSS
  - dosya/satır veya selector: `public/css/prodelya-admin.css:1-220`, `499-820`, `3881-3998`, `:root`, `html`, `body`, `table`, `input`, `.pd-btn`, `.pd-card`, `.pd-badge`, `.pd-tabs`, `.pd-modal`, `.pd-sticky-bar`
  - ilişkili checkpoint: çoklu checkpointlerin ortak primitive zemini
  - risk: yüksek. Global selector ve primitive değişiklikleri quote detail, order detail, Product Hub ve diğer ekranları birlikte etkiliyor.
  - staging önerisi: dosya bazlı staging kesinlikle güvenli değil. Ayrı planlama ve dikkatli patch staging gerekir.
  - değerlendirme: görsel kalite için önemli, ama yanlış ayrılırsa geniş regresyon üretir.

## 4. CSS Commit Planı
- Seçenek A: quote detail + send modal CSS tek commit
  - kapsam: `5017-6203` çevresi ve bu bloğun dayandığı minimum shared primitive’ler
  - risk: orta
  - avantaj: son quote detail checkpoint zincirini görsel olarak tamamlar
  - dezavantaj: primitive bağımlılıkları yüzünden dar patch hazırlığı gerekir
  - test/smoke ihtiyacı: quote detail, send modal, approval UI, mobile responsive smoke
  - öneriliyor mu?: Evet, ama route/menu/public graphic cleanup sonrası
- Seçenek B: order detail CSS tek commit
  - kapsam: `915-1191`, ilgili responsive satırlar
  - risk: orta
  - avantaj: order detail checkpoint ile uyumlu, blok sınırı nispeten net
  - dezavantaj: shared primitive bağımlılığı var
  - test/smoke ihtiyacı: order detail tabs, operation center, completed safety smoke
  - öneriliyor mu?: Evet, A’dan sonra
- Seçenek C: quote/order list CSS küçük commit
  - kapsam: `pd-orders-index-*`, `pd-quote-workspace`, sticky panel ve sayaç/aksiyon yardımcı stilleri
  - risk: orta-yüksek
  - avantaj: quote/order list checkpoint görsel tamamlanır
  - dezavantaj: kalan diff içinde küçük ve dağınık; yanlış hunk alma riski var
  - test/smoke ihtiyacı: quote list, order list, counters/tab/empty state smoke
  - öneriliyor mu?: Şimdilik hayır, önce ayrı prep gerekir
- Seçenek D: shared `pd-*` primitive CSS ayrı commit
  - kapsam: `:root`, `pd-btn`, `pd-badge`, `pd-tabs`, `pd-modal`, `pd-sticky-bar`
  - risk: yüksek
  - avantaj: ortak tasarım katmanını toplar
  - dezavantaj: çok fazla ekrana yayılır; tek committe regresyon yüzeyi büyür
  - test/smoke ihtiyacı: çok geniş admin smoke
  - öneriliyor mu?: Hayır
- Seçenek E: CSS çok karışık, önce Template Master Plan yapılmalı
  - kapsam: shared/global + Product Hub + request hub + cross-screen primitive çakışmaları
  - risk: düşük uygulama riski, yüksek gecikme maliyeti
  - avantaj: temiz yeniden haritalama yapılır
  - dezavantaj: hazır duran quote detail/order detail stil bloklarının checkpoint’e alınmasını geciktirir
  - test/smoke ihtiyacı: plan sonrası yeniden hazırlık gerekir
  - öneriliyor mu?: Kısmen. Shared/global ve Product Hub tarafı için evet; fakat net quote detail/order detail blokları tamamen bekletilmek zorunda değil
- Net öneri:
  - CSS commitleri doğrudan şimdi topluca yapılmamalı.
  - En güvenli sıra:
    1. `ROUTE-MENU-PUBLIC-GRAPHIC-CLEANUP-PREP`
    2. yalnız `quote detail + send modal CSS` için dar hunk prep
    3. yalnız `order detail CSS` için dar hunk prep
    4. kalan shared/Product Hub/list CSS için gerekirse `TEMPLATE-MASTER-PLAN`

## 5. routes/web.php Analizi
- Diff grupları:
  - BOM/line-ending churn: dosya başındaki `<?php` BOM temizliği ve sonda boş satır farkı
  - revision/repeat route churn: `revision-compare`, `revision-apply`, `revision-draft.store`, `repeat-order-draft.store` satırlarında esasen line-ending normalizasyonu görünüyor
  - Product Hub route hunkı: `admin.catalog.search` route’u eklenmiş
  - public graphic approval route hunkı: bu diff içinde görünmüyor
  - quote/order UI veya send-channel route: kalan diff içinde yok
- Karar:
  - `routes/web.php` bu aşamada CSS/template fazına alınmamalı
  - ayrı `route/menu/public graphic cleanup` mini fazı daha doğru
  - Product Hub route hunkı daha önceki Product Hub checkpointleriyle ilişkili
  - quote/order template fazına taşınmaması daha güvenli

## 6. config/admin_menu.php Analizi
- Diff grupları:
  - Product Hub label düzeltmesi: bozuk Türkçe karakter `Ürün Veri Merkezi` düzeltmesi
  - tenant menü etiketi: `Tahsilatlar` yerine `Finans`
  - current accounts izin görünürlüğü: `permission_any` eklenmiş
- Sınıflandırma:
  - Product Hub/menu cleanup ile ilişkili
  - tenant-facing Türkçe karakter ve menü görünürlük cleanup'ı
  - quote/order UI checkpointleriyle doğrudan ilişkili değil
- Karar:
  - bu dosya CSS/template fazına alınmamalı
  - `Product Hub/menu cleanup` veya daha genel `route/menu cleanup` fazına ayrılmalı
  - canlı öncesi genel menü cleanup için de uygun aday

## 7. Public Graphic Approval Analizi
- Dosya: `resources/views/public/graphics/approval/show.blade.php`
- Diff içeriği:
  - iki form action’ı hard-coded relative path yerine named route kullanıyor
  - `public.graphics.approval.approve`
  - `public.graphics.approval.revision`
- Sınıflandırma:
  - public grafik onay guest yüzeyi
  - quote/order UI ile doğrudan ilişkili değil
  - public approval core’a komşu ama ayrı cleanup olarak ele alınabilir
- Karar:
  - CSS/template fazına alınmamalı
  - `PUBLIC-GRAPHIC-APPROVAL-CLEANUP` benzeri küçük ayrı faz önerilir
  - ilişkili test: `tests/Feature/PublicGraphicApprovalRouteTest.php`

## 8. Docs/Test Cleanup Analizi
- Quote detail checkpoint raporları
  - `docs/QUOTE-DETAIL-CHECKPOINT-COMMIT-APPLY-RAPORU-20260710.md`
  - `docs/QUOTE-DETAIL-FAILED-STAGING-RESET-AND-SCOPE-REALIGN-RAPORU-20260710.md`
  - `docs/QUOTE-DETAIL-SEND-CHANNEL-HUNK-STAGING-PREP-RAPORU-20260710.md`
  - durum: şu an dışarıda kalmalı; ilgili docs cleanup fazında değerlendirilmeli
- CSS/template prep raporları
  - bu rapor: `docs/CSS-TEMPLATE-HUNK-STAGING-PREP-RAPORU-20260710.md`
  - durum: bu fazın tek yeni çıktısı
- Quote/order UI testleri
  - `tests/Feature/PromotionQuoteAndOrderIndexHeaderPanelTest.php`
  - `tests/Feature/PromotionQuoteAndOrderIndexUxTest.php`
  - `tests/Feature/QuoteOrderListNoSensitiveLeakTest.php`
  - `tests/Feature/QuoteOrderListNoTechnicalUiLeakRegressionTest.php`
  - `tests/Feature/QuoteOrderListTenantIsolationTest.php`
  - `tests/Feature/QuoteOrderListTurkishTerminologyTest.php`
  - `tests/Feature/QuoteOrderManualSmokeRouteTest.php`
  - durum: quote/order list cleanup veya docs/test cleanup fazında commitlenmeli
- Public graphic approval testleri
  - `tests/Feature/PublicGraphicApprovalRouteTest.php`
  - durum: public graphic cleanup fazında commitlenmeli
- Product Hub/menu testleri
  - `tests/Feature/TenantProductCatalogMenuSimplificationTest.php`
  - durum: menu/Product Hub cleanup fazında commitlenmeli
- Final cleanup’a kalacaklar
  - `docs/FULL-SYSTEM-SCAN-20260709.md`
  - `docs/SAFE-ROLLBACK-AUDIT-20260709.md`
  - `docs/WORKTREE-TEMP-CLEANUP-SAFE-RAPORU-20260710.md`
  - `docs/ORDER-DETAIL-TEMP-CLEANUP-SAFE-RAPORU-20260710.md`
  - `docs/10.15.18-C-revizyonu-uygula-teknik-karar-plani.md`
- Geçici/tekrarlı görünenler
  - `.tmp_quote_detail_commit_target.blade.php`
  - `.tmp_quote_detail_show_worktree_backup.blade.php`
  - not: bu fazda silinmedi; yalnız cleanup adayları olarak raporlandı

## 9. Commit’e Alınmayacaklar
- `routes/web.php`
- `config/admin_menu.php`
- `resources/views/public/graphics/approval/show.blade.php`
- `tests/Feature/PublicGraphicApprovalRouteTest.php`
- `tests/Feature/TenantProductCatalogMenuSimplificationTest.php`
- Product Hub request hub / preview shell CSS blokları
- shared/global CSS primitive’lerinin tamamı tek parça halinde
- geçici `.tmp_*` çalışma dosyaları

## 10. Test Sonuçları
- `PromotionQuoteDetail|PromotionQuoteShowDecisionScreen|PromotionQuoteConvertCta`: geçti, 42 test
- `PromotionQuoteSendChannelHotfix|PromotionQuoteSendActionsUx|PromotionQuoteDetailSend|PromotionQuoteDetailWhatsapp|PromotionQuoteDetailPhone`: geçti, 20 test
- `OrderDetailOperationalFlowUx|OrderShowTabbedLayout|OrderCompletedDecisionSafety`: geçti, 5 test
- `PromotionQuote`: geçti, 126 test
- `Order`: geçti, 212 test
- `PublicQuoteApproval|QuoteApproval`: geçti, 34 test
- `NotificationPublicApprovalTokenSanitization|QuoteNotificationIntegration|WhatsappLinkUsesNormalizedPhone`: geçti, 7 test
- `ProductHubLiveProductInfoEndpointTest|PromotionQuoteLiveProductInfoUiTest`: geçti, 14 test
- `AdminSmokeTest|FullOperationalFlowSmokeTest`: geçti, 60 test
- Full suite: çalıştırılmadı

## 11. Smoke Planı
- `/admin/promotion-quotes`
- `/admin/promotion-quotes/{id}`
- teklif detay unified görünüm
- gönderim modalı
- WhatsApp link
- e-posta önizleme
- müşteri onayı alanı
- revizyon bağlantısı
- `/admin/orders`
- `/admin/orders/{id}`
- sipariş operasyon merkezi
- Product Hub canlı ürün bilgisi
- public approval guest link
- public graphic approval sayfası
- menü / Product Hub / Finans menü görünümü

## 12. Kalan Riskler
- global CSS riski: `:root`, `pd-btn`, `pd-badge`, `pd-tabs`, `pd-modal` gibi primitive’ler çok ekrana yayılıyor
- route/menu karışması: `routes/web.php` ve `config/admin_menu.php` aynı fazda CSS ile alınırsa scope bulanıklaşır
- public graphic cleanup: blade ve route testi küçük ama ayrı bir guest-surface sorumluluğu taşıyor
- Product Hub/Menu text hunkları: Product Hub ve menü cleanup kendi fazını hak ediyor
- Template Master Plan çakışması: shared/global CSS önce acele commitlenirse ileride planlı refactor ile çakışabilir

## 13. Net Karar
- route/menu/public graphic cleanup önce yapılmalı

## 14. Sonraki Adım
- `ROUTE-MENU-PUBLIC-GRAPHIC-CLEANUP-PREP`
- veya sonrasında `CSS-TEMPLATE-CHECKPOINT-COMMIT-APPLY`
- daha geniş shared/global toparlama gerekirse `TEMPLATE-MASTER-PLAN`
