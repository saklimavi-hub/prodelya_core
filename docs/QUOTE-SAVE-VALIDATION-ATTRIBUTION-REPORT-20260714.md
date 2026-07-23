# Quote Save Validation Attribution Report - 2026-07-14

## Scope
- Prompt: `docs/prompts/PRODELYA_V1_10.16.5_F1P2H6_QUOTE_SAVE_VALIDATION_ATTRIBUTION_PROMPT.md`
- Date: 2026-07-14
- Status: `IMPLEMENTED — QUOTE SAVE VALIDATION ROOT CAUSE EXPOSED AND ROW-LEVEL ERRORS MAPPED — MANUAL SMOKE PENDING`
- Staging/commit: none

## Exact Browser Attribution
Headless browser probe and captured request/response traces showed two distinct behaviors.

### A. Unresolved sales price snapshot blocks save
- Request: `POST /admin/promotion-quotes`
- Result: validation redirect back to `GET /admin/promotion-quotes/create`
- Exact backend key: `items.0.price_snapshot`
- Exact message: `Ürün fiyat özeti okunamadı. Satırı yeniden seçip tekrar deneyin.`
- Proven blocker: catalog-linked product row with `list_price=0.00` and missing usable sales snapshot

### B. Missing intermediate/setup originally did not block save
- Request included setup-required print option with empty setup fields
- Result before fix: quote saved successfully
- Root cause: backend silently accepted option defaults through setup normalization
- Risk: visible UI suggested `Ara eleman gerekir`, but missing explicit setup still passed

### C. Configured setup passes
- Request with explicit `Klişe: Yeni üretilecek / Ayarlandı` payload saved successfully
- This remained the expected PASS contract

## Applied Fixes

### Backend
- Added exact print-row validation key builder in `PromotionQuoteController`
- Added explicit setup-presence check for setup-required print options
- Added exact print-row validation error key:
  - `items.{item}.prints.{print}.setup_requirement`
- Preserved existing unresolved sales snapshot validation key:
  - `items.{item}.price_snapshot`

### Quote workspace UI
- Added row-level error mapping into initial item/print payloads
- Added client summary links targeting exact backend-style paths
- Added automatic focus/scroll to the first invalid row
- Added client-side setup-required guard for print rows
- Preserved old input and existing initial-row boot contract

## Tests
Executed on 2026-07-14.

- `php artisan test --filter=PromotionQuoteSaveValidationAttribution --stop-on-failure` PASS
- `php artisan test --filter=PromotionQuoteWorkspaceInitialRowContract --stop-on-failure` PASS
- `php artisan test --filter=PromotionQuoteSalesListCurrencyUi --stop-on-failure` PASS
- `php artisan test --filter=PromotionQuotePrintOptionIntegration --stop-on-failure` PASS
- `php artisan test --filter=PromotionQuote --stop-on-failure` PASS

## Manual Smoke Gate
Pending user manual smoke.

Expected manual checkpoints:
- exact backend error bag visible through row mapping
- unresolved price row highlighted correctly
- missing setup print row highlighted correctly
- configured setup row not blocked
- old input preserved
- no duplicate initial row
- no 404/405/500

## Worktree
- Production code edited
- Targeted tests added/updated
- No staging performed
- No commit performed
- `.tmp` probe artifacts not staged
