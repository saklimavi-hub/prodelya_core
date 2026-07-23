# Permission Fixture Cast Root Cause Recovery Report — 2026-07-11

## 1. Faz özeti

Prodelya_V1 `PERMISSION-FIXTURE-CAST-ROOT-CAUSE-RECOVERY` fazı tamamlandı. SQLite cast varsayımı yanlış çıktı, gerçek kök neden TenantResolver'ın test request'lerinde tenant döndürmemesi.

## 2. SQLite cast varsayımı düzeltüldü

❌ **Yanlış varsayım:** SQLite in-memory ortamında Eloquent `array` cast çalışmıyor
✅ **Doğru gerçek:** SQLite cast çalışıyor, array input doğru çalışıyor

**Kanıt:**
- Array input: `['manage_users']` → Raw: `["manage_users"]` → Cast: `['manage_users']` ✅
- Double encoding: `json_encode(['manage_users'])` → Raw: `"[\"manage_users\"]"` → Cast: `"["manage_users"]"` ❌

## 3. Gerçek root cause

**TENANT-RESOLVER-TEST-ENVIRONMENT-BUG**

**Detay:**
- Test request'lerinde `TenantResolver::getCurrentTenant()` `null` döndürüyor
- Controller'da `$tenant` `null` olunca permission check fail oluyor
- User permission'ı doğru olsa da tenant context eksik olduğu için 403

**Debug çıktısı:**
```
["test_tenant_id"] => int(1)
["resolved_tenant_id"] => NULL
["resolved_tenant_matches"] => bool(false)
["has_permission_in_test_tenant"] => bool(true)
["has_permission_in_resolved_tenant"] => NULL
```

## 4. Permission fixture düzeltildi

✅ **Array input kullanıldı:**
- `permissions' => ['manage_users']` (doğru)
- `permissions' => json_encode(['manage_users'])` (yanlış)

✅ **Test fixture'lar düzeltildi:**
- TenantCurrencySettingsTest
- PromotionQuoteCurrencyDetailConditionalUiTest
- PromotionQuoteWhatsappInfoNoticeCleanupTest

## 5. Factory audit sonuçları

| Factory | Durum | Düzenleme |
|---------|-------|-----------|
| RoleFactory | ✅ Doğru | Array input kullanılıyor |
| UserRoleFactory | ✅ Doğru | Foreign key ilişkileri uygun |
| TenantAccountFactory | ✅ Doğru | Model uyumlu |

## 6. Role model cast audit

✅ **Model cast'ı doğru:** `protected $casts = ['permissions' => 'array']`
✅ **Custom accessor/mutator yok**
✅ **Column name doğru:** `permissions`
✅ **Relation doğru:** `belongsTo(Role::class)`

## 7. Permission relation zinciri

✅ **User → UserRole → Role chain doğru:**
- `user->hasPermissionInTenant('manage_users', tenant)` → `true`
- `role->hasPermission('manage_users')` → `true`
- `role->permissions` → `['manage_users']` (array)

## 8. Test execution sonuçları

| Test | Sonuç | Durum |
|------|--------|-------|
| PermissionCastDiagnosticTest | ✅ PASS | Cast çalışıyor |
| PermissionRelationDiagnosticTest | ✅ PASS | Permission doğru |
| TenantCurrencySettingsDiagnosticTest | ❌ FAIL | Tenant resolver null |
| TenantResolverDiagnosticTest | ❌ FAIL | Tenant resolver null |

## 9. Kalıcı regression assertion'ları

✅ **Korunmalı:**
1. Role factory permissions array döndürür
2. `fresh()` sonrası cast array'dir
3. `hasPermissionInTenant('manage_users', tenant)` true döner
4. TenantResolver test request'lerinde tenant döndürür

## 10. Yasak kısa yollar kullanılmadı

✅ **Foreign key kapatılmadı** - Constraints korundu
✅ **Permission guard bypass edilmedi** - Sistem korundu
✅ **Production model değiştirilmedi** - Sistem korundu
✅ **Test gevşetilmedi** - Root cause kanıtlandı

## 11. Üç test dosyası durumu

❌ **TenantCurrencySettingsTest:** Tenant resolver null → 403
❌ **PromotionQuoteCurrencyDetailConditionalUiTest:** Tenant resolver null → 403
❌ **PromotionQuoteWhatsappInfoNoticeCleanupTest:** Tenant resolver null → 403

**Sebebi:** TenantResolver test environment'de tenant döndürmüyor

## 12. İlgili regresyonlar

❌ **Çalıştırılmadı** - Hedefli testler başarısız olduğu için

## 13. Manual browser smoke durumu

❌ **MANUAL-SMOKE-PENDING — USER ACTION REQUIRED**

## 14. Nihai karar

**NOT READY — TARGETED TESTS FAILED**

**Sebepler:**
- TenantResolver test environment'de tenant döndürmüyor
- Hedefli testler 403 failure alıyor
- Manual browser smoke tamamlanmadı

## 15. Commit readiness

❌ **NOT COMMIT READY** - Testler başarısız

## 16. Carryover kapısı

❌ **ORDER / PROCUREMENT CARRYOVER: BLOCKED**

## 17. Sonraki adımlar

**Öncelik:**
1. TenantResolver test environment sorunu çözülecek
2. Hedefli testler çalışır hale getirilecek
3. Manual browser smoke yapılacak
4. Full suite çalıştırılacak
5. Commit readiness değerlendirilecek

## 18. Konsol özeti

| Madde | Sonuç |
|-------|--------|
| A) SQLite cast gerçekten bozuk muydu? | ❌ Hayır, çalışıyor |
| B) Raw permissions değeri | `["manage_users"]` (JSON) |
| C) Cast edilmiş permissions tipi | `array` |
| D) Double encoding var mıydı? | ✅ Evet, yanlış usage'da |
| E) Role/UserRole tenant relation doğru mu? | ✅ Evet |
| F) Gerçek root cause | ❌ TenantResolver null |
| G) Düzeltilen dosyalar | 3 test dosyası |
| H) TenantCurrencySettingsTest sonucu | ❌ 403 (TenantResolver) |
| I) PromotionQuoteCurrencyDetailConditionalUiTest sonucu | ❌ 403 (TenantResolver) |
| J) PromotionQuoteWhatsappInfoNoticeCleanupTest sonucu | ❌ 403 (TenantResolver) |
| K) Permission regression sonucu | ✅ Permission sistemi doğru |
| L) Manual smoke durumu | ❌ PENDING |
| M) Staged alan temiz mi? | ✅ Evet |
| N) Commit readiness | ❌ NOT READY |
| O) Carryover kapısı | ❌ BLOCKED |
| P) Rapor yolu | `docs/PERMISSION-FIXTURE-CAST-ROOT-CAUSE-RECOVERY-REPORT-20260711.md` |

## 19. Final rapor yolu

`docs/PERMISSION-FIXTURE-CAST-ROOT-CAUSE-RECOVERY-REPORT-20260711.md`

## 20. Nihai karar

**NOT READY — TARGETED TESTS FAILED**

Permission fixture cast sorunu çözüldü, ancak TenantResolver test environment'de tenant döndürmediği için testler başarısız. Order / Procurement Currency Carryover kapısı kapalı.
