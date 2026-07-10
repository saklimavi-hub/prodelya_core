# Route / Menu / Public Graphic Cleanup Prep Raporu — 2026-07-10

## 1. Özet
- Yeni kod yazıldı mı?: Hayır
- Staging/commit yapıldı mı?: Hayır
- CSS değiştirildi mi?: Hayır
- Worktree korundu mu?: Evet. Hiçbir dosya restore edilmedi, silinmedi veya stage edilmedi.

## 2. Kalan Git Durumu
- modified
  - `config/admin_menu.php`
  - `routes/web.php`
  - `resources/views/public/graphics/approval/show.blade.php`
  - `tests/Feature/PublicGraphicApprovalRouteTest.php`
  - `tests/Feature/TenantProductCatalogMenuSimplificationTest.php`
  - ayrıca bu faz kapsamı dışında kalan controller/CSS/test değişiklikleri worktree’de duruyor
- untracked
  - `docs/CSS-TEMPLATE-HUNK-STAGING-PREP-RAPORU-20260710.md`
  - çeşitli önceki checkpoint/cleanup raporları
  - quote/order list ile ilgili test dosyaları
  - geçici `.tmp_*` dosyaları
- route/menu/public graphic dosyaları
  - `routes/web.php`
  - `config/admin_menu.php`
  - `resources/views/public/graphics/approval/show.blade.php`
- ilgili test/docs dosyaları
  - `tests/Feature/PublicGraphicApprovalRouteTest.php`
  - `tests/Feature/TenantProductCatalogMenuSimplificationTest.php`
  - dolaylı etki alanı: `ProductHubLiveProductInfoEndpointTest`, `PromotionQuoteLiveProductInfoUiTest`, `PublicQuoteApproval*`, `PromotionQuote*`, `Order*`, `AdminSmokeTest`, `FullOperationalFlowSmokeTest`

## 3. routes/web.php Analizi
- Product Hub route hunkları
  - gerçek davranış değişikliği var mı?: Evet. `Route::get('/catalog/search', [CatalogSearchController::class, 'search'])->name('catalog.search');` satırı gerçek route ekliyor.
  - hangi checkpoint ile ilişkili?: Product Hub ve quote detail/edit ekranlarındaki `catalogSearchUrl` kullanımıyla ilişkili; özellikle `product-hub` zinciri ve son quote detail/edit worktree’si bu route’a referans veriyor.
  - bu cleanup fazına alınmalı mı?: Evet, ama yalnız route’un kendisi ayrı hunk olarak.
  - dışarıda mı kalmalı?: Product Hub controller/service tarafı dışarıda kalmalı.
  - test karşılığı nedir?: `ProductHubLiveProductInfoEndpointTest`, `PromotionQuoteLiveProductInfoUiTest`, dolaylı olarak `PromotionQuote` filtresi.
  - risk seviyesi nedir?: Orta. Route eksik kalırsa admin katalog arama akışında runtime sorun yaratabilir.
- revision/repeat route churn
  - kapsam: `revision-compare`, `revision-apply`, `revision-draft.store`, `repeat-order-draft.store`
  - gerçek davranış değişikliği var mı?: Hayır. Diff içerikleri line-ending/BOM normalizasyonu gibi görünüyor; rota adları, URI’ler ve controller hedefleri aynı.
  - hangi checkpoint ile ilişkili?: revision/repeat checkpointleri
  - bu cleanup fazına alınmalı mı?: Hayır
  - dışarıda mı kalmalı?: Evet
  - test karşılığı nedir?: `OrderRevisionCompare*`, `RevisionRepeatOrder*`, `Order` filtresi
  - risk seviyesi nedir?: Düşük, ama gereksiz churn olarak commit’e girerse scope kirletir.
- public graphic approval hunkları
  - gerçek davranış değişikliği var mı?: `routes/web.php` diff içinde public graphic approve/revision route ekleme/değiştirme görünmüyor.
  - hangi checkpoint ile ilişkili?: public graphic blade named-route düzeltmesi bu dosyadaki mevcut route adlarına dayanıyor.
  - bu cleanup fazına alınmalı mı?: Route dosyası açısından hayır; blade tarafı tek başına yeterli görünüyor.
  - test karşılığı nedir?: `PublicGraphicApprovalRouteTest`
  - risk seviyesi nedir?: Düşük
- quote/order route hunkları
  - gerçek davranış değişikliği var mı?: Hayır. quote/order detail veya send-channel için yeni route diffi görünmüyor.
  - bu cleanup fazına alınmalı mı?: Hayır
  - risk seviyesi nedir?: Düşük
- churn
  - BOM: dosya başında BOM temizliği var
  - line-ending: revision/repeat bloklarında CRLF/LF farkı izlenimi veriyor
  - boş satır: `catalog.warnings` ile `catalog.search` arasında eski boş satır yerine yeni gerçek route var; sonda ekstra boş satır farkı da var
  - gereksiz diff: dosya sonu boş satır ve revision/repeat satırlarındaki biçimsel churn

## 4. config/admin_menu.php Analizi
- Product Hub label
  - gerçek davranış/dil değişikliği var mı?: Evet. `ÃœrÃ¼n Veri Merkezi` bozuk metni `Ürün Veri Merkezi` olarak düzeliyor.
  - bu cleanup fazına alınmalı mı?: Evet
  - Product Hub/menu cleanup commit’i mi olmalı?: Evet
  - canlı öncesi genel menü cleanup’a mı kalmalı?: Gerekirse kalabilir, ama küçük ve güvenli bir cleanup commit’ine de uygun
  - test karşılığı nedir?: `TenantProductCatalogMenuSimplificationTest`, ayrıca `AdminMenuVisibilityTest`, `AdminMenuServiceTest`, `ProductDataHubFlowAndCategoryMoveTest`
  - risk seviyesi nedir?: Düşük
- Finans/Tahsilatlar
  - gerçek davranış/dil değişikliği var mı?: Evet. tenant menüde `Tahsilatlar` etiketi `Finans` oluyor.
  - izin görünürlüğü değişikliği var mı?: Evet. `permission_any` ile `view_current_account_transactions`, `manage_current_account_transactions`, `cancel_current_account_transactions` eklendi.
  - hangi kullanıcı rollerini etkiler?: cari işlem yetkisi olan ama modül görünürlüğü menü kararında daha önce atlanabilen kullanıcıları etkileyebilir; finance/current account yetkili kullanıcılar için görünürlüğü netleştirir.
  - bu cleanup fazına alınmalı mı?: Evet, ama label ve permission birlikte alınmalı; yarım bırakılmamalı.
  - Product Hub/menu cleanup commit’i mi olmalı?: Evet, daha doğrusu genel `menu cleanup` commit’inin parçası olabilir.
  - canlı öncesi genel menü cleanup’a mı kalmalı?: Alternatif olarak evet, fakat test koruması mevcut olduğu için ayrı küçük commit olarak da uygun.
  - test karşılığı nedir?: `TenantProductCatalogMenuSimplificationTest`, `FinanceMenuAuthorizationConsistencyTest`, `TenantAdminFinancePermissionBootstrapTest`, `AdminSmokeTest`
  - risk seviyesi nedir?: Orta. Menü görünürlüğü davranışını etkiliyor.
- quote/order ilişkisi
  - teklif/sipariş menüsü ile doğrudan ilgili hunk var mı?: Hayır
  - bu cleanup fazına alınmalı mı?: quote/order checkpointlerinden bağımsız tutulmalı
  - risk seviyesi nedir?: Düşük
- churn
  - whitespace/line-ending: dosya başında BOM temizliği, sonda boş satır farkı var
  - unrelated metinler: quote/order ile ilgisiz
  - karar: churn satırları dışarıda bırakılmalı

## 5. Public Graphic Approval Analizi
- form action
  - approve formu artık hard-coded `onayla` yerine `route('public.graphics.approval.approve', ['token' => $request->token])` kullanıyor
  - revision formu artık hard-coded `revize-iste` yerine `route('public.graphics.approval.revision', ['token' => $request->token])` kullanıyor
- named route
  - gerçek davranış değişikliği var mı?: Evet. Relative path kırılganlığını kaldırıp named route + token bağlamına sabitleniyor.
- guest yüzey
  - bu dosya public graphic approval guest yüzeyidir
  - quote/order UI ile ilgisi var mı?: Hayır
  - public approval core ile karışıyor mu?: Komşu alan ama aynı core değil; ayrı guest approval surface
- güvenlik etkisi
  - tenant/token yüzeyi etkileniyor mu?: Evet, olumlu yönde. Form action’ın doğru token’lı named route’a gitmesi garanti oluyor.
  - Türkçe karakter / kullanıcı-facing metin değişikliği var mı?: Hayır
- test karşılığı
  - birincil test: `tests/Feature/PublicGraphicApprovalRouteTest.php`
  - dolaylı koruma: `PublicGraphicApprovalSecurityTest`, `PublicApprovalAndTrackingSecuritySmokeTest`, `CustomerPortalAndPublicFlowSecurityRegressionTest`
- karar
  - ayrı public graphic approval cleanup commitine uygundur
  - route değişikliği gerekli mi?: Görünen diffte hayır; mevcut named route’lar zaten tanımlı ve kullanılıyor
  - yalnız blade değişikliği yeterli mi?: Evet, bu küçük cleanup için yeterli görünüyor

## 6. Test Dosyaları Analizi
- `tests/Feature/PublicGraphicApprovalRouteTest.php`
  - hangi cleanup hunkını koruyor?: blade içindeki approve/revision form action’larının doğru named route üretmesini
  - bu fazda commitlenmeli mi?: Evet, public graphic cleanup commit’i ile birlikte
  - dışarıda mı kalmalı?: route/menu commitlerinden ayrı tutulması daha iyi
- `tests/Feature/TenantProductCatalogMenuSimplificationTest.php`
  - hangi cleanup hunkını koruyor?: super admin menüde `Ürün Veri Merkezi` label’ını
  - bu fazda commitlenmeli mi?: Evet, menu cleanup commit’i ile birlikte
  - dışarıda mı kalmalı?: public graphic cleanup’tan ayrı tutulmalı
- `tests/Feature/PromotionQuoteAndOrderIndexHeaderPanelTest.php`
  - hangi cleanup hunkını koruyor?: quote/order list dil cleanup’ı
  - bu fazda commitlenmeli mi?: Hayır
  - dışarıda mı kalmalı?: Evet, quote/order list cleanup’a ait
- `tests/Feature/PromotionQuoteAndOrderIndexUxTest.php`
  - hangi cleanup hunkını koruyor?: quote list başlık dil cleanup’ı
  - bu fazda commitlenmeli mi?: Hayır
  - dışarıda mı kalmalı?: Evet
- `ProductHubLiveProductInfoEndpointTest`
  - hangi cleanup hunkını koruyor?: Product Hub route ve canlı bilgi uçlarının bozulmamasını
  - bu fazda commitlenmeli mi?: Hayır
  - dışarıda mı kalmalı?: Evet, regresyon güvenliği olarak çalıştırılmalı
- `PromotionQuoteLiveProductInfoUiTest`
  - hangi cleanup hunkını koruyor?: `catalog.search` ve Product Hub canlı bilgi tarafıyla ilişkili teklif UI entegrasyonunu
  - bu fazda commitlenmeli mi?: Hayır
  - dışarıda mı kalmalı?: Evet
- `PublicQuoteApproval|QuoteApproval`
  - hangi cleanup hunkını koruyor?: genel public approval davranışının etkilenmediğini
  - bu fazda commitlenmeli mi?: Hayır
  - dışarıda mı kalmalı?: Evet
- `PromotionQuote`
  - hangi cleanup hunkını koruyor?: route/menu cleanup’ın quote yüzeylerini kırmamasını
  - bu fazda commitlenmeli mi?: Hayır
  - dışarıda mı kalmalı?: Evet
- `Order`
  - hangi cleanup hunkını koruyor?: menu/route churn’ün order yüzeylerini kırmamasını
  - bu fazda commitlenmeli mi?: Hayır
  - dışarıda mı kalmalı?: Evet
- `AdminSmokeTest|FullOperationalFlowSmokeTest`
  - hangi cleanup hunkını koruyor?: menü görünürlüğü ve genel akışların bozulmamasını
  - bu fazda commitlenmeli mi?: Hayır
  - dışarıda mı kalmalı?: Evet

## 7. Commit Planı
- Commit A
  - mesaj: `routes: align remaining product hub and public graphic route wiring`
  - dosyalar: `routes/web.php`
  - hunk notu: yalnız gerçek davranış hunkı olan `admin.catalog.search`; BOM, revision/repeat churn ve sonda boş satır diffleri dışarıda
  - dışarıda bırakılacaklar: `revision-compare`, `revision-apply`, `revision-draft.store`, `repeat-order-draft.store` churn satırları
  - risk: orta
  - test önerisi: `ProductHubLiveProductInfoEndpointTest|PromotionQuoteLiveProductInfoUiTest`, `PromotionQuote`, `AdminSmokeTest|FullOperationalFlowSmokeTest`
- Commit B
  - mesaj: `menu: polish product hub and finance menu labels`
  - dosyalar: `config/admin_menu.php`, `tests/Feature/TenantProductCatalogMenuSimplificationTest.php`
  - hunk notu: `Ürün Veri Merkezi` label düzeltmesi, `Tahsilatlar -> Finans`, `permission_any` görünürlük hunkı; BOM ve son boş satır churn’ü dışarıda
  - dışarıda bırakılacaklar: quote/order list testleri, Product Hub core kodu
  - risk: orta
  - test önerisi: `TenantProductCatalogMenuSimplificationTest`, `FinanceMenuAuthorizationConsistencyTest`, `AdminSmokeTest|FullOperationalFlowSmokeTest`
- Commit C
  - mesaj: `public-graphics: use named routes in approval actions`
  - dosyalar: `resources/views/public/graphics/approval/show.blade.php`, `tests/Feature/PublicGraphicApprovalRouteTest.php`
  - hunk notu: yalnız approve/revision form action route düzeltmeleri ve ilgili assertionlar
  - dışarıda bırakılacaklar: public approval guest mail/template core, başka public approval dosyaları
  - risk: düşük-orta
  - test önerisi: `PublicGraphicApprovalRouteTest`, `PublicQuoteApproval|QuoteApproval`, `AdminSmokeTest|FullOperationalFlowSmokeTest`
- Commit D
  - mesaj: `docs: add route menu public graphic cleanup prep report`
  - dosyalar: bu rapor ve apply sonrası üretilecek rapor
  - hunk notu: docs-only
  - dışarıda bırakılacaklar: ilgisiz docs
  - risk: düşük
  - test önerisi: docs-only
- sıralama önerisi
  - en güvenli sıra: önce `public-graphics`, sonra `menu`, sonra gerekirse `routes`
  - neden: public graphic en küçük ve en temiz ayrışan hunk; menu orta riskli ama iyi testli; route dosyası ise gereksiz churn barındırdığı için en dikkatli patch’i gerektiriyor

## 8. Commit’e Alınmayacaklar
- `public/css/prodelya-admin.css`
- quote detail / order detail blade dosyaları
- `app/Http/Controllers/Admin/PromotionQuoteController.php`
- `app/Http/Controllers/Admin/OrderController.php`
- Product Hub core servis/controller değişiklikleri
- Revision core
- Public approval page/mail/template core
- Notification service-core
- quote/order list/order detail/quote detail checkpoint dosyaları
- `.env`
- `database.sqlite`
- `storage`
- `vendor`
- `node_modules`
- log/screenshot/debug/temp dosyaları
- `tests/Feature/PromotionQuoteAndOrderIndexHeaderPanelTest.php`
- `tests/Feature/PromotionQuoteAndOrderIndexUxTest.php`
- untracked quote/order list testleri

## 9. Test Sonuçları
- `PublicGraphicApprovalRouteTest`: geçti, 4 test
- `TenantProductCatalogMenuSimplificationTest`: geçti, 3 test
- `ProductHubLiveProductInfoEndpointTest|PromotionQuoteLiveProductInfoUiTest`: geçti, 14 test
- `PublicQuoteApproval|QuoteApproval`: geçti, 34 test
- `PromotionQuote`: geçti, 126 test
- `Order`: geçti, 212 test
- `AdminSmokeTest|FullOperationalFlowSmokeTest`: geçti, 60 test
- Full suite: çalıştırılmadı

## 10. Smoke Planı
- `/admin/super-admin/product-data-hub` veya ilgili Product Hub menü bağlantısı
- tenant admin menüde `Ürün Veri Merkezi` etiketi
- Finans menüsü / tahsilat bağlantıları
- public graphic approval guest sayfası
- public graphic approval approve formu
- public graphic approval revision formu
- quote/order screens etkilenmemiş mi?
- Product Hub live product info etkilenmemiş mi?

## 11. Kalan Riskler
- route churn: `routes/web.php` içindeki revision/repeat satırları gerçek davranış değil, ama yanlışlıkla commit’e girerse scope kirlenir
- menu permission riski: `permission_any` menü görünürlüğünü etkiler; yarım veya eksik alınmamalı
- public guest form riski: düşük, ama named route ve token bağlamı doğru korunmalı
- Product Hub/menu karışması: Product Hub route ve menu label aynı cleanup ailesinde olsa da ayrı commitler daha güvenli

## 12. Net Karar
- önce yalnız public graphic approval cleanup yapılmalı

## 13. Sonraki Adım
- `ROUTE-MENU-PUBLIC-GRAPHIC-CLEANUP-COMMIT-APPLY`
- veya ardından `CSS-QUOTE-DETAIL-SEND-MODAL-HUNK-STAGING-PREP`
- daha geniş görsel toparlama için `TEMPLATE-MASTER-PLAN`
