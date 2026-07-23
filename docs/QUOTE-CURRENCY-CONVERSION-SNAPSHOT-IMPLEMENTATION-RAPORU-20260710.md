# Quote Currency Conversion Snapshot Implementation Raporu — 2026-07-10

## 1. Faz özeti

- Faz tipi: Backend + Quote Workflow + Production UI Implementation
- Amaç: teklif document currency, item snapshot, print snapshot, rate refresh/acknowledge, send snapshot freeze ve customer-facing güvenli çıktılarını production akışına bağlamak
- Bu çalışmada order/procurement carryover uygulanmadı

## 2. Preview onayı

- Referans preview: `docs/ui-previews/prodelya_teklif_currency_tl_usd_eur_onizleme.html`
- Preview yaklaşımı production bilgi mimarisi için esas alındı
- Preview topbar, senaryo paneli ve demo kontrolleri production’a taşınmadı

## 3. Ground truth audit

| Katman | Mevcut alan | Bugünkü anlam | Currency hedefi | Değişiklik |
|---|---|---|---|---|
| `orders.currency` | quote/order para birimi | legacy TL ağırlıklı | canonical `TRY/USD/EUR` document currency | `TRY` normalize edildi, ikinci document currency kolonu açılmadı |
| `order_items.price_snapshot` | satır fiyat özeti | ürün/toplam/KDV | source/base/document snapshot contract | güvenli şekilde genişletildi |
| `order_item_prints` | baskı toplamları | document-facing fiyatlar | print pricing snapshot | `pricing_snapshot` eklendi |
| `quote_send_snapshots.snapshot_json` | gönderim anı müşteri snapshotı | immutable teklif görünümü | currency metadata + document totals | snapshot builder genişletildi |
| create/edit workspace | quote form JS + hidden snapshots | tek para birimi varsayımı | compact document currency controls | payload ve etiketler güncellendi |
| PDF/public approval | customer-facing çıktılar | quote currency | yalnız belge para birimi | internal cost/rate leak kapalı tutuldu |

## 4. Veri modeli kararı

- `orders.currency` document currency olarak korundu
- Yeni additive alanlar:
  - `tenant_base_currency`
  - `currency_policy`
  - `currency_snapshot_summary`
  - `rates_refreshed_at`
  - `rates_refreshed_by`
  - `current_rate_acknowledged_at`
  - `current_rate_acknowledged_by`
  - `currency_snapshot_locked_at`
- `order_item_prints.pricing_snapshot` eklendi

## 5. Migration/backward compatibility

- Migration: `database/migrations/2026_07_10_210000_add_quote_currency_snapshot_fields.php`
- Additive ve reversible tutuldu
- Legacy `TL` okuması compatibility katmanında `TRY` normalize edilerek ele alındı
- Toplu backfill veya bulk repricing yapılmadı
- Local DB: `sqlite`
- Backup alındı: `database/backups/database-before-quote-currency-2026-07-10.sqlite`
- `php artisan migrate --path=database/migrations/2026_07_10_210000_add_quote_currency_snapshot_fields.php` başarılı çalıştı
- Migration sonrası doğrulama:
  - `orders`: 30
  - `quotes`: 21
  - `order_items`: 40
  - `order_item_prints`: 39
  - yeni kolonlar schema üzerinde mevcut
- Not: `php artisan migrate:status --path=...` bu ortamda halen `Pending` döndü; ancak `migrations` tablosunda kayıt oluştu ve schema kolonları gerçek olarak eklendi

## 6. Quote-level currency contract

- `orders.currency` canonical document currency olarak yazılıyor
- `currency_snapshot_summary` içinde:
  - `document_currency`
  - `tenant_base_currency`
  - `currency_policy`
  - `requested_date`
  - `snapshot_version`
  - `overall_status`
  - `status_counts`
- `rates_refreshed_at/by` her authoritative save/refresh sonrası güncelleniyor
- `currency_snapshot_locked_at` ilk müşteri gönderiminde kilitleniyor

## 7. Item/print/helper snapshot

- `order_items.price_snapshot` içinde en az şu alanlar korunuyor:
  - `source_price`
  - `source_currency`
  - `source_list_price`
  - `source_net_price`
  - `tenant_base_currency`
  - `base_cost`
  - `document_currency`
  - `suggested_sales_unit_price_base`
  - `suggested_sales_unit_price_document`
  - `actual_sales_unit_price_base`
  - `actual_sales_unit_price_document`
  - `manual_sales_price_override`
  - `applied_rate`
  - `rate_source`
  - `rate_type`
  - `rate_date`
  - `fallback_used`
  - `stale`
  - `conversion_legs`
  - `document_conversion_status`
  - KDV ve toplam alanları
- `order_item_prints.pricing_snapshot`:
  - `document_currency`
  - `tenant_base_currency`
  - `document_unit_price`
  - `document_total`
  - `base_unit_price`
  - `base_total`
  - `manual_override`
  - `requested_date`
  - `snapshot_version`

## 8. Access/module/permission

- Yeni access servisi: `app/Services/PromotionQuote/QuoteCurrencyAccessService.php`
- `multi_currency` kapalı:
  - yalnız `TRY`
  - foreign document currency reddedilir
- `multi_currency` açık:
  - `TRY/USD/EUR`
- Rate status ve timestamp yalnız `can_view_currency_details=true` kullanıcıya gösterilir
- Refresh/acknowledge aksiyonları finance-detail gate arkasında tutuldu

## 9. Create/edit behavior

- Workspace currency select canonical `TRY/USD/EUR` ile çalışıyor
- Kullanıcı etiketi `TRY` için `TL` gösteriyor
- Controller request’ten gelen browser total/rate truth’unu kabul etmiyor; server-side snapshot ve toplam kuruyor
- Draft edit ekranında kompakt `Kuru Yenile` ve `Mevcut Kuru Koru` aksiyonları eklendi

## 10. Manual price preservation

- `manual_sales_price_override` ayrı tutuluyor
- Refresh aksiyonu manuel satış fiyatını ezmiyor
- Önerilen satış fiyatı ile actual satış fiyatı ayrı alanlarda korunuyor

## 11. Rate refresh/keep behavior

- Yeni route’lar:
  - `admin.promotion-quotes.currency.refresh`
  - `admin.promotion-quotes.currency.acknowledge`
- Refresh:
  - draft-only
  - DB’deki mevcut rate/core servislerini kullanır
  - manual actual sale price korunur
  - summary metadata güncellenir
- Keep current:
  - draft-only
  - `current_rate_acknowledged_at/by` yazar
  - fiyatları değiştirmez

## 12. Send snapshot immutability

- `QuoteApprovalService` ilk gönderimde `currency_snapshot_locked_at` yazar
- `QuoteSendSnapshotBuilder` item/print document değerlerini snapshot’a taşır
- Sonraki draft değişiklikleri eski `quote_send_snapshots.snapshot_json` içeriğini geriye dönük değiştirmez

## 13. Detail/PDF/public behavior

- Quote detail ekranına compact document currency/status alanı eklendi
- PDF service document-facing unit/line/print totals için snapshot alanlarını kullanıyor
- Public approval controller `TRY` etiketini `TL` olarak gösteriyor
- Customer-facing yüzeylerde supplier cost/rate detail sızdırılmıyor

## 14. Security/tenant isolation

- Tenant scoped quote erişimi korunuyor
- Foreign currency kullanımı module/capability gate ile kontrol ediliyor
- Public approval tarafında internal/raw payload leak guard korunuyor
- Live TCMB HTTP çağrısı eklenmedi

## 15. Rounding/math

- Mevcut Currency Core servisleri kullanıldı
- Conversion exception’ları güvenli `null/status` davranışına çevrildi
- Server-side totals authoritative tutuldu

## 16. Changed files

- `database/migrations/2026_07_10_210000_add_quote_currency_snapshot_fields.php`
- `app/Models/Order.php`
- `app/Models/OrderItemPrint.php`
- `app/Services/PromotionQuote/QuoteCurrencyAccessService.php`
- `app/Services/PromotionQuote/QuoteCurrencyPricingService.php`
- `app/Http/Controllers/Admin/PromotionQuoteController.php`
- `app/Services/QuoteSendSnapshotBuilder.php`
- `app/Services/PromotionQuotePdfService.php`
- `app/Services/CustomerFacingPriceDisplayService.php`
- `app/Services/QuoteApprovalService.php`
- `app/Http/Controllers/PublicQuoteApprovalController.php`
- `resources/views/admin/promotion-quotes/_form-workspace.blade.php`
- `resources/views/admin/promotion-quotes/show.blade.php`
- `routes/web.php`
- `tests/Feature/PromotionQuoteCurrencySnapshotTest.php`

## 17. Added tests

- `tests/Feature/PromotionQuoteCurrencySnapshotTest.php`
  - module kapalıyken canonical `TRY`
  - item pricing snapshot manual sale protection
  - send lock metadata
  - refresh manual price preservation

## 18. Targeted tests

- `php artisan test --filter=PromotionQuoteCurrencySnapshotTest`
- `php artisan test --filter=PromotionQuoteCreateEditUiRegressionTest`
- `php artisan test --filter=PublicQuoteApprovalCustomerPriceDisplayTest`
- `php artisan test --filter=PromotionQuotePdfOutputTest`

Sonuç:

- `PromotionQuoteCurrencySnapshotTest`: 4 test, hepsi geçti
- `PromotionQuoteCreateEditUiRegressionTest`: 5 test, hepsi geçti
- `PublicQuoteApprovalCustomerPriceDisplayTest`: 1 test, geçti
- `PromotionQuotePdfOutputTest`: 4 test, hepsi geçti
- `PromotionQuoteCurrency|PromotionQuoteCreateEdit|PromotionQuotePdf|PublicQuoteApprovalCustomerPriceDisplay`: 14 test, geçti

Detay:

- `PromotionQuoteCurrencySnapshotTest`: 4 test / 14 assertion / 3.46 sn / failed 0 / risky 0 / skipped 0
- `PromotionQuoteCreateEditUiRegressionTest`: 5 test / 98 assertion / 4.35 sn / failed 0 / risky 0 / skipped 0
- `PublicQuoteApprovalCustomerPriceDisplayTest`: 1 test / 16 assertion / 3.47 sn / failed 0 / risky 0 / skipped 0
- `PromotionQuotePdfOutputTest`: 4 test / 48 assertion / 4.64 sn / failed 0 / risky 0 / skipped 0
- Geniş hedefli filtre: 14 test / 176 assertion / 5.42 sn / failed 0 / risky 0 / skipped 0

## 19. Module regressions

- Kök nedenleri çözülen regresyonlar:
  - `AdminSmokeTest::test_promotion_quote_can_be_created_with_basic_payload`
    - legacy test beklentisi `TL` idi
    - canonical storage davranışı `TRY` olarak korundu
    - test beklentisi `TRY` ile hizalandı
  - `FullOperationalFlowSmokeTest::test_full_operational_flow_smoke_covers_all_operation_modules_and_security`
    - finance payment payload ve doğrulama tarafında legacy `TL` / canonical `TRY` uyumsuzluğu vardı
    - smoke payload `TRY` ile hizalandı
    - `StoreOrderPaymentRequest` ve `OrderPaymentService` canonical `TRY` + `TL` alias mantığı ile normalize edildi
- Geçenler:
  - `AdminSmokeTest|FullOperationalFlowSmokeTest`: 60 test / 644 assertion / 11.91 sn
  - `PromotionQuote`: 130 test / 1204 assertion / 24.26 sn
  - `PublicQuoteApproval`: 15 test / 169 assertion / 4.47 sn
  - `QuoteApproval`: 34 test / 324 assertion / 7.05 sn
  - `PromotionQuotePdf`: 4 test / 48 assertion / 2.58 sn
  - `QuoteSend`: 16 test / 93 assertion / 4.32 sn
  - `Currency`: 48 test / 139 assertion / 5.74 sn
  - `CatalogSearchCurrencyPayloadTest`: 3 test / 21 assertion / 2.11 sn
  - `ProductHubLiveProductInfo`: 12 test / 51 assertion / 2.81 sn
- Quote currency verify kapsamındaki hedefli ve geniş regresyonlar artık yeşil
- Ancak full suite aşamasında bu fazın kapsamı dışındaki yeni failurelar görüldüğü için commit/staging’e geçilmedi

## 20. Full suite 17 failure inventory

- Global current worktree full suite sonucu:
  - total: `1832`
  - passed: `1815`
  - failed: `17`
  - assertions: `18125`
  - süre: `411.48 sn`
- Current 17 failure listesi:
  - `Tests.Feature.CompanyContactAddressActionsTest::test_company_detail_shows_active_contact_and_address_actions_with_clean_copy`
  - `Tests.Feature.CompanyContactAddressActionsTest::test_contact_and_address_empty_states_are_user_friendly`
  - `Tests.Feature.DemoTenantFullAccessTest::test_demo_tenant_can_access_active_modules_features_and_planned_items_stay_closed`
  - `Tests.Feature.FinanceNotificationIntegrationTest::test_finance_payment_notifications_emit_safely_and_do_not_break_payment_workflow`
  - `Tests.Feature.NotificationSecurityHardeningTest::test_notification_feature_guards_and_menu_visibility_follow_access_rules`
  - `Tests.Feature.OrderPaymentCurrentAccountTransactionTest::test_order_payments_sync_into_current_account_transactions_safely_and_idempotently`
  - `Tests.Feature.OrderPaymentReducesCustomerDebitBalanceTest::test_payment_sync_reduces_order_receivable_balance_and_marks_partial_then_paid`
  - `Tests.Feature.PermanentCategoryBackboneLockTest::test_product_data_hub_overview_shows_category_reset_metrics`
  - `Tests.Feature.ProductionTurkishTerminologyTest::test_production_tabs_use_turkish_labels_and_hide_broken_terms`
  - `Tests.Feature.PublicApprovalAndTrackingSecuritySmokeTest::test_public_surfaces_respect_token_boundaries_feature_guards_and_attachment_visibility`
  - `Tests.Feature.PublicApprovalAndTrackingSecuritySmokeTest::test_public_surfaces_hide_forbidden_fields_and_notification_failures_do_not_break_actions`
  - `Tests.Feature.PublicGraphicApprovalSecurityTest::test_public_show_uses_request_attachment_and_hides_sensitive_fields`
  - `Tests.Feature.PublicLinkScreensUxPolishTest::test_public_link_screens_use_customer_facing_copy_and_keep_security_boundaries`
  - `Tests.Feature.SettingsNotificationTemplateCssPolishTest::test_settings_and_notification_pages_keep_prodelya_layout_and_single_active_menu_state`
  - `Tests.Feature.SuperAdminTenantPackageOverrideTest::test_super_admin_can_manage_tenant_package_module_feature_and_limit_overrides`
  - `Tests.Feature.TenantDomainSubdomainLocalSmokeTest::test_public_tracking_quote_and_graphic_links_remain_guest_accessible_without_sensitive_leakage`
  - `Tests.Feature.TenantPackageOverviewTest::test_tenant_admin_can_open_package_overview_and_see_usage_modules_and_requests`

## 21. Historical baseline 14 vs reproducibility

- Historical known baseline rapor kanıtı:
  - `docs/CURRENCY-CORE-IMPLEMENTATION-RAPORU-20260710.md`: `1809/1823`, `14 failed`
  - `docs/PRODUCT-DATA-HUB-CURRENCY-PROPAGATION-RAPORU-20260710.md`: `1814/1828`, `14 pre-existing failure`
- Ancak bugünkü attribution resume akışında yapılan iki clean rerun bunu birebir yeniden üretmedi:
  - clean HEAD worktree: `bc07ac0`
  - historical baseline worktree: `6f7ca96`
- Her iki clean worktree’de de aynı seçilmiş failure ailesi `17` failure verdi.
- Bu nedenle historical `14` listesinden bugünkü `17` listesine çıkan üç delta failure bugünün mutable test ortamında birebir yeniden üretilemedi.

## 22. Clean HEAD + file-level attribution

- Clean HEAD worktree (`bc07ac0`) üzerinde aynı 15 failure dosyası çalıştırıldı.
- Sonuç:
  - `CURRENT_COUNT=17`
  - `BASELINE_COUNT=17`
  - `ONLY_CURRENT`: boş
  - `ONLY_BASELINE`: boş
- Yorum:
  - Mevcut kirli worktree’de görülen 17 failure, uncommitted quote currency diff’i çıkarıldığında da aynen devam ediyor.
  - Bu kanıt current observed failure set içinde quote-currency-attributed failure sayısının `0` olduğunu gösterir.
- Quote currency verify kapsamında değişen dosyalar:
  - `app/Http/Requests/Admin/StoreOrderPaymentRequest.php`
  - `app/Services/OrderPaymentService.php`
  - `tests/Feature/AdminSmokeTest.php`
  - `tests/Feature/FullOperationalFlowSmokeTest.php`
  - quote currency controller/service/view/migration/test dosyaları
- Bu dosyalarla ilgili önemli diff özeti:
  - payment request/service tarafında yalnız `TL` => canonical `TRY` alias normalization eklendi
  - smoke test beklentileri canonical `TRY` ile hizalandı
  - quote detail/create/send/public payload tarafında document currency snapshot alanları eklendi
- Buna rağmen payment/current-account/public/package/company ailesindeki 17 failure clean HEAD’de de aynı kaldığı için bu failurelar current quote currency diff’ine bağlanamadı.

## 23. Delta classification result

- Historical baseline referansı: `14 failed`
- Bugünkü clean rerun referansı: `17 failed`
- Teorik delta sayısı: `3`
- Ancak bu üç historical delta bugünkü ortamda net isim bazında izole edilemedi.
- Net sınıflandırma:
  - quote-currency-attributed delta failures: `0`
  - unrelated/current-clean-head failures: `17`
  - historical 14->17 delta attribution: `UNKNOWN`
- Company/Cari classification:
  - `CompanyContactAddressActionsTest::test_company_detail_shows_active_contact_and_address_actions_with_clean_copy`
  - clean HEAD ve historical baseline worktree’de de aynı şekilde kırılıyor
  - beklenen `Müşteri Portalı`, görülen `Portal Kullanıcıları`
  - sınıflandırma: quote currency dışı, Company/Cari copy/menu expectation alanı

## 24. Manual smoke and commit gate

- Ayrı browser smoke koşturulmadı.
- Test-backed smoke karşılıkları doğrulandı:
  - `AdminSmokeTest`
  - `FullOperationalFlowSmokeTest`
  - quote currency hedefli testleri
- Kullanıcı kuralı gereği attribution sonucu `UNKNOWN` kaldığı için selective staging/commit akışına geçilmedi.
- Bu turda:
  - commit oluşturulmadı
  - staging yapılmadı
  - staged alan boş bırakıldı

## 25. Existing worktree protection and next step

- Mevcut kirli worktree korundu.
- Özellikle şu önceden kirli alanlara toplu reset/restore uygulanmadı:
  - `app/Http/Controllers/Admin/OrderController.php`
  - `config/admin_menu.php`
  - `public/css/prodelya-admin.css`
- `git add .`, `git add -A`, `git reset`, `git restore`, `git stash` kullanılmadı.
- Bu faz sonunda karar:
  - quote currency verify kapsamındaki hedefli ve geniş regresyonlar yeşil
  - current observed 17 failure set quote currency diff’inden kaynaklanmıyor
  - historical `14` -> `17` delta bugünkü ortamda temiz biçimde yeniden üretilemediği için attribution `UNKNOWN`
  - bu nedenle selective commit checkpoint güvenli değil
  - Order / Procurement Currency Carryover fazına geçilmedi
## 26. Explicitly not implemented

- order/procurement currency carryover
- manual exchange rate feature
- live TCMB request
- live Product Hub sync/projection
- accounting/payment/current account multi-currency
- Matbaa V2
- Dieline V3
