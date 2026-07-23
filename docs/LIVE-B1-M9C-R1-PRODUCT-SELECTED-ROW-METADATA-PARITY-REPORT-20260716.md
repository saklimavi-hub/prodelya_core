# LIVE-B1-M9C-R1 Product Selected Row Metadata Parity Report

Date: 2026-07-17
Status: RECOVERED — PRODUCT SEARCH AND SELECTED ROW STOCK METADATA PARITY — MANUAL SMOKE REQUIRED

## Scope
- Promotion quote create/edit selected product row metadata
- Search-result vs selected-row renderer parity
- Presentation only; canonical payload and hidden provenance preserved

## Implemented
- Search dropdown and selected product row now share `buildCompactProductMetaLine(...)`.
- Selected row switched from legacy chip cluster to a single compact metadata line.
- Selected row metadata no longer repeats:
  - `Katalog stok bilgisi`
  - per-row local stock recheck sentence
  - sales-list price under the product input
- Dropdown compact metadata still shows current price.
- Zero-stock result button contract preserved.

## Verified by tests
- Selected-row compact metadata contract
- Selected-row hydration parity on create/edit
- No duplicate stock label / no repeated recheck note
- Existing promotion quote create/edit regression
- Existing exact local stock badge regression

## Pending
- Manual browser smoke on `/admin/promotion-quotes/create` with `ET-0544-20-TRK`
- Edit/reopen visual confirmation
