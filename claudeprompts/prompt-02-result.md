# Prompt 02 result: parse-item defer after integration

**Summary:** the legacy single-`latest` cutover risk is **real** and was reproduced on both rebuilt integration branches. A small corrective commit on `feature/parse-item-defer` fixes it. It is **not** merged into the integration branches.

## 1. Rebuilt branch tips (unchanged by this task)

| Branch | Tip | Contains `448a1b4` |
|---|---|---|
| `integration/tiedepalsta-rebuilt` | `2b205b8` (merge of `feature/parse-item-defer`) | yes |
| `integration/tiedepalsta-local-rebuilt` | `5e378e6` (merge of `integration/tiedepalsta-rebuilt`) | yes |
| `feature/parse-item-defer` | `448a1b4` + the corrective commit (§8) | — |

## 2. Full PHPUnit on the integration branches

PHP 8.4.26 and PHPUnit 9.6.37, with the existing scratch harness. Each branch was run in a scratch `git archive` export of its tip.

| Branch | Full suite as merged | Standalone `defer_test.php` |
|---|---|---|
| `integration/tiedepalsta-rebuilt` | **OK, 247 tests, 1781 assertions** | OK, 7 / 47 |
| `integration/tiedepalsta-local-rebuilt` | **OK, 251 tests, 1812 assertions** | OK, 7 / 47 |

With the corrective patch applied in those exports, plus the updated `defer_test.php` and the integration-level tests (§3):

| Branch | Result |
|---|---|
| `integration/tiedepalsta-rebuilt` | **OK, 257 tests, 1847 assertions** |
| `integration/tiedepalsta-local-rebuilt` | **OK, 261 tests, 1878 assertions** |

## 3. Integration-level defer tests

The file `defer_tracking_test.php` (7 tests) uses the existing tracking fixture: an in-memory forum and tracker, with `fetch_all()` running. Its driver subclass calls the **real** `driver::parse_feed()` on raw RSS, so a `parse_item_append` listener defers items exactly as in production.

| Test | Integration tree as merged | With the fix |
|---|---|---|
| deferred new item: neither posted nor mapped, retried next run | pass | pass |
| deferred tracked item: untouched until offered again | pass | pass |
| ambiguous identity control (two items, one guid) | pass | pass |
| identity becomes unambiguous when its other item is deferred | pass | pass |
| legacy upgrade, deferred **marker**: no history repost | **FAIL** | pass |
| legacy upgrade, deferred **older** item: not reposted later | **FAIL** | pass |
| legacy upgrade: a deferred **new** item stays retryable | pass | pass |

**Where the file lives:** the tracking fixture doesn't exist at `cb7774c`, so this file cannot live on `feature/parse-item-defer`. It is committed as `claudeprompts/prompt-02-defer_tracking_test.php`; PHPUnit doesn't scan that directory. **Move it to `tests/defer_tracking_test.php` when the fix is merged into the integration branches.** Before that merge, two of its tests fail on purpose.

**New untracked item** (deferred `b` among `a`, `b`, `c`):
- no topic for `b`;
- no `feedpostbot_items` row for `b`;
- `b` not in `handled`;
- `latest` = `a`;
- `c` and `a` posted and mapped normally.

Next run without deferral: `b` is posted and mapped (3 rows), and `b` is in `handled`.

## 4. Tracked-item defer semantics

Both `a` and `b` are tracked, with `update_existing` and `update_reply` ON. In the next run both items changed and `a` is deferred:
- **only `b` is edited.**
- For `a`, these are all unchanged: `source_hash`, `content_hash`, `notified_hash`, `pending_op` (none), `item_state`, `reply_retry_count` and `reply_next_retry`. So there is no update, no reply-debt change and no pending operation.
- Other tracked writes still happen.

When `a` is offered again, its change is applied (`a` is edited), and still no new topic is created.

Reason: a deferred item is absent from `fetch_items()` input, so `resolve_pending()`, `handle_tracked_item()` and `create_tracked()` never see it.

## 5. Ambiguous / shared identity

**Control:** two current items with the same guid `dup` and different links form an ambiguous identity, so 1 topic is posted (the second has the same handled key) and **no** mapping is created.

**With deferral:** deferring one of the two (by link) leaves the other **unambiguous**, so it is **mapped** (`item_key` = `sha256('guid:dup')`).

The identity counts are built from `fetch_items()` input only, so the deferred item behaves exactly as if absent from that run.

## 6. Legacy single-`latest` regression: exact result

**Setup:** the source has no `handled` history and `latest` = `marker`. The feed is, newest first: `new2`, `new1`, `marker`, `old1`, `old2`.

**On the current integration tree:**
- **Defer `marker`:** run 1 posts `old2`, `old1`, `new1`, `new2`. The historical `old1` and `old2` are **reposted**, and `marker` would be reposted on run 2.
- **Defer `old1`:** run 1 seeds only `marker` and `old2` as handled, so run 2 **reposts `old1`**.
- **Defer `new1`:** works correctly; it is posted on run 2.

**Why:** `fetch_items()` seeds the history once, in the first run without `handled`. It walks the item list until `is_handled()` matches the legacy marker, then marks that item and everything older. Deferred items are missing from that list, so:
- a deferred marker means the boundary is never found, nothing is seeded, and all history is treated as new;
- a deferred older item is never seeded, and the bootstrap never runs again because `handled` now exists.

## 7. Is the cutover risk real?

**Yes.** On the real cutover, a board upgraded from the 1.0.x line has only `latest`. Any listener that defers an item at or below the marker during the **first** upgraded run causes reposts:
- if it defers the marker, the whole visible history is reposted at once;
- if it defers an older item, that item is reposted one run later.

The fix does not depend on whether a fetch fails.

## 8. Fix (owner: `feature/parse-item-defer`)

**Owner:** the legacy-marker bootstrap already exists at `cb7774c`, and the defect only appears together with `defer`. So the fix belongs to the defer feature: a follow-up commit on **`feature/parse-item-defer`**, not squashed, not merged anywhere.

- **Commit:** "Adopt the former latest marker across deferred items" (SHA in the console report; `git log -1 feature/parse-item-defer`).
- **Change** (`classes/driver.php`, +13/−3):
  - `parse_feed()` records every parsed item in feed order, **deferred ones included**, in `protected $feed_items`.
  - `fetch_all()` resets it, calls `parse_feed()`, and passes it explicitly: `fetch_items($items, $id, $this->feed_items)`.
  - `fetch_items($items, $source_id, $feed_items = null)` uses `$feed_items` **only for the one-time legacy-marker bootstrap**, falling back to `$items`.
- **Semantics:**
  - Items at or below the legacy marker are seeded as handled exactly as if nothing had been deferred: that is the normal seeding, and those items were posted by the old version.
  - Deferred items newer than the marker are **not** seeded and stay retryable.
  - Outside the bootstrap, nothing changes. Deferred items are still never marked handled, and the processing loop, tracking, identity counts and history pruning still use `$items` only.
  - If every item is deferred, `fetch_items()` returns before the bootstrap, so adoption simply happens on a later run.
  - Callers that don't pass `$feed_items` (including subclasses overriding `parse_feed()`) behave exactly as before.
- **Regression tests** added to `tests/defer_test.php`, going through the real `fetch_all()`:
  - `test_legacy_marker_is_adopted_when_the_marker_item_is_deferred`
  - `test_legacy_marker_adoption_covers_deferred_older_items`
  - `test_legacy_marker_adoption_keeps_deferred_new_items_retryable`

  The fixture gained `fetch_all()` support: persisted state, a lock stub and a per-run destination lookup. Against the pre-fix `448a1b4` driver, the first two fail and the third passes (it guards behaviour that already worked).
- **Validation:**
  - focused `defer_test.php`: OK, 10 tests, 70 assertions;
  - full suite on the feature branch: **OK, 69 tests, 347 assertions** (was 66/324);
  - lint OK; `git diff --check` clean; phpcs on `driver.php` unchanged (same 4 pre-existing findings);
  - `git status` clean after the commit.
- **Merge check:** the patch applies cleanly to both integration trees (§2 counts), but nothing was merged.

**Known remaining limitation (not changed):** history pruning still uses `$items`. An already-handled item that is deferred is treated as "absent" for that run. It stays in the bounded absent history and is not reposted, unless more than `HISTORY_LIMIT` (10 000) absent entries accumulate.

## 9. ACP run-report recommendation

**Keep deferral silent for now; no generic counter.**
- The ACP "Run all feeds" message reports created and updated counts (and "locked"). A bare "N deferred" number would carry no reason and no item identity, so an admin could not act on it.
- Deferral is a listener decision. The extension that defers knows why (for example a transient enrichment failure) and can log that itself with useful context, rate-limited as it sees fit.
- A deferred item never loses data. It is retried each run, or drops out of the feed naturally.

Revisit only if several independent extensions start deferring and need a common signal.

## 10. Lint, diff-check and status

- **Integration branches:** `git diff --check cb7774c..<branch>` is clean for both. PHP lint passes for all PHP files changed since `cb7774c` (29 files on `tiedepalsta-rebuilt`, 31 on `-local-rebuilt`).
- **Feature branch:** lint, diff-check and phpcs as in §8.
- **Working tree:** clean after the commit except for nothing untracked; it is left on `feature/parse-item-defer` (see §11).

## 11. Confirmation

- No deployment, database, phpBB runtime configuration or deployed `ext/ger/feedpostbot` was touched.
- **Integration branches untouched**: still `2b205b8` and `5e378e6`. All integration runs used scratch exports.
- Nothing pushed, nothing squashed.
- The working tree was switched from the clean `integration/tiedepalsta-local-rebuilt` to `feature/parse-item-defer` to make the corrective commit, and is left there.

**Next step:** merge `feature/parse-item-defer` into `integration/tiedepalsta-rebuilt` and then `-local-rebuilt`, and move `claudeprompts/prompt-02-defer_tracking_test.php` to `tests/defer_tracking_test.php` in that merge.
