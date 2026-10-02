# Add generic parse-time item deferral to ger/feedpostbot

Repository:

`/srv/www/tiedepalsta/develtmp/feedpostbot/upstream-dev`

Upstream baseline:

`cb7774c`

Create an independent feature branch directly from that upstream baseline:

`feature/parse-item-defer`

This feature must remain generic and upstream-worthy.

Do not include any Tiedepalsta-specific, HS, TM, Avaruus.fi or image-handling logic.

---

# Problem

The existing event:

`ger.feedpostbot.parse_item_append`

allows listeners to modify a fetched feed item before it is added to the parsed item list.

A dependent extension sometimes needs to say:

> Do not process this particular item on this run, but offer it again on the next run.

The current alternatives are unsuitable:

- throwing from the parse listener skips/fails the entire feed run;
- consuming the item later with `do_post = false` permanently marks it handled;
- throwing later in the posting path can churn provisional tracking state and stop processing newer items.

We need a clean generic **defer this item for this run** control.

---

# Required semantics

Add a listener-writable boolean variable to:

`ger.feedpostbot.parse_item_append`

Conceptually:

```php
$defer = false;
```

Expose it through the event together with the existing variables.

If a listener sets:

```php
$defer = true;
```

then that individual parsed feed item must be omitted from the returned item list for that run.

Required observable behaviour:

- no topic/post is created for the item;
- the item is not marked handled;
- the item does not advance `latest`;
- no tracking/mapping is created by downstream code;
- an already tracked item is not updated on that run;
- later items in the same feed are still processed normally;
- the deferred item is offered again on the next feed run;
- existing listeners that do not know about `defer` behave exactly as before.

The implementation should be as small as possible.

Do not add retry counters, persistence, queues or source-specific state.

---

# Scope

The feature belongs at parse time.

Prefer a minimal implementation around the existing:

`ger.feedpostbot.parse_item_append`

event.

Expected shape conceptually:

1. build `$append`;
2. set `$defer = false`;
3. trigger the event with `item`, `append`, and `defer`;
4. apply listener-returned values;
5. if deferred, do not append the item to the parsed return array;
6. otherwise continue exactly as before.

Inspect the actual code before modifying it and adapt to existing event conventions.

---

# Backwards-compatible legacy parse events

The driver also has backwards-compatible events such as:

- `ger.feedpostbot.parse_rss_append`
- `ger.feedpostbot.parse_atom_append`
- `ger.feedpostbot.parse_rdf_append`

Inspect their ordering relative to `parse_item_append`.

Decide explicitly whether `defer` should also be available to those backwards-compatible events.

Prefer the smallest coherent API.

Important:

- do not accidentally allow an item deferred by one event to be re-enabled by another unless that is deliberately documented;
- preserve backwards compatibility.

Explain and test the chosen semantics.

---

# Optional capability constant

Consider adding a public capability/version constant such as:

`SUPPORTS_ITEM_DEFER`

only if it gives dependent extensions a clean way to fail safely when running against an older Feed Post Bot version.

Do not add it automatically if there is already a better existing capability/version mechanism.

Report the decision and rationale.

If added, keep it simple and stable.

---

# Event documentation

Update the event PHPDoc accurately.

Document:

- type: boolean;
- default: false;
- effect: omit this item from the current parsed result only;
- deferred items may be offered again on subsequent runs;
- deferral is not a permanent skip.

Do not mention Tiedepalsta or Avaruus.fi.

If README documentation is appropriate for extension authors, add only the minimum useful explanation.

---

# Tests

Add focused regression tests.

At minimum prove:

## Basic defer

Given multiple items in one feed:

- listener defers one item;
- deferred item is absent from parsed/fetched processing;
- surrounding items remain present and are processed normally.

## Retry semantics

Across two feed runs:

- first run defers item A;
- A is not considered handled/consumed by the generic driver behaviour;
- second run without deferral processes A normally.

Use the most appropriate existing test level available on upstream `cb7774c`.

Do not introduce update-existing/tracking code into this standalone feature branch.

If the upstream baseline does not contain tracking, test the parse/fetch invariant at the lowest reliable layer and state explicitly that tracked integration semantics will be verified after merge into the integration branch.

## Listener compatibility

- a listener that ignores `defer` preserves existing behaviour;
- modifying `$append` without deferring still works.

## Multiple items

- deferring one item must not stop or discard newer/other items.

## Legacy event interaction

Add a test for the chosen interaction with the backwards-compatible parse event(s), if they can affect `defer`.

---

# Safety / minimal-delta constraints

Do not:

- modify item identity logic;
- modify handled-history semantics globally;
- modify posting code;
- modify tracking code;
- add database schema;
- add ACP settings;
- add logs unless required by existing conventions;
- add Tiedepalsta-specific behaviour;
- add image/network logic;
- refactor unrelated parsing code.

This should remain a very small semantic delta from upstream.

---

# Branch/history requirements

Start from:

`cb7774c`

Create:

`feature/parse-item-defer`

Do not base this branch on:

- `feature/update-existing-feed-items`;
- integration branches;
- localization branches;
- local/private-host branches.

The feature should be independently mergeable.

Do not merge it into integration branches in this task.

---

# Validation

Run the strongest available validation for this standalone upstream branch.

At minimum:

- focused tests;
- full PHPUnit suite using the same PHP 8.4 / PHPUnit 9.6.37 scratch harness already used for Feed Post Bot work;
- `git diff --check cb7774c..HEAD`;
- PHP lint for modified PHP files;
- `git status --short`.

If the baseline suite count differs from integration branches, report the exact count rather than assuming a previous number.

Also inspect the complete diff for unintended changes.

---

# Commit

When implementation and tests are green:

Create one focused commit on:

`feature/parse-item-defer`

Suggested subject:

`Allow listeners to defer parsed feed items`

The commit should contain:

- feature implementation;
- tests;
- event documentation;
- any capability constant if justified;
- the two `claudeprompts` files for this task.

Do not merge the branch anywhere.

Do not push.

---

# Final report

Return:

1. exact branch and final commit SHA;
2. exact production-code change;
3. semantics of `defer`;
4. interaction with backwards-compatible parse events;
5. whether a support/capability constant was added;
6. tests added and what each proves;
7. focused and full PHPUnit counts;
8. lint/diff-check/status results;
9. complete changed-file list / diff stat;
10. confirmation that no database/runtime/integration branch was modified;
11. any integration-level behaviour that still needs verification after merge.

Print the report normally in the console response and save the same report to:

`/srv/www/tiedepalsta/develtmp/feedpostbot/upstream-dev/claudeprompts/prompt-01-result.md`
