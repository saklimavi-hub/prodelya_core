# LIVE-B1-M9C-R4 Local Stock Display Label Report — 2026-07-17

Status: RECOVERED — COMPACT LOCAL AND SUPPLIER STOCK METADATA — MANUAL SMOKE REQUIRED

## Scope
- Quote workspace dropdown + selected-row metadata display only
- No reservation, procurement, pricing, database, or global CSS changes

## Applied presentation contract
- Operational exact local stock exists: show `Local stok: <exact>`
- Operational exact missing but catalog projection exists: show `Local stok: <projection>`
- Neither exists: omit the local stock fragment
- Keep supplier stock separate as `Tedarikçi stok: <value>`
- Keep `Güncellendi: ...`
- Do not show user-facing technical/provenance labels such as:
  - `Yerel stok doğrulanamadı`
  - `Katalog stok`
  - `Katalog stok bilgisi`
  - `reason_code`
  - per-row recheck note text

## Implementation
- Updated shared builder in `resources/views/admin/promotion-quotes/_form-workspace.blade.php`
- Added `resolveCompactLocalStockDisplay(...)`
- Updated shared compact metadata generation so dropdown/selected/edit/old-input all use the same Local stok wording
- Hidden canonical provenance payload remains unchanged

## Tests
- `PromotionQuoteCompactLocalStockLabelTest`: PASS (`tests=1`, `assertions=5`)
- `PromotionQuoteCatalogProjectionDisplaysAsLocalStockTest`: PASS (`tests=1`, `assertions=5`)
- `PromotionQuoteNoUnresolvedStockTextTest`: PASS (`tests=1`, `assertions=5`)
- `PromotionQuoteSupplierStockRemainsSeparateTest`: PASS (`tests=1`, `assertions=3`)
- `PromotionQuoteMetadataUpdatedAtPreservedTest`: PASS (`tests=1`, `assertions=3`)
- `PromotionQuoteMetadataHydrationParityTest`: PASS (`tests=1`, `assertions=6`)
- `PromotionQuoteCreateEditUiRegressionTest`: PASS (`tests=5`, `assertions=119`)
- `PromotionQuoteWorkspaceJavascriptContractTest`: PASS (`tests=2`, `assertions=19`)
- Broad `PromotionQuote`: PASS (`tests=197`, `assertions=1663`)
- Broad `CatalogSearch`: PASS (`tests=8`, `assertions=52`)
- Broad `Stock`: PASS (`tests=54`, `assertions=492`)
- Broad `AdminSmokeTest`: PASS (`tests=59`, `assertions=214`)

## Manual expectation
For `ET-0506-MV`, expected compact metadata line:
- `Etkin Promosyon · SKU: ET-0506-MV · Local stok: 1.000 · Tedarikçi stok: 27.800 · Güncellendi: 29.06.2026 06:46`

Must not appear:
- `Yerel stok doğrulanamadı`
- `Katalog stok`
- `Katalog stok bilgisi`
- technical provenance keys
- blank chip/icon

## Worktree / staging / commit
- Staging: none
- Commit: none

## Manual PASS Addendum
MANUAL PASS — accepted compact metadata and workspace behavior
