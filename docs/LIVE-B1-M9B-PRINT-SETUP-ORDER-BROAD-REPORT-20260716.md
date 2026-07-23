# LIVE-B1-M9B — Print Setup Suspension Attribution and Order Broad Report — 2026-07-16

## 1. Sonuç

Durum:
- `ORDER BROAD CLEAN — MIGRATION APPROVAL GATE TECHNICALLY READY`

Bu turda kanıtlandı:
- canonical global setup feature default'u `false`
- disabled/default durumda quote→order yeni setup requirement üretmez
- explicit enabled capability test katmanında açıldığında requirement creation ve status actions çalışır
- full `Order` broad PASS
- `migrate --pretend` yeniden PASS

Gerçek migrate, data correction, staging veya commit yapılmadı.

## 2. Canonical feature flag audit

Exact global default:
- `config/prodelya.php`
  - `prodelya.features.promotion_intermediate_element_enabled = env(..., false)`

Exact resolver:
- `app/Services/PromotionIntermediateElementPolicy.php`
  - `enabled()` → `config('prodelya.features.promotion_intermediate_element_enabled', false)`
  - `shouldRender()`
  - `shouldValidate()`
  - `shouldPersist()`
  - `shouldGenerateRequirements()`
  - `blocksProductionReadiness()`
  - hepsi aynı canonical flag'e bağlı

Guard zinciri:
- quote form render guard:
  - `resources/views/admin/promotion-quotes/_form-workspace.blade.php`
  - `intermediateElementEnabled`
- quote save/persist guard:
  - `app/Http/Controllers/Admin/PromotionQuoteController.php`
  - `sanitizePrintSetupPayload()`
- quote→order setup requirement generation guard:
  - `app/Services/WorkFormCreationService.php`
  - `if ($this->promotionIntermediateElementPolicy->shouldGenerateRequirements()) { ... }`
- production readiness setup blocking guard:
  - `PromotionIntermediateElementPolicy::blocksProductionReadiness()` üzerinden snapshot/readiness yüzeyleri

## 3. Failing residual attribution

Residual test:
- `Tests\Feature\PrintSetupRequirementCoreTest::test_quote_to_order_conversion_creates_setup_requirements_and_status_actions_update_them_with_tenant_scope`
- observed: expected `setupRequirements = 1`, actual `0`

Kanıtlanan kök neden:
- test explicit feature enable yapmıyordu
- global default `false` olduğu için conversion sırasında `WorkFormCreationService` setup requirement generation bloğuna girmiyordu
- bu durumda `actual = 0` canonical disabled/default davranıştır
- yani ilk failure production regression değil, stale test fixture idi

## 4. Disabled/default contract doğrulaması

Test katmanında sabitlenen contract:
- new quote/order akışında setup feature kapalıysa:
  - setup UI alanları neutralized kalır
  - setup-specific validation inactive olur
  - quote→order new setup requirement oluşturmaz
  - readiness setup nedeniyle bloklanmaz
  - legacy data korunur

Bu sözleşme şu testlerle kanıtlandı:
- `Tests\Feature\PromotionIntermediateElementFeatureFlagTest`
- yeni/ayrılan disabled conversion assertion:
  - `Tests\Feature\PrintSetupRequirementCoreTest::test_quote_to_order_conversion_does_not_create_setup_requirements_when_feature_disabled_by_default`

## 5. Explicit enabled contract doğrulaması

Enabled capability gerçekten destekleniyor.

Exact kanıt:
- `Tests\Feature\QuoteToOrderPrintSetupPricingCarryoverTest`
  - explicit `config()->set('prodelya.features.promotion_intermediate_element_enabled', true)`
  - setup payload carryover PASS
- `Tests\Feature\PrintSetupRequirementCoreTest`
  - enabled conversion + requirement status actions PASS
- `Tests\Feature\PrintSetupRequirementProductionReadinessTest`
  - enabled readiness blocking / release / safe summary PASS

Uygulanan test katmanı hizalamaları:
- `tests/Feature/PrintSetupRequirementCoreTest.php`
  - disabled/default conversion test eklendi
  - requirement/status actions bekleyen test explicit enabled hale getirildi
  - safe setup summary testine explicit enabled eklendi
- `tests/Feature/PrintSetupRequirementProductionReadinessTest.php`
  - class `setUp()` içinde canonical feature key explicit enabled yapıldı

Production code bu turda global olarak açılmadı.

## 6. Full Order broad sırasında açılan ek stale drift

Print setup residual kapandıktan sonra full `Order` broad içinde procurement edit UI testlerinde tarihsel metin drift'i açıldı.

Stale beklentiler:
- `Talep Aksiyonları`
- `Fiyatsız Talep Formunu Aç`
- `Tedarik Listesine Dön`
- `Tedarikçi Talebi Düzenle`

Current canonical procurement edit UI:
- `Tedarikçi Talebi ve Gelen Ürün Kaydı`
- `Taslak Kaydet`
- `Talebi Kaydet`
- `Fiyatsız Talep Formu`
- `Listeye Dön`
- `Tedarikçi Talebi`

Yalnız test katmanında hizalandı:
- `tests/Feature/SupplierRequestEditButtonOrderTest.php`
- `tests/Feature/SupplierRequestEditNoDoubleHeaderTest.php`

Production procurement UI değiştirilmedi.

## 7. Test sonuçları

Hedefli PASS:
- `php artisan test --filter=PrintSetupRequirementCoreTest --stop-on-failure`
- `php artisan test --filter=QuoteToOrderPrintSetupPricingCarryoverTest --stop-on-failure`
- `php artisan test --filter=PrintSetupRequirementProductionReadinessTest --stop-on-failure`
- `php artisan test --filter=SupplierRequestEditButtonOrderTest --stop-on-failure`
- `php artisan test --filter=SupplierRequestEditNoDoubleHeaderTest --stop-on-failure`

Broad PASS:
- `php artisan test --filter=Order --stop-on-failure`
  - `260 tests, 260 passed`

## 8. Migration readiness

`php artisan migrate --pretend` yeniden çalıştırıldı:
- PASS
- pending migrations unchanged:
  - `2026_07_16_120000_add_variant_scope_to_tenant_local_stocks_table`
  - `2026_07_16_120100_create_tenant_stock_reservations_table`
- gerçek schema write yapılmadı

## 9. Sınır ve yapılmayanlar

Bu turda yapılmayanlar:
- `php artisan migrate`
- exact stock row write
- reservation apply
- `TS-2026-0015` correction
- global setup feature açma
- UI/public/PDF davranışı değiştirme
- staging
- commit

## 10. Kapanış kararı

M9B sonucu:
- print setup residual failure stale test olarak kanıtlandı ve canonical feature resolver'a hizalandı
- explicit enabled capability hâlâ çalışıyor ve testlerle doğrulandı
- full `Order` broad temiz
- `migrate --pretend` temiz

Net karar:
- `ORDER BROAD CLEAN — MIGRATION APPROVAL GATE TECHNICALLY READY`
