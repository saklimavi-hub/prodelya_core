# Public Graphic Approval Cleanup Commit Apply Raporu — 2026-07-10

## 1. Özet
- Yeni kod yazıldı mı?: Hayır. Mevcut worktree’de hazır duran cleanup hunkları seçici staging ile commitlendi.
- Kaç commit oluşturuldu?: 2
- Migration çalıştırıldı mı?: Hayır
- DB’ye dokunuldu mu?: Hayır
- CSS alındı mı?: Hayır
- Route dosyası alındı mı?: Hayır
- Menü dosyası alındı mı?: Hayır

## 2. Commit Listesi
- `public-graphics: use named routes in approval actions`
  - hash: `dfbce43`
  - dosyalar:
    - `resources/views/public/graphics/approval/show.blade.php`
    - `tests/Feature/PublicGraphicApprovalRouteTest.php`
  - test sonucu:
    - `PublicGraphicApprovalRouteTest` 4/4 geçti
    - `PublicQuoteApproval|QuoteApproval` 34/34 geçti
    - `AdminSmokeTest|FullOperationalFlowSmokeTest` 60/60 geçti
- `docs: add public graphic approval cleanup report`
  - hash: bu rapor commit'i
  - dosyalar:
    - `docs/ROUTE-MENU-PUBLIC-GRAPHIC-CLEANUP-PREP-RAPORU-20260710.md`
    - `docs/PUBLIC-GRAPHIC-APPROVAL-CLEANUP-COMMIT-APPLY-RAPORU-20260710.md`
  - test sonucu: docs-only commit; ek kod testi gerekmedi

## 3. Hunk Staging Notları
- public graphics approval show blade
  - approve form action hard-coded `onayla` yerine `public.graphics.approval.approve` named route’una çevrildi
  - revision form action hard-coded `revize-iste` yerine `public.graphics.approval.revision` named route’una çevrildi
- `PublicGraphicApprovalRouteTest`
  - guest yüzeyin doğru approve/revision action URL’lerini render ettiğini doğrulayan assertionlar eklendi
- dışarıda bırakılan dosyalar
  - `routes/web.php`
  - `config/admin_menu.php`
  - `public/css/prodelya-admin.css`
  - `app/Http/Controllers/Admin/PromotionQuoteController.php`
  - `app/Http/Controllers/Admin/OrderController.php`
  - Product Hub route/menu hunkları
  - quote/order UI ve ilgisiz test/docs dosyaları

## 4. Davranış Sonucu
- approve form action
  - artık `public.graphics.approval.approve` named route’unu kullanıyor
- revision form action
  - artık `public.graphics.approval.revision` named route’unu kullanıyor
- named route kullanımı
  - relative path yerine Laravel route üretimi kullanıldığı için guest yüzey daha güvenli ve daha kararlı hale geldi
- token parametresi
  - route parametresi olarak korunuyor
- guest public graphic approval yüzeyi
  - çalışmaya devam ediyor; testler ve smoke filtreleri yeşil

## 5. Dışarıda Bırakılanlar
- `routes/web.php`
- `config/admin_menu.php`
- `public/css/prodelya-admin.css`
- Product Hub
- quote/order UI
- public approval quote core
- notification service-core
- revision/repeat core
- unrelated docs/tests

## 6. Final Test Sonuçları
- `PublicGraphicApprovalRouteTest`: geçti, 4 test
- `PublicQuoteApproval|QuoteApproval`: geçti, 34 test
- `ProductHubLiveProductInfoEndpointTest|PromotionQuoteLiveProductInfoUiTest`: geçti, 14 test
- `PromotionQuote`: geçti, 126 test
- `Order`: geçti, 212 test
- `AdminSmokeTest|FullOperationalFlowSmokeTest`: geçti, 60 test

## 7. Full Suite Durumu
- Full suite çalıştırılmadı
- İstenen final test matrisi tamamen geçti

## 8. Kalan Worktree Durumu
- menu cleanup kaldı mı?: Evet. `config/admin_menu.php` ve `tests/Feature/TenantProductCatalogMenuSimplificationTest.php` worktree’de kaldı.
- Product Hub route cleanup kaldı mı?: Evet. `routes/web.php` içindeki `admin.catalog.search` ve churn ayrıştırması hâlâ worktree’de duruyor.
- CSS/template kaldı mı?: Evet. `public/css/prodelya-admin.css` worktree’de kaldı.
- docs/test cleanup kaldı mı?: Evet. İlgisiz docs ve quote/order list test dosyaları hâlâ worktree’de duruyor.

## 9. Net Karar
- Public graphic approval cleanup tamamlandı mı?: Evet
- Sonraki checkpoint grubuna geçilebilir mi?: Evet. En temiz küçük guest-surface cleanup güvenli şekilde commitlendi.

## 10. Sonraki Öneri
- `MENU-PRODUCT-HUB-FINANCE-CLEANUP-PREP`
- veya `PRODUCT-HUB-ROUTE-CLEANUP-PREP`
- veya `CSS-QUOTE-DETAIL-SEND-MODAL-HUNK-STAGING-PREP`
- veya `TEMPLATE-MASTER-PLAN`
