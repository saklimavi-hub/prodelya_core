# PRODELYA M13-N2.4 Graphic Approval Notification Dependency Report - 2026-07-23

Status: BLOCKED - GRAPHIC APPROVAL NOTIFICATION DEPENDENCY ISOLATED, V4 NOT CHECKPOINT APPROVED

## Main Repo Safety

- Main repo: `C:\laragon\www\prodelya_core`
- Main HEAD: `c7f2a80`
- Branch: `feature/master-restructure-phase-2-order-flow`
- Main staged area: empty at start and before report creation
- Main staging/commit/tag/reset/restore/stash/clean: not used
- Dirty worktree: preserved
- Whole dirty worktree copy / robocopy: not used

## V3 Blocker Reproduction

Exact V3 test:

```text
php artisan test --filter="AdminGraphicCustomerApprovalActionTest::test_send_action_creates_request_cancels_previous_open_request_and_emits_notification" --stop-on-failure
FAIL, 1 test, 5 assertions
Session is missing expected key [success].
```

Dirty main comparison:

```text
php artisan test --filter="AdminGraphicCustomerApprovalActionTest::test_send_action_creates_request_cancels_previous_open_request_and_emits_notification" --stop-on-failure
PASS, 1 test, 15 assertions
```

## Route, Controller, Approval, Notification Trace

- Route: `POST /admin/graphics/{graphic}/customer-approval/send`
- Route name: `admin.graphics.customer-approval.send`
- Middleware: `module.enabled:graphic_customer_approval`, `feature.enabled:graphic_customer_approval,public_graphic_approval`
- Controller: `App\Http\Controllers\Admin\GraphicCustomerApprovalController::send`
- Approval creation: `GraphicApprovalRequestService::createRequest`
- Previous open request cancel: `GraphicApprovalRequestService::cancelOpenRequests`
- Notification event: `graphic_customer_approval_requested`
- Related log: `NotificationLog` with `related_type = GraphicApprovalRequest` morph class and `related_id = approval_request.id`
- Redirect target: `admin.graphics.show`

Safety preserved:

- Tenant isolation remains enforced before action.
- Module/feature guards remain unchanged.
- Attachment tenant, order item, print, work form, type, and visibility checks remain in `GraphicApprovalRequestService::assertAttachmentEligible`.
- Existing open approval requests are still cancelled before the new waiting request is created.
- Public token is not rendered in admin show assertions.
- Notification dispatch failure still must not break approval request creation or public tracking.

## Canonical Flash Decision

Decision: Scenario B. `success` is canonical only when the e-mail notification is actually sent. In the test fixture, the configured test channel produces a preview/log outcome, so `warning` is the canonical flash key.

Proof:

- Dirty main controller maps notification-log status to redirect flash:
  - no e-mail log, skipped, failed, pending, preview/log channel => `warning`
  - sent e-mail => `success`
- Dirty main test expects `warning` and still asserts the real workflow contract:
  - redirect target is correct
  - approval request is created
  - selected attachment is captured
  - graphic status becomes customer-approval waiting
  - notification log exists for `graphic_customer_approval_requested`
  - second send cancels the previous open request
  - admin show does not expose the raw public token
- The V4 exact blocker passes with 15 assertions after applying the controller outcome hunk and the one-line test contract update.

The test assertion was not weakened to hide a failed action; the DB request, status transition, notification log, cancel/create flow, and token non-leak assertions all remain active.

## N2.4 Patch Artifacts

Patch directory: `.tmp/pre-m14-selective-patches-v4`

- `graphic-customer-approval-controller-notification.patch` - notification-log outcome to redirect flash mapping; SHA256 `B6819F3309F0594D2C9704DDA65CB7454DF789F8407386B6432022AFC2EBDB3B`
- `graphic-customer-approval-test-contract.patch` - one-line fixture contract update from `success` to `warning`; SHA256 `495F00D241E65D74BDEBAE68CE2B9A4C5E8829A1A7290542B5A3910C687CA80C`
- `pre-m14-diff-hygiene.patch` - semantics-free removal of known EOF blank-line warnings; SHA256 `0268BD68BC1E252EA5BE6B2F9E75B0631C375888378C13A0627BA85F0A1041E6`

No controller whole-file staging, route whole-file staging, or broad graphic UX patch was introduced.

## V4 Snapshot

- Path: `C:\laragon\www\_prodelya_checkpoints\pre_m14_exact_snapshot_v4_20260723`
- Base: clean clone from `c7f2a80`
- Runtime dependency: `vendor` junction and copied `.env`
- Applied source: prior approved A/B/C manifest, N2.2 local-product dependencies, N2.3 Product Data Hub shell dependency, N2.4 graphic approval notification patches, and N2.4 EOF hygiene
- Changed tracked files: 44
- Untracked manifest files: 26
- Total changed/untracked: 70
- Manifest-external changed files: none identified
- `git diff --check`: PASS

## Targeted Gates

Passed:

```text
php artisan test --filter="AdminGraphicCustomerApprovalActionTest::test_send_action_creates_request_cancels_previous_open_request_and_emits_notification" --stop-on-failure
PASS, 1 test, 15 assertions

php artisan view:clear
PASS

php artisan view:cache
PASS

php artisan test --filter=AdminGraphicCustomerApprovalActionTest --stop-on-failure
PASS, 5 tests, 56 assertions
```

Failed next:

```text
php artisan test --filter=Notification --stop-on-failure
FAIL, 53 tests completed before stop, 52 passed, 1174 assertions
Tests\Feature\ProductionNotificationIntegrationTest::test_production_notifications_emit_safely_and_do_not_break_workflow
Call to a member function all() on array
```

Dirty main comparison for the new blocker:

```text
php artisan test --filter="ProductionNotificationIntegrationTest::test_production_notifications_emit_safely_and_do_not_break_workflow" --stop-on-failure
PASS, 1 test, 121 assertions
```

Attribution:

- The new failure is outside the Graphic Customer Approval route/controller/request/notification chain.
- Dirty main contains separate production workflow and production notification test changes, including `ProductionWorkflowService`, `NotificationEventService`, and `ProductionNotificationIntegrationTest`.
- Those files were not added to V4 because they are not proven N2.4 graphic approval notification dependencies.

## Full Suite

Not run.

Reason: the targeted `Notification` gate failed before full-suite eligibility. Therefore the required `2213/2213` proof was not obtained on exact selective snapshot V4.

## Final Execution Manifest Delta

N2.4 adds:

- `.tmp/pre-m14-selective-patches-v4/graphic-customer-approval-controller-notification.patch`
- `.tmp/pre-m14-selective-patches-v4/graphic-customer-approval-test-contract.patch`
- `.tmp/pre-m14-selective-patches-v4/pre-m14-diff-hygiene.patch`

Excluded from V4:

- `app/Services/ProductionWorkflowService.php`
- `app/Services/Notifications/NotificationEventService.php`
- `tests/Feature/ProductionNotificationIntegrationTest.php`

Reason for exclusion: these are the next targeted Notification blocker surface, not part of the isolated Graphic Customer Approval notification dependency.

## Approval Decision

Checkpoint execution is not approved.

Reason:

- Graphic Customer Approval notification dependency is isolated and its targeted tests pass.
- Diff hygiene passes.
- The exact V4 selective snapshot does not pass the full targeted matrix because a separate Production notification dependency now blocks `Notification`.
- Full suite was not run and `2213/2213` was not proven.
