# Prompt 01 result: generic parse-time item deferral

## 1. Branch and commit

- **Branch:** `feature/parse-item-defer`, created directly from upstream `cb7774c` ("Fix HTML escaping compatibility across test suite and phpBB versions").
- **Commit:** one commit, "Allow listeners to defer parsed feed items". This file is part of that commit, so it cannot contain the commit's own SHA. Get it with `git log -1 --format=%H feature/parse-item-defer`; the console report gives it as well.
- Not merged, not pushed.

## 2. Production-code change (`classes/driver.php`, +15/−1)

- New public constant: `driver::SUPPORTS_ITEM_DEFER = true`.
- In `parse_feed()`, for each item:
  1. `$defer = false;` before `ger.feedpostbot.parse_item_append`;
  2. the event's vars become `array('item', 'append', 'defer')`;
  3. after the listener values are applied, `if ($defer) { continue; }`: the item is not added to the returned list, and the backwards-compatible event is skipped for it;
  4. `$vars` is reset to `array('item', 'append')` before the backwards-compatible `parse_{rss|atom|rdf}_append` event, so that event's API is unchanged.
- Event PHPDoc: added `@var bool defer` with the semantics and `@changed 1.2.0 Added defer`. 1.2.0 is the next feature release; `composer.json` stays at 1.1.0.

Nothing else changed: no identity, handled-history, posting, logging, schema or ACP code.

## 3. Semantics of `defer`

- Boolean, default `false`.
- `true` leaves **this item out of the current run only.** `parse_feed()` omits it, so `fetch_items()` never sees it:
  - no `post_message()` call, so no topic or post;
  - not marked handled;
  - `latest` is not advanced.
- Other items of the same feed are processed normally, including newer ones and ones processed after it.
- On the next run the item is parsed again and offered to listeners again. While a listener keeps deferring it, it stays unprocessed. **It is not a permanent skip**; `submit_post_before` `do_post = false` remains the permanent skip.
- Normal phpBB event semantics apply among several `parse_item_append` listeners: the final value after all listeners decides.
- No counters, persistence, queues or logging.

## 4. Interaction with the backwards-compatible parse events

- **Order:** `parse_item_append` fires first, then `parse_{type}_append`.
- **Decision:** `defer` exists **only on `parse_item_append`.** This is the smallest coherent API:
  - A deferred item **does not trigger** the backwards-compatible event. Legacy listeners therefore never see it and **cannot re-enable it**.
  - The backwards-compatible event **does not receive** `defer`, and anything it returns cannot defer an item. Its variable list is unchanged.
- Both properties are tested and were shown to be enforced by mutation runs (§6).

## 5. Capability constant

**Added:** `driver::SUPPORTS_ITEM_DEFER = true`.

Rationale:
- An older Feed Post Bot silently ignores `defer` and processes the item immediately. For a dependent extension that is an unsafe failure.
- The existing version metadata is not a reliable capability signal: `composer.json` says 1.1.0 on this baseline, while unreleased events elsewhere already carry `@since 1.2.0`.
- A class constant can be checked cheaply, e.g. in `ext.php::is_enableable()`, with `defined('\ger\feedpostbot\classes\driver::SUPPORTS_ITEM_DEFER')`. It is documented in the README.

## 6. Tests added (`tests/defer_test.php`, 7 tests)

The tests use the real `parse_feed()` (SimplePie on raw RSS with items a newest, b, c oldest) and the real `fetch_items()`. Only `post_message()` (records guids), `get_simplepie_instance()` (serves the XML) and the dispatcher (scripted listeners) are stubbed. `fetch_items(parse_feed(...))` is exactly what `fetch_all()` does per source.

| Test | Proves |
|---|---|
| `test_support_is_advertised` | the capability constant exists and is `true` |
| `test_deferred_item_is_left_out_of_the_parse_result` | b is absent and a, c are present in order; `parse_item_append` fired for all three; `parse_rss_append` **not** fired for b |
| `test_deferred_item_is_not_handled_and_is_processed_on_the_next_run` | run 1 posts c, a; b is not handled; `latest` = a. Run 2 (no defer) posts only b, and b becomes handled. Run 3 posts nothing. |
| `test_deferring_the_first_processed_item_does_not_stop_newer_items` | deferring the oldest item (processed first) still posts b, a |
| `test_item_stays_deferred_as_long_as_a_listener_defers_it` | repeated deferral never posts or handles the item, and `latest` never points at it |
| `test_listeners_ignoring_defer_keep_existing_behaviour` | `$append` changes from both events still apply; all items are posted as before |
| `test_backwards_compatible_events_neither_receive_nor_apply_defer` | the legacy event's data has no `defer`, and returning `defer => true` from it has no effect |

The listener helper also asserts that `parse_item_append` receives `defer === false` by default.

**Verification that the tests bite:**
- Against unmodified `cb7774c`, the 5 defer tests fail and the 2 compatibility tests pass.
- Mutation "legacy event also receives `defer`": the legacy test fails.
- Mutation "check `defer` after the legacy event": 2 tests fail.

**Tracking not tested:** `cb7774c` contains no update-existing/tracking code, so tracked-item semantics are not tested here (see §11).

## 7. PHPUnit results

PHP 8.4.26, PHPUnit 9.6.37 (the existing scratch harness; bootstrap mirroring `tests/run_isolated.php`).

| Run | Result |
|---|---|
| Focused: `tests/defer_test.php` | **OK, 7 tests, 47 assertions** |
| Full suite on branch | **OK, 66 tests, 324 assertions** |
| Full suite on baseline `cb7774c` (scratch export) | OK, 59 tests, 277 assertions. The difference is exactly the 7 new tests and 47 assertions. |

The one `PHP Notice` (SimplePie "invalid XML") printed during the full suite appears on both baseline and branch. It comes from an existing stress test that feeds invalid XML on purpose.

## 8. Lint, diff-check and status

- `php -l classes/driver.php tests/defer_test.php`: no syntax errors.
- `git diff --check cb7774c`: clean.
- phpcs (repository `phpcs.xml.dist`): `classes/driver.php` has the same 4 findings as on `cb7774c`, so none are new. `tests/` is outside the ruleset's paths; the new test shares the existing tests' file-header and multiple-classes-per-file style.
- `git status --short` before committing: `M README.md`, `M classes/driver.php`, `?? tests/defer_test.php`. `claudeprompts/` is listed as **ignored**: `.git/info/exclude` already contained `/claudeprompts/` before this task. That file was **not** edited; the two prompt files were staged with `git add -f`. Consider removing that line, since the directory is meant to be versioned.

## 9. Changed files

```
README.md                                 |   4 +
classes/driver.php                        |  16 +-
tests/defer_test.php                      | new
claudeprompts/prompt-01-parse-item-defer.md | new
claudeprompts/prompt-01-result.md         | new
```

The README gets a minimal "For Extension Developers" paragraph covering `defer` and the capability check.

## 10. Confirmation

- No database, phpBB runtime configuration, deployed extension (`/srv/www/tiedepalsta/ext/ger/feedpostbot`) or other branch was modified.
- No integration, localization or local branch was touched or merged; nothing was pushed.
- The repository's working tree was switched from `integration/tiedepalsta-local-rebuilt` (clean at the time) to the new branch, and is left on `feature/parse-item-defer`.

## 11. Still to verify after merging into the integration branches

1. **Tracked items:** a deferred item never reaches `create_tracked()` (no provisional row) or `handle_tracked_item()` (no update, no state change). Expected from the code, since the item is absent from `fetch_items()` input, but to be tested with the tracking test fixture.
2. **Ambiguity detection:** a deferred item is not counted when detecting shared identities for that run.
3. **Handled history:** if an already-handled item is deferred, its key is treated as "absent" for that run. It stays in the bounded absent history (`HISTORY_LIMIT`) and is not reposted, but it can be evicted earlier than a present key in feeds with more than `HISTORY_LIMIT` absent entries.
4. **One-time legacy-marker bootstrap** (sources whose state has no `handled` history yet): if a listener defers exactly the item matching the old `latest` marker during that first run, the bootstrap cannot find the marker. Older items would then not be pre-marked as handled. Avoid deferring during the upgrade run, or add a guard in a follow-up. Not changed here per the "no handled-history changes" constraint.
5. The ACP "run now" path uses `fetch_all()` too and needs no change; confirm the run report counts are unaffected.
