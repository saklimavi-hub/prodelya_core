# PRODELYA M13-N2.6 Production Route/Surface Dependency Report - 2026-07-23

## Result

BLOCKED - EXACT SELECTIVE SNAPSHOT V6 NOT APPROVED.

The production canonical route/surface dependency was isolated with a narrow test/fixture patch. The exact blocker passed in V6, but the required targeted matrix then exposed a separate Graphic Customer Approval UX dependency under the broad `Production` filter. Because the targeted gates did not all pass, the 2213/2213 full suite was not run and checkpoint execution is not approved.

## V5 Blocker

Snapshot:

```text
C:\laragon\www\_prodelya_checkpoints\pre_m14_exact_snapshot_v5_20260723
```

Exact failing test:

```text
CompanyRoleRemovalSyncTest::test_print_fason_role_removal_hides_company_from_production_assignment_list
```

V5 result:

```text
FAIL
expected 200
actual 302
tests/Feature/CompanyRoleRemovalSyncTest.php:212
```

Dirty main result for the same exact test:

```text
PASS
1 test
7 assertions
```

## Route Matrix

| Layer | V5 before patch | Dirty main | V6 after patch |
|---|---|---|---|
| Request URL | `/admin/productions/{id}?tab=islemler` | `/admin/productions/{id}/subcontract-assignment` | `/admin/productions/{id}/subcontract-assignment` |
| Legacy response | 302 redirect | not used by this test | redirect contract preserved |
| Canonical route | `admin.productions.subcontract-assignment` | `admin.productions.subcontract-assignment` | `admin.productions.subcontract-assignment` |
| Production type | implicit fixture state | explicit outsourced | explicit outsourced |
| Production status | implicit fixture state | explicit pending | explicit pending |
| Sent state | implicit fixture state | `sent_to_subcontractor_at = null` | `sent_to_subcontractor_at = null` |
| Company before role removal | blocked before assertion by 302 | visible | visible |
| Company after role removal | blocked before assertion by 302 | not visible | not visible |
| Exact test result | FAIL | PASS | PASS |

## Canonical Production Route Decision

V5 already contains the canonical route behavior in `ProductionController`.

Evidence:

```text
legacy ?tab=islemler / ic-uretim / dis-uretim
-> redirect()->route($this->canonicalProductionRouteName($production), $production)
```

Canonical route resolver evidence:

```text
completed/cancelled/no remaining quantity -> admin.productions.show
internal active -> admin.productions.operator
outsourced/external sent, partial-after-send, or problem state with company -> admin.productions.subcontract-tracking
outsourced/external pending and unsent -> admin.productions.subcontract-assignment
```

Route registration evidence in V5:

```text
GET /admin/productions/{production}/operator -> admin.productions.operator
GET /admin/productions/{production}/subcontract-assignment -> admin.productions.subcontract-assignment
GET /admin/productions/{production}/subcontract-tracking -> admin.productions.subcontract-tracking
GET /admin/productions/{production} -> admin.productions.show
```

Decision:

```text
Outsourced unsent production must use /subcontract-assignment.
Legacy ?tab=islemler remains a redirect contract and must not be restored as a 200 surface.
```

## Company Eligibility Truth

The test contract remains:

```text
same tenant
active company
active print_fason role
canonical subcontract assignment surface renders eligible company before role removal
company remains stored after role removal
company is no longer eligible for new production assignment after role removal
```

No write-side role sync, tenant guard, historical production-company, or current-account semantics were changed.

## V6 Patch Artifact

Created in the clean V6 snapshot:

```text
.tmp/pre-m14-selective-patches-v6/company-role-removal-production-route-contract.patch
```

SHA256:

```text
14732BCEB6B81CC27C3374BE351B26C4500B20FCD1DCB4D21C8164C2CF9D3D72
```

Patch scope:

```text
tests/Feature/CompanyRoleRemovalSyncTest.php
```

Patch hunks:

```text
1. before request: ?tab=islemler -> /subcontract-assignment
2. after request: ?tab=islemler -> /subcontract-assignment
3. fixture: force outsourced, pending, no production_company_id, no sent_to_subcontractor_at
```

No `ProductionController` hunk was added in N2.6.
No `routes/web.php` hunk was added in N2.6.
No tenant/company eligibility guard was weakened.

## Exact Snapshot V6

Path:

```text
C:\laragon\www\_prodelya_checkpoints\pre_m14_exact_snapshot_v6_20260723
```

Base:

```text
c7f2a80
```

Construction:

```text
clean clone from main repository at c7f2a80
V5 snapshot manifest copied path-by-path
N2.5 report copied explicitly
N2.6 patch applied only to CompanyRoleRemovalSyncTest
no whole dirty worktree copy
no robocopy blanket copy
```

Manifest counts:

```text
tracked changed files: 46
untracked files: 28
total manifest entries: 74
```

V5-to-V6 delta:

```text
tests/Feature/CompanyRoleRemovalSyncTest.php
.tmp/pre-m14-selective-patches-v6/company-role-removal-production-route-contract.patch
```

## Diff Hygiene

Command:

```powershell
git -C C:\laragon\www\_prodelya_checkpoints\pre_m14_exact_snapshot_v6_20260723 diff --check
```

Result:

```text
PASS
```

Only CRLF/LF normalization warnings were printed. No whitespace error was reported and exit code was 0.

## Targeted Gates

Exact blocker:

```text
php artisan test --filter="CompanyRoleRemovalSyncTest::test_print_fason_role_removal_hides_company_from_production_assignment_list" --stop-on-failure
PASS
1 test
7 assertions
```

Targeted matrix:

```text
view:clear -> PASS
view:cache -> PASS
CompanyRoleRemovalSyncTest -> PASS, 4 tests, 40 assertions
ProductionCanonicalRouteResolver -> PASS, 1 test, 6 assertions
ProductionLegacyRouteCleanup -> PASS, 6 tests, 43 assertions
ProductionSubcontractorAssignmentFlow -> PASS, 9 tests, 78 assertions
Production -> FAIL
```

Failing targeted gate:

```text
Tests\Feature\GraphicCustomerApprovalUxTest::test_graphic_show_displays_approved_state_without_auto_production_ready_and_list_shows_customer_approval
tests/Feature/GraphicCustomerApprovalUxTest.php:136
missing expected text: Son Müşteri Hareketi
```

Dirty main for the same Graphic UX exact test:

```text
PASS
1 test
10 assertions
```

Attribution:

```text
This is a separate Graphic Customer Approval UX/index contract dependency.
Dirty main changes update the Graphic UX test expectation from the stale "Son Müşteri Hareketi" copy to the canonical grouped graphics index copy:
"Onay var, üretime hazırlık kararı ayrı verilir."
```

No V6 widening was performed for this separate dependency.

## Full Suite

Not run.

Reason:

```text
The required targeted matrix failed before the full-suite gate.
Running or approving the 2213/2213 checkpoint after a targeted failure would violate the V6 stop-line.
```

Checkpoint execution:

```text
NOT APPROVED
```

## Main Repository Safety

Final main repository checks:

```text
HEAD: c7f2a80
branch: feature/master-restructure-phase-2-order-flow
staged area: empty
```

No staging or commit was performed in the main repository.

## Final Execution Manifest Delta

Direct stage:

```text
N1 A manifest from prior approved snapshot
```

Interactive/narrow patches:

```text
N1 B patches from prior approved snapshot
N2.2 local-product route/search/surface patches
N2.3 product-data-hub-shell-test-contract.patch
N2.4 graphic-customer-approval-controller-notification.patch
N2.4 graphic-customer-approval-test-contract.patch
N2.4 pre-m14-diff-hygiene.patch
N2.5 production-notification-test-contract.patch
N2.6 company-role-removal-production-route-contract.patch
```

Whole-file C dependencies:

```text
Six N2 C whole-file dependencies from prior approved manifest
No new whole-file dependency added in N2.6
```

Excluded in N2.6:

```text
ProductionController
routes/web.php
tenant/company eligibility query changes
legacy ?tab=islemler 200 restoration
Graphic UX index/test dependency
```

Pre-commit full suite:

```text
NOT RUN
2213/2213 NOT VERIFIED
```

Commit message reserved for a future approved checkpoint only:

```text
checkpoint: pre-m14 full-suite cleanup
```
