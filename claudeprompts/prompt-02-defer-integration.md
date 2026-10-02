# Validate parse-item defer after integration with update-existing tracking

Repository:

`/srv/www/tiedepalsta/develtmp/feedpostbot/upstream-dev`

The standalone feature:

`feature/parse-item-defer`

contains commit:

`448a1b495f942970b59fa4b03b9d1467ea3d8039`

It has now been merged into:

- `integration/tiedepalsta-rebuilt`
- `integration/tiedepalsta-local-rebuilt`

This task is primarily validation and investigation.

Do not deploy or modify the database/runtime.

## 1. Full integration validation

Validate both rebuilt integration branches with the same PHP 8.4 / PHPUnit 9.6.37 scratch harness used previously.

For each branch run:

- full PHPUnit;
- `git diff --check cb7774c..<branch>`;
- PHP lint for all PHP files changed since `cb7774c`;
- `git status --short`.

Confirm that the standalone defer tests still pass after integration.

## 2. Add integration-level defer tests if needed

The standalone upstream branch cannot test update-existing/tracking because that code does not exist at `cb7774c`.

On the integration tree, prove these behaviours:

### New untracked item

When a new item is deferred:

- no topic is created;
- no `phpbb_feedpostbot_items` mapping is created;
- it is not added to handled history;
- `latest` is not advanced to it;
- other items in the feed continue normally;
- next run without deferral processes and maps it normally.

### Already tracked item

When an already mapped item is deferred:

- no update is attempted;
- source/content/notified hashes remain unchanged;
- no reply debt changes;
- no pending operation is created;
- other items continue normally;
- later run without deferral resumes normal processing.

### Shared/ambiguous identity

Confirm a deferred item is not considered when determining same-run ambiguous/shared stable identities.

The defer must behave as if that item were absent from this run.

## 3. Investigate the legacy single-`latest` upgrade edge case

This is important for the real server migration.

Legacy Feed Post Bot state can contain only the old single:

`latest`

marker, with no modern handled history yet.

The standalone defer analysis identified this possible failure:

> If the listener defers exactly the feed item matching the legacy `latest` marker during the first upgraded run, the marker is absent from the parsed item list. The upgrade/adoption logic may then fail to recognize the historical boundary, causing older feed items to be treated as new and reposted.

Build a focused regression test that reproduces the actual integration logic.

Test at least:

1. legacy source state with only `latest`;
2. feed contains:
   - items newer than latest;
   - the exact latest marker item;
   - older historical items;
3. listener defers exactly the legacy latest-marker item.

Determine precisely what happens.

If the suspected repost risk is NOT real, explain why and show the passing test.

If it IS real:

- demonstrate it with a failing test against the current integration tree;
- identify the smallest correct fix;
- do not use operational assumptions such as "the HTTP request probably won't fail";
- preserve the semantics that a deferred genuinely-new item remains retryable;
- preserve normal legacy-upgrade handled seeding;
- do not make deferred items permanently handled merely to solve the upgrade case.

Prefer a generic/integration-safe solution rather than a Tiedepalsta-specific workaround.

## 4. Fix policy

If the legacy-upgrade risk is reproducible and the fix is small, implement it in the most appropriate existing feature branch or as a focused follow-up branch/commit, but:

- explain first in the final report which logical feature owns the fix;
- keep the semantic delta minimal;
- add the regression test;
- do not autosquash history yet;
- do not merge the new corrective commit into integration branches automatically in this task.

If no production fix is needed, make no production changes.

## 5. ACP run result

Check whether deferral currently needs to be visible in the ACP "Run all feeds" result.

Do not implement a counter merely because it is possible.

Answer whether:

- silent deferral is acceptable for the generic feature;
- or a generic deferred-item count materially improves operability.

Remember that dependent extensions may separately log their own transient enrichment failures.

## 6. Validation for any corrective commit

If a corrective commit is created, run:

- focused tests;
- full PHPUnit;
- diff-check;
- lint;
- status.

Report its branch and SHA.

Do not push.

## Final report

Return:

1. rebuilt branch tips;
2. full PHPUnit counts for both branches;
3. new integration-level defer tests and results;
4. tracked-item defer semantics;
5. ambiguous/shared-identity result;
6. exact result of the legacy single-`latest` regression test;
7. whether the cutover risk is real;
8. if real, smallest correct fix and any corrective commit SHA;
9. ACP run-report recommendation;
10. lint/diff-check/status;
11. confirmation that runtime and database were untouched.

Print the report normally and save the same report to:

`/srv/www/tiedepalsta/develtmp/feedpostbot/upstream-dev/claudeprompts/prompt-02-result.md`
