# PROMOTION INTERMEDIATE ELEMENT FEATURE SUSPENSION REPORT — 2026-07-14

## 1. Kullanıcı kararı
Promosyon teklif -> sipariş -> operasyon akışındaki ara eleman / klişe / setup özelliği silinmeden, global default `false` olacak şekilde askıya alındı.

## 2. Feature/config standardı
Mevcut repo standardı `config/prodelya.php -> features` altında kullanılıyor.
Eklenen anahtar:
- `promotion_intermediate_element_enabled`
- env fallback: `PRODELYA_PROMOTION_INTERMEDIATE_ELEMENT_ENABLED=false`

## 3. Canonical policy
Yeni merkezi helper:
- `app/Services/PromotionIntermediateElementPolicy.php`

Ana yetenekler:
- `enabled()`
- `shouldRender()`
- `shouldValidate()`
- `shouldPersist()`
- `shouldGenerateRequirements()`
- `blocksProductionReadiness()`

## 4. Audit edilen yüzeyler
İncelenen ana alanlar:
- `PromotionQuoteController`
- quote workspace Blade/JS
- quote create/edit initial payload
- `WorkFormCreationService`
- `WorkFormDataBuilder`
- `ProductionReadinessResolver`
- `ProductionDataBuilder`
- work form / pdf / order detail / production partial yüzeyleri
- setup requirement servis zinciri
- ilgili feature testleri

## 5. Quote create/edit
Feature default false iken:
- setup validation branchi policy arkasına alındı
- setup payload save sırasında ignored olacak şekilde daraltıldı
- workspace payload’a `intermediateElementEnabled` eklendi
- client-side setup row validation policy ile kapatıldı

## 6. Validation
Korunan validation:
- `items.*.price_snapshot`

Kapatılan validation:
- `items.*.prints.*.setup_requirement` yalnız feature true iken çalışır

## 7. Persistence
Feature false iken yeni kayıtlarda setup alanları persistence sırasında gate edildi:
- `cliche_status`
- `setup_pricing_enabled`
- `setup_type`
- `setup_status`
- `setup_total_amount`
- `setup_distribution_quantity`
- `setup_unit_amount`

## 8. Order conversion
Work form creation sırasında yeni setup requirement üretimi policy arkasına alındı.
Feature false iken yeni setup requirement oluşturulmaz.

## 9. Work Form / PDF
Work form ve PDF yüzeylerinde setup/klişe metinlerini gizleyen view gating manuel smoke ile doğrulandı.

## 10. Production readiness
`ProductionReadinessResolver` içinde setup blocker feature gate arkasına alındı.
Feature false iken setup readiness sonucu:
- ready = true
- required = false
- blocking labels = []

## 11. Public/customer/notification
Bu fazda doğrudan sızıntı oluşturan setup field’leri için backend tarafında yeni persistence ve readiness üretimi kapatıldı.
Customer/public yüzeylerde setup görünmemesi manuel smoke ile PASS doğrulandı.

## 12. Existing data preservation
Yapılmayanlar:
- migration/schema değişikliği yok
- setup tablo/kolon silinmedi
- geçmiş setup kayıtları silinmedi
- backfill / cleanup çalıştırılmadı

## 13. Legacy re-enable test
Mevcut legacy setup davranışına bağımlı quote testlerinde `config(true)` ile geri-açma doğrulandı.

## 14. Tests
Geçen hedefli testler:
- `php artisan test --filter=PromotionIntermediateElementFeatureFlag --stop-on-failure`
- `php artisan test --filter=PromotionQuoteSaveValidationAttribution --stop-on-failure`
- `php artisan test --filter=PromotionQuotePrintOptionIntegration --stop-on-failure`
- `php artisan test --filter=PromotionQuotePrintSetupPricing --stop-on-failure`
- `php artisan test --filter=QuoteToOrderPrintSetupPricingCarryover --stop-on-failure`

Eklenen test:
- `tests/Feature/PromotionIntermediateElementFeatureFlagTest.php`

## 15. Manual smoke
Kullanıcı manuel smoke sonucu PASS verdi.
Doğrulananlar:
- Promotion Intermediate Element Suspension Manual Smoke: PASS
- Quote create setup UI hidden: PASS
- Quote edit legacy setup hidden: PASS
- Setup validation disabled: PASS
- Price snapshot validation preserved: PASS
- Quote saves without setup: PASS
- No new setup record: PASS
- Order conversion without setup: PASS
- Work Form setup hidden: PASS
- PDF setup hidden: PASS
- Production readiness ignores setup only: PASS
- Public quote approval setup hidden: PASS
- Customer-facing setup hidden: PASS
- Existing setup data preserved: PASS
- Create JS/row boot preserved: PASS
- Console errors: NONE
- 404/405/500: NONE

## 16. Worktree / staging / commit
- staged alan bu fazda kullanılmadı
- commit yapılmadı
- `.tmp` ve preview dosyaları stage edilmedi

## 17. F1P2 / F1P3 next gate
F1P2 gate closed.
F1P3 gate OPEN.
Bu rapor güncellemesi dışında staging/commit yapılmadı.

## 18. Future template reference paths
Dokunulmadı:
- `docs/ui-previews-new/*`
