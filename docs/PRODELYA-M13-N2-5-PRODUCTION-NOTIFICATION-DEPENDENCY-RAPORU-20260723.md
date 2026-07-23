# PRODELYA M13-N2.5 Production Notification Dependency Report - 2026-07-23

Status: BLOCKED - PRODUCTION NOTIFICATION DEPENDENCY ISOLATED, V5 NOT CHECKPOINT APPROVED

## Main Repo Safety

- Main repo: `C:\laragon\www\prodelya_core`
- Main HEAD: `c7f2a80`
- Main staged area: empty at start and before report creation
- Main staging/commit/tag/reset/restore/stash/clean: not used
- Dirty worktree: preserved
- Dirty worktree blanket copy / robocopy: not used

## V4 Blocker

V4 exact failure:

```text
php artisan test --filter="ProductionNotificationIntegrationTest::test_production_notifications_emit_safely_and_do_not_break_workflow" --stop-on-failure
FAIL, 1 test, 6 assertions
Call to a member function all() on array
```

Dirty main comparison:

```text
php artisan test --filter="ProductionNotificationIntegrationTest::test_production_notifications_emit_safely_and_do_not_break_workflow" --stop-on-failure
PASS, 1 test, 121 assertions
```

## Stack Trace

The full captured trace proves the `.all()` call is not in `ProductionWorkflowService` or `NotificationEventService`. It is Laravel's test response assertion context injection after a redirect assertion fails.

```text
TRACE_START
C:\laragon\www\prodelya_core\vendor\laravel\framework\src\Illuminate\Testing\TestResponseAssert.php:81
#0 C:\laragon\www\prodelya_core\vendor\laravel\framework\src\Illuminate\Testing\TestResponseAssert.php(47): Illuminate\Testing\TestResponseAssert->injectResponseContext(Object(PHPUnit\Framework\ExpectationFailedException))
#1 C:\laragon\www\prodelya_core\vendor\laravel\framework\src\Illuminate\Testing\TestResponse.php(433): Illuminate\Testing\TestResponseAssert->__call('assertEquals', Array)
#2 C:\laragon\www\prodelya_core\vendor\laravel\framework\src\Illuminate\Testing\TestResponse.php(207): Illuminate\Testing\TestResponse->assertLocation('http://localhos..')
#3 C:\laragon\www\_prodelya_checkpoints\pre_m14_exact_snapshot_v4_20260723\tests\Feature\ProductionNotificationIntegrationTest.php(70): Illuminate\Testing\TestResponse->assertRedirect('http://localhos..')
#4 C:\laragon\www\prodelya_core\vendor\phpunit\phpunit\src\Framework\TestCase.php(1314): Tests\Feature\ProductionNotificationIntegrationTest->test_production_notifications_emit_safely_and_do_not_break_workflow()
#5 C:\laragon\www\prodelya_core\vendor\phpunit\phpunit\src\Framework\TestCase.php(1351): PHPUnit\Framework\TestCase->invokeTestMethod('test_production..', Array)
#6 C:\laragon\www\prodelya_core\vendor\phpunit\phpunit\src\Framework\TestCase.php(521): PHPUnit\Framework\TestCase->runTest()
#7 C:\laragon\www\prodelya_core\vendor\phpunit\phpunit\src\Framework\TestRunner\TestRunner.php(99): PHPUnit\Framework\TestCase->runBare()
#8 C:\laragon\www\prodelya_core\vendor\phpunit\phpunit\src\Framework\TestCase.php(361): PHPUnit\Framework\TestRunner->run(Object(Tests\Feature\ProductionNotificationIntegrationTest))
#9 C:\laragon\www\prodelya_core\vendor\phpunit\phpunit\src\Framework\TestSuite.php(374): PHPUnit\Framework\TestCase->run()
#10 C:\laragon\www\prodelya_core\vendor\phpunit\phpunit\src\Framework\TestSuite.php(374): PHPUnit\Framework\TestSuite->run()
#11 C:\laragon\www\prodelya_core\vendor\phpunit\phpunit\src\Framework\TestSuite.php(374): PHPUnit\Framework\TestSuite->run()
#12 C:\laragon\www\prodelya_core\vendor\phpunit\phpunit\src\TextUI\TestRunner.php(64): PHPUnit\Framework\TestSuite->run()
#13 C:\laragon\www\prodelya_core\vendor\phpunit\phpunit\src\TextUI\Application.php(229): PHPUnit\TextUI\TestRunner->run(...)
#14 C:\laragon\www\prodelya_core\vendor\phpunit\phpunit\phpunit(104): PHPUnit\TextUI\Application->run(Array)
#15 {main}
TRACE_END
```

Exact failed assertion context:

- V4 test expected redirect to `admin.productions.show`.
- V4 selective application already carries the canonical production return-route behavior from `ProductionController`: no explicit `return_to` returns to the canonical production operation route.
- For internal production, that canonical route is `admin.productions.operator`.
- Laravel's assertion helper then hit its own array/Collection bug while enriching the failed redirect assertion.

## Array / Collection Boundary Decision

Decision: Scenario A, array is canonical at the notification service boundary.

Evidence:

- `NotificationEventService::dispatchEvent(TenantAccount $tenant, string $eventKey, mixed $source = null, array $options = []): array` already exposes an array options boundary.
- Production dispatch passes an array options payload with array `context`.
- `NotificationEventService` normalizes context internally through `sanitizeContext((array) ($options['context'] ?? []), ...)`.
- The failing `.all()` stack is from `Illuminate\Testing\TestResponseAssert`, not from production notification context, recipient resolver, template variables, or log payload creation.

No fixture was randomly wrapped in a Collection. No `ProductionWorkflowService` or `NotificationEventService` hunk was required for the exact dependency.

## Production Notification Truth

Events asserted by the V5 exact test:

- `production_started`: production team audience, `internal` sent and `email` preview logs, production user recipient
- `production_partially_completed`: production team audience, fallback/admin recipient after production role removal
- `production_problem_reported`: production team audience, safe logs
- `production_completed`: production team audience, safe logs

Workflow safety preserved:

- Start, partial, issue, and completed transitions still mutate production state before notification assertions.
- Quantity truth remains asserted through `25` completed and `75` remaining message payload checks.
- Notification failure is mocked and workflow still reaches `STATUS_INTERNAL`.
- Sensitive-data guards remain active for cost, sales price, margin-like fields, `group_code`, file paths, raw payload keys, storage paths, and VAT copy.

## Dirty Main Diff Attribution

Included:

- `tests/Feature/ProductionNotificationIntegrationTest.php`
  - add explicit internal assignment before canonical start
  - update internal start/partial/issue redirect assertions to `admin.productions.operator`
  - add assignment before the notification-failure safety start

Excluded:

- `app/Services/ProductionWorkflowService.php`
  - broader production route/assignment semantics, operator/company preconditions, route-change behavior, and subcontractor assignment behavior
- `app/Services/Notifications/NotificationEventService.php`
  - broader channel normalization

Reason: neither service hunk is needed to close the exact V4 production notification failure or to make the `Notification` targeted gate pass in V5.

## V5 Patch Artifact

Patch directory: `.tmp/pre-m14-selective-patches-v5`

- `production-notification-test-contract.patch`; SHA256 `CDDF8EC4FFE9982F1FF73A1E8F46FB777F97F010AA656D989AD7136F1DA77F30`

## V5 Snapshot

- Path: `C:\laragon\www\_prodelya_checkpoints\pre_m14_exact_snapshot_v5_20260723`
- Base: clean clone from `c7f2a80`
- Prior source: V4 exact selective snapshot copied path-by-path, plus N2.4 report
- New N2.5 dependency: one narrow production notification test contract patch
- Changed tracked files: 45
- Untracked manifest files: 27
- Total changed/untracked: 72
- `git diff --check`: PASS

## Targeted Gates

Passed:

```text
php artisan test --filter="ProductionNotificationIntegrationTest::test_production_notifications_emit_safely_and_do_not_break_workflow" --stop-on-failure
PASS, 1 test, 121 assertions

php artisan view:clear
PASS

php artisan view:cache
PASS

php artisan test --filter=ProductionNotificationIntegrationTest --stop-on-failure
PASS, 1 test, 121 assertions

php artisan test --filter=Notification --stop-on-failure
PASS, 53 tests, 1289 assertions
```

Failed next:

```text
php artisan test --filter=Production --stop-on-failure
FAIL, Tests\Feature\CompanyRoleRemovalSyncTest::test_print_fason_role_removal_hides_company_from_production_assignment_list
Expected response status code [200] but received 302.
```

Dirty main comparison:

```text
php artisan test --filter="CompanyRoleRemovalSyncTest::test_print_fason_role_removal_hides_company_from_production_assignment_list" --stop-on-failure
PASS, 1 test, 7 assertions
```

Attribution: this is the next Production route/surface dependency. Dirty main changes `CompanyRoleRemovalSyncTest` to use `/admin/productions/{id}/subcontract-assignment` instead of legacy `?tab=islemler`, plus fixture production-route setup. It is outside the N2.5 production notification dependency.

## Full Suite

Not run.

Reason: the targeted `Production` gate failed before full-suite eligibility. Therefore `2213/2213` was not proven on exact selective snapshot V5.

## Approval Decision

Checkpoint execution is not approved.

Reason:

- Production notification dependency is isolated.
- `Notification` targeted gate passes.
- `git diff --check` passes.
- The broader targeted matrix is blocked by a separate Production route/surface dependency.
- Full suite was not run and `2213/2213` was not proven.
