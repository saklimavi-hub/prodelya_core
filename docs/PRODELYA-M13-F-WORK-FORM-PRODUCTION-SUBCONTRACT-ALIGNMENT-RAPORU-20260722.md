# PRODELYA M13-F Work Form Production/Subcontract Alignment Report - 2026-07-22

Final status: READY - WORK FORM PRODUCTION AND SUBCONTRACT ALIGNMENT - MANUAL SMOKE REQUIRED

## Surface inventory
- Admin Work Form: live operational surface. It now builds exact production rows from live OrderItemPrintProduction + exact OrderItemPrint, with exact graphic readiness and exact production photo projection when attachment metadata is present.
- Printable HTML / PDF: versioned snapshot surface. PDF render calls WorkFormRenderDataBuilder with live production rows disabled and reads production_snapshot.production_rows.
- Public Work Form Tracking: customer-safe projection. Public print rows no longer render internal/external route labels.
- Email/PDF renderer: WorkFormPdfService remains the central PDF HTML renderer; no uncontrolled live production query was added for PDF.

## Live vs snapshot decision
- Admin current labels are read from live exact production rows and ProductionReadinessResolver output.
- Production workflow sync writes exact production_rows into the existing Work Form production_snapshot, preserving the PDF/version contract.
- PDF does not switch to uncontrolled live production reads.

## Exact print mapping
- Canonical unit remains OrderItemPrintProduction + exact order_item_print_id.
- Render projection maps sequence, print type, print option, route, operator/subcontractor, planned/completed/remaining, canonical status, graphic label, procurement label, QC projection, final graphic and first production photos per exact print.
- Sibling production photos are filtered by order_item_print_id; legacy unbound photos are used only when a Work Form has one production row.

## Internal/fason field matrix
- Internal: operator, production unit, planned, completed, remaining, canonical status.
- Fason: subcontractor company, sent quantity, received quantity, remaining at subcontractor, prior internal completed quantity when baseline exists.
- Legacy missing subcontract baseline displays only the compact note: "Bu eski kayıtta fason başlangıç miktarı ayrı izlenemiyor."

## Procurement label matrix
- need unrequested: Talep Hazırlanacak
- request draft: Talep Taslağı
- request sent: Tedarik Bekliyor
- partial received: Tedarik Kısmi Tamamlandı
- received: Tedarik Tamamlandı
- not required: Tedarik Gerekli Değil

## Graphic/media matrix
- Graphic readiness still requires exact graphic operation status production_ready plus latest attachment.
- Product reference, graphic visual, production photos and delivery attachments remain separated by attachment_type.
- Production photo upload from production screens now links the canonical attachment to the exact order_item_print_id; no duplicate file or duplicate attachment is created.

## QC decision
- Default/stale QC waiting is not surfaced as "Kalite Kontrol Bekliyor" unless the production is actually in quality_control status.
- Non-required/default rows project "Kalite Kontrol Gerekli Değil".

## PDF/public projection
- PDF: compact exact production table from snapshot production_rows.
- Public: customer-safe data only; route labels, operator, subcontractor, internal notes, finance values, file paths and tokens are not rendered.

## Finance/privacy proof
- No production section renders unit_price, print_total, grand_total, subcontractor_cost, current-account values, file_path, physical_path, token/hash, or Product Hub raw mapping.
- Public attachments continue to use the secure customer-visible endpoint only.

## Tests
- php artisan view:clear: PASS
- php artisan view:cache: PASS
- php artisan test --filter=WorkFormProduction --stop-on-failure: No tests found
- php artisan test --filter=WorkFormPdf --stop-on-failure: PASS, 4 tests / 48 assertions
- php artisan test --filter=WorkFormAttachment --stop-on-failure: PASS, 5 tests / 51 assertions
- php artisan test --filter=PublicWorkForm --stop-on-failure: PASS, 10 tests / 90 assertions
- php artisan test --filter=Production --stop-on-failure: PASS, 163 tests / 2258 assertions
- php artisan test --filter=Graphic --stop-on-failure: PASS, 114 tests / 1572 assertions
- php artisan test --filter=Procurement --stop-on-failure: FAIL, ProcurementDraftPriceRefreshTest expected value="164.49" in supplier request edit HTML; this is outside the Work Form production/subcontract surface and is recorded as an existing broader procurement regression.
- php artisan test --filter=Order --stop-on-failure: PASS, 263 tests / 2371 assertions
- php artisan test --filter=AdminSmokeTest --stop-on-failure: PASS, 59 tests / 214 assertions
- Extra: php artisan test --filter=WorkFormShow --stop-on-failure: PASS, 5 tests / 60 assertions

## Manual smoke
Required. Automated tests passed for Work Form/PDF/Public/Production/Graphic/Order/AdminSmoke except the unrelated Procurement filter failure above. Manual smoke should verify exact 1a/1b/2a rows, graphics, production photos, PDF printability, public privacy, and QC not-required copy in browser.

## Changed files in this phase
- app/Http/Controllers/Admin/WorkFormAttachmentController.php
- app/Models/OrderItemPrintProduction.php
- app/Models/OrderItemProcurement.php
- app/Services/ProductionDataBuilder.php
- app/Services/PublicWorkFormTrackingDataBuilder.php
- app/Services/WorkFormAttachmentService.php
- app/Services/WorkFormPdfService.php
- app/Services/WorkFormRenderDataBuilder.php
- resources/views/admin/work-forms/pdf.blade.php
- resources/views/admin/work-forms/show.blade.php
- resources/views/public/work-forms/track.blade.php
- tests/Feature/WorkFormPdfTest.php
- tests/Feature/WorkFormShowTest.php
- docs/PRODELYA-M13-F-WORK-FORM-PRODUCTION-SUBCONTRACT-ALIGNMENT-RAPORU-20260722.md
- docs/PRODELYA-M13-C4-PRODUCTION-POOL-DETAIL-UI-STATUS-TRUTH-RAPORU-20260721.md
- docs/PRODELYA-M13-E1-SUBCONTRACT-RECEIPT-TRACKING-FLOW-RAPORU-20260721.md

## Worktree/staging/commit
- Worktree was dirty before this phase with many unrelated modified and untracked files.
- No staging was performed.
- No commit was created.
- No schema, migration, global CSS, production workflow write semantics, quantity semantics, public token security, pricing/current-account code, M13-E2, staging or commit action was started.