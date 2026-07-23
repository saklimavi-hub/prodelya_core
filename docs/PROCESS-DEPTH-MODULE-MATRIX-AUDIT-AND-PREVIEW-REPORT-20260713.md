# Process Depth Module Matrix Audit and Preview Report

Date: 2026-07-13
Phase: `PRODELYA_V1_10.16.5-E0`
Scope: Read-only audit, module matrix decisions, HTML previews
Production code changes: None
Staging: None
Commit: None

## A) Preflight

- `git status --short`: worktree dirty, unrelated changes preserved
- `git diff --stat`: unrelated diff present; Process Depth files not in diff
- `git diff --cached --stat`: empty
- `git log -12 --oneline`: required commits verified
- `php artisan migrate:status`: Process Depth migration present as `2026_07_12_140000_add_process_depth_to_packages_table`
- `git diff --name-only -- ...ProcessDepth... ...orders/show.blade.php ...tests/Feature/ProcessDepth`: empty

Verified checkpoints:

- Feature commit: `97ec0d5`
- Docs commit: `bbd3354`
- Staged area: empty
- Process Depth core/settings/order detail area: clean

## B) Real Architecture Read Set

### Order detail Process Depth anchor

- `app/Services/OrderDetailProcessDepthPresenter.php`
- `resources/views/admin/orders/show.blade.php`
- `tests/Feature/ProcessDepth/OrderDetailProcessDepthPilotTest.php`
- `tests/Feature/ProcessDepth/OrderDetailApprovedStickyPanelTest.php`
- `tests/Feature/OrderDetailOperationalFlowUxTest.php`
- `tests/Feature/OrderDetailSpacingStandardTest.php`

Observed reality:

- Canonical focus is centralized in `OrderDetailProcessDepthPresenter`.
- Current focus keys are presentation-oriented: `graphic_pending`, `procurement_pending`, `production_pending`, `delivery_pending`, `payment_pending`.
- The presenter does not mutate workflow, create records, or bypass permissions.
- `resources/views/admin/orders/show.blade.php` already consumes depth presentation flags such as compact/standard/detailed density, evidence visibility, QC visibility, and advanced activity visibility.
- This makes module rollout naturally presentation-first.

### Graphic module

Models / services / controllers / views / tests reviewed:

- `app/Models/OrderItemPrintGraphic.php`
- `app/Services/OrderItemPrintGraphicWorkflowService.php`
- `app/Services/GraphicWorkflowService.php`
- `app/Services/GraphicModuleDataBuilder.php`
- `app/Services/GraphicApprovalRequestService.php`
- `app/Http/Controllers/Admin/GraphicController.php`
- `app/Http/Controllers/Admin/GraphicCustomerApprovalController.php`
- `app/Http/Controllers/PublicGraphicApprovalController.php`
- `resources/views/admin/graphics/show.blade.php`
- `tests/Feature/GraphicModuleTest.php`
- `tests/Feature/GraphicPerPrintUiTest.php`
- `tests/Feature/GraphicShowHistoryTurkishTest.php`
- `tests/Feature/PublicGraphicApprovalRouteTest.php`
- `tests/Feature/PublicGraphicApprovalSecurityTest.php`

Observed reality:

- Per-print graphic architecture already exists.
- `GraphicController@show` renders real operation data through `GraphicModuleDataBuilder::buildShow`.
- The builder already knows operation cards, customer approval card, workflow history, visibility, preview URLs, work folder, readiness, and active step tabs.
- Public approval already exists and must stay optional, not auto-enforced by Process Depth.
- This module is the strongest match for the current order-detail focus model because the canonical blocker already prioritizes graphic pending.

### Procurement module

Models / services / controllers / views / tests reviewed:

- `app/Models/OrderItemProcurement.php`
- `app/Models/SupplierProcurementRequest.php`
- `app/Services/ProcurementWorkflowService.php`
- `app/Services/ProcurementDataBuilder.php`
- `app/Services/SupplierProcurementRequestService.php`
- `app/Services/SupplierProcurementRequestDataBuilder.php`
- `app/Http/Controllers/Admin/ProcurementController.php`
- `app/Http/Controllers/Admin/SupplierProcurementRequestController.php`
- `resources/views/admin/procurements/show.blade.php`
- `tests/Feature/ProcurementCoreTest.php`
- `tests/Feature/ProcurementUiTest.php`
- `tests/Feature/ProcurementDetailSimplificationTest.php`
- `tests/Feature/ProcurementShowSupplierCariTabTest.php`
- `tests/Feature/SupplierProcurementRequestUiTest.php`

Observed reality:

- Procurement already supports partial receipt, remaining quantity, supplier grouping and supplier request flow.
- The detail page is tab-oriented and already separates summary, supplier/cari, request/form, actions, incoming quantity, history.
- `ProcurementDataBuilder` actively sanitizes forbidden price/cost fragments for public-ish snapshots.
- Sensitive areas are real: purchase price visibility, supplier cari integration, supplier request grouping.
- This module is rollout-ready for presentation, but not as low-risk as graphics.

### Production / subcontracting module

Models / services / controllers / views / tests reviewed:

- `app/Models/OrderItemPrintProduction.php`
- `app/Services/ProductionWorkflowService.php`
- `app/Services/ProductionDataBuilder.php`
- `app/Services/ProductionReadinessResolver.php`
- `app/Services/SubcontractorProductionCurrentAccountSyncService.php`
- `app/Http/Controllers/Admin/ProductionController.php`
- `resources/views/admin/productions/show.blade.php`
- `resources/views/admin/productions/partials/_production_summary.blade.php`
- `resources/views/admin/productions/partials/_production_internal.blade.php`
- `resources/views/admin/productions/partials/_production_external.blade.php`
- `resources/views/admin/productions/partials/_production_actions.blade.php`
- `resources/views/admin/productions/partials/_production_history.blade.php`
- `tests/Feature/ProductionCoreTest.php`
- `tests/Feature/ProductionShowTabbedLayoutTest.php`
- `tests/Feature/ProductionReadinessPerPrintGraphicTest.php`
- `tests/Feature/ProductionFinancePermissionTest.php`
- `tests/Feature/SubcontractorProductionCurrentAccountSyncTest.php`

Observed reality:

- Production already has separate internal/external tabs, readiness logic, assignment flow, QC-related UI, and photo/history surfaces.
- `ProductionDataBuilder` carries readiness warnings, graphic/procurement readiness labels, setup/kliche/QC language, blockers, status tone, and public-status mapping.
- Controlled-mode detail can be rich without inventing data because the backend is already structured.
- Main risk is accidental coupling of readiness, QC, subcontractor finance, and cost visibility into false workflow gates.

### Delivery module

Models / services / controllers / views / tests reviewed:

- `app/Models/OrderItemWorkFormDelivery.php`
- `app/Models/OrderDeliveryPackage.php`
- `app/Models/OrderDeliveryPackageItem.php`
- `app/Models/OrderDeliveryLabelBatch.php`
- `app/Services/DeliveryWorkflowService.php`
- `app/Services/DeliveryDataBuilder.php`
- `app/Services/DeliveryCreationService.php`
- `app/Services/OrderDeliveryPlanningService.php`
- `app/Http/Controllers/Admin/DeliveryController.php`
- `resources/views/admin/deliveries/show.blade.php`
- `tests/Feature/DeliveryCoreTest.php`
- `tests/Feature/DeliveryUiTest.php`
- `tests/Feature/DeliveryPackageKoliV1Test.php`
- `tests/Feature/OrderDeliveryTabWorkflowTest.php`
- `tests/Feature/OrderShowDeliveryTabHttpSmokeTest.php`

Observed reality:

- Delivery already supports partial/full delivery, remaining quantity, recipient, delivery method, package/koli information, evidence upload, and customer-visible vs internal activity visibility.
- `DeliveryDataBuilder` already normalizes readiness warnings and sensitive fragments.
- The module has strong detail depth, but public/customer-visible boundaries are more delicate than the other three modules.

## C) Shared Spacing Rollout Decision

Canonical preview spacing used in all four previews:

- Top-level gap: `14px`
- Section/sticky gap: `12px`
- Nested card gap: `10px`
- Inline action gap: `8px`
- Tight helper gap: `6px`

Canonical preview primitives used:

- `pd-page-stack`
- `pd-section-stack`
- `pd-card-stack`
- `pd-two-column-layout`
- `pd-inline-stack`
- `pd-tight-stack`

Decision:

- Shared spacing standard is suitable for all four module previews.
- No global `.card` margin hack is needed.
- Sticky is justified for module summary panels, not as a mandatory layout rule for every block.

## D) Presentation-Only vs Future Enforcement

### Presentation-only in this rollout family

- Module card density
- Detail density
- Activity list depth
- Evidence section visibility
- QC detail visibility
- Customer approval visibility
- Warning copy length
- Which secondary links are foregrounded
- Sticky sidebar composition

### Enforcement candidates for later audit only

- Customer approval as a hard gate
- Production readiness as a hard gate
- Procurement completeness as a hard gate
- Delivery evidence requirement
- QC completion requirement
- Snapshot hardening and mutation guards

Decision:

- Enforcement stays out of E0 and should remain out of E1/F1/G1/H1.
- `I0 — Snapshot + enforcement audit` remains the right later phase.

## E) Module / Feature / Permission Boundaries

- Process Depth must not unlock unavailable modules.
- Process Depth must not bypass tenant/package/permission limits.
- Financial visibility remains permission-bound in procurement, production and delivery.
- Public/customer-visible document boundaries remain unchanged.
- No fake workflow records, no synthetic operation creation, no synthetic package/label capability.

## F) Sensitive Data Boundaries

### Must stay protected

- Purchase prices and supplier cost detail
- Production cost / subcontractor current account detail
- Internal-only attachments and notes
- File system path / raw storage path values
- Product Data Hub raw fields
- Customer portal-inappropriate internal workflow detail

### Preview-safe but controlled

- Operational status labels
- Remaining quantity
- Readiness blockers
- Customer-visible approval history
- Customer-visible delivery evidence summary

## G) Matrix Decision Table

| Alan | Hızlı | Standart | Kontrollü | Enforcement bu fazda? |
|---|---|---|---|---|
| Grafik operasyon kartları | Kompakt | Ayrı operasyonlar | Tam ayrıntı | Hayır |
| Müşteri grafik onayı | Gizli/isteğe bağlı | Görünür | Ayrıntılı | Hayır |
| Tedarik kısmi geliş | Kısa durum | Tam işlem | Ayrıntılı geçmiş | Hayır |
| Üretim readiness | Kısa | Temel | Ayrıntılı | Hayır |
| Üretim QC | Gizli | İsteğe bağlı | Ayrıntılı | Hayır |
| Teslimat evidence | Gizli | İsteğe bağlı | Ayrıntılı | Hayır |
| Activity history | Son hareket | Kısa liste | Tam liste | Hayır |
| Finans/maliyet | Permission | Permission | Permission | Hayır |

## H) Module Matrices

### 1. Graphic matrix

Fast:

- Single compact operation focus
- Reference or latest visual
- Short status
- One primary CTA
- Last move only
- Long timeline, approval history and full attachment surface collapsed

Standard:

- `1a / 1b / 1c / 1d` operation separation
- Visibility and customer approval state visible
- Revision / ready-to-production actions visible
- Short history
- Work form and folder links visible

Controlled:

- Full operation detail per print
- Full file and approval context
- Visibility split
- Production readiness details
- Evidence and activity depth
- Optional checklist area only if real backend data exists

Decision: `GO`

Reason:

- Real per-print architecture is already present.
- Canonical order focus already prioritizes graphics.
- Presentation-only integration can reuse existing builder/controller/view boundaries with low workflow risk.

### 2. Procurement matrix

Fast:

- Product, requested, received, remaining, short ETA
- One primary CTA
- Long request history and price detail suppressed

Standard:

- Supplier grouping
- Partial receipt flow
- Price-less supplier request form
- Expected date
- Short activity history
- Purchase price only for authorized users

Controlled:

- Full request lines
- Requested / ordered / received comparison
- Partial receipt timeline
- Delay reasons
- Documents / notes / attachments
- Supplier current account effect only for authorized users

Decision: `CONDITIONAL GO`

Conditions:

- Keep price visibility permission-bound.
- Do not present supplier stock snapshot as local reserved stock.
- Do not imply automatic reservation or accounting mutation.

### 3. Production / subcontracting matrix

Fast:

- Production type
- Planned / completed / remaining
- One primary CTA
- Basic start / partial / completed status framing

Standard:

- Internal vs subcontracting split
- Operator or supplier assignment
- Basic readiness
- Optional photos
- Basic QC
- Short history

Controlled:

- Graphic readiness
- Procurement readiness
- Setup / kliche / preparation detail
- QC detail
- Evidence / photo history
- Subcontracting send/return context
- Detailed activity and finance-sensitive areas only by permission

Decision: `CONDITIONAL GO`

Conditions:

- QC must remain informational.
- Evidence upload must remain optional.
- Cost and subcontractor finance must stay permission-bound.

### 4. Delivery matrix

Fast:

- Delivery-ready / delivered
- Recipient
- Delivery date
- One primary CTA

Standard:

- Partial delivery
- Delivered / remaining
- Delivery method
- Recipient
- Optional photo/document
- Short customer notification context

Controlled:

- Multi-delivery detail
- Delivery rows
- Item distribution
- Evidence and customer-visible split
- Recipient/time audit
- Detailed history
- Package summary if real package engine data exists

Decision: `CONDITIONAL GO`

Conditions:

- No fake package/label feature.
- No mandatory evidence gate.
- No sensitive internal detail leak into customer-visible surfaces.

## I) Preview Paths

- `docs/ui-previews/prodelya_process_depth_grafik_matrix_onizleme.html`
- `docs/ui-previews/prodelya_process_depth_tedarik_matrix_onizleme.html`
- `docs/ui-previews/prodelya_process_depth_uretim_fason_matrix_onizleme.html`
- `docs/ui-previews/prodelya_process_depth_teslimat_matrix_onizleme.html`

## J) Real Implementation Order

Validated order:

1. `E1 — Grafik UI`
2. `F1 — Tedarik UI`
3. `G1 — Üretim/Fason UI`
4. `H1 — Teslimat UI`
5. `I0 — Snapshot + enforcement audit`

Why this order holds:

- Graphics already owns the strongest canonical blocker in order detail.
- The data model is per-operation and already well-separated.
- Public approval already exists, so visibility nuance is real without requiring workflow mutation.
- Procurement, production and delivery each carry heavier permission/sensitive-data coupling.

## K) First Real Implementation Prompt Scope

Recommended next real phase:

- `PRODELYA_V1 10.16.5-E1 — Grafik Operasyonu Süreç Derinliği UI Entegrasyonu`

Recommended E1 scope:

- Reuse existing `GraphicController` and `GraphicModuleDataBuilder`
- Apply Process Depth presentation only inside graphic UI surfaces
- Keep one primary CTA discipline per depth mode
- Keep `1a / 1b / 1c / 1d` real operation model intact
- Do not add workflow enforcement
- Do not add snapshot mutation
- Do not duplicate the module with a second fake screen

Likely E1 touch points later:

- `resources/views/admin/graphics/show.blade.php`
- `resources/views/admin/graphics/index.blade.php`
- Possibly presentation-only mapping in `app/Services/GraphicModuleDataBuilder.php`
- Targeted graphic UI tests only

## L) Console Summary

- A) Preflight: PASS
- B) Graphic architecture readiness: PASS
- C) Procurement architecture readiness: PASS
- D) Production architecture readiness: PASS
- E) Delivery architecture readiness: PASS
- F) Graphic matrix: GO
- G) Procurement matrix: CONDITIONAL GO
- H) Production matrix: CONDITIONAL GO
- I) Delivery matrix: CONDITIONAL GO
- J) Shared spacing used: YES
- K) Workflow enforcement added: NO
- L) Snapshot added: NO
- M) Production code changed: NO
- N) Preview files: 4 created
- O) Report path: `docs/PROCESS-DEPTH-MODULE-MATRIX-AUDIT-AND-PREVIEW-REPORT-20260713.md`
- P) First implementation: `E1 — Grafik UI`
- Q) GO/NO-GO: Graphic UI gate open
- R) Staging: NO
- S) Commit: NO
- T) Final decision: `VERIFIED — PROCESS DEPTH MODULE MATRIX AND PREVIEWS READY — GRAPHIC UI IMPLEMENTATION GATE OPEN`
