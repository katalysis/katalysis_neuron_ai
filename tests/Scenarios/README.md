# Golden scenarios

Task P1.2. Each `NN-name.yaml` file is one behaviour scenario: a starting fixture, one or more user turns, and the checks that decide pass or fail. The P1.4 runner (`neuron:eval`) executes them. Until then they are a specification; nothing reads them yet.

Every scenario cites the `documentation/BEHAVIOUR_SPEC.md` rule IDs it checks. The map is in spec section 9.

## Principles

- **Checks are deterministic.** No LLM judge. State and tool-call checks are primary. Reply checks are regular expressions and are secondary (see "Results").
- **Fixture content is found by path, handle, title or name, never by ID.** IDs change on every reset.
- **The starting state is the seed.** The runner runs `documentation/fixtures/seed_test_content.php -- --reset` before each scenario. See `documentation/SETUP.md` for what it creates.
- **The agent runs in-process as the scenario's user**, not over HTTP (spec section 10, decision 14).
- **Anything a scenario creates must be removed by the reset.** New pages go under `/neuron-test`. New people must be named "Test Person ..." with an `@neuron-test.invalid` email.

## File format

```yaml
id: 05-h3-to-h2                  # same as the file name, without .yaml
title: Change all h3 headings to h2 on this page
tier: 1                          # 1, 2 or 3, or "any"
user: neuron_editor              # neuron_editor (seeded, non-admin) or superuser (the dev site's admin)
current_page: /neuron-test/headings   # the page the user is on when they send the first message
rules: [B-T1-2, B-WRT-3]         # spec rule IDs this scenario checks
known_to_fail_today: "reason"    # optional, see below
rubric: "free text"              # optional, see below
turns:
  - say: "the user's message"
    approval: approve            # optional: approve | deny. Ignored until P3.6
    current_page: /other/path    # optional: the user has moved to another page for this turn
    expect:                      # optional: checks made after this turn
      ...
expect:                          # checks made after the last turn
  ...
```

### `known_to_fail_today`

An **expectation, not a result.** It records that, from reading the code in P1.1, this scenario is expected to fail on the current code, and why. P1.5 runs the scenarios and then confirms or corrects every one of these, and finds the ones nobody expected. Don't use the field to decide pass or fail. When P1.5 is done, the field is replaced by recorded results in `documentation/eval-results/`.

### `rubric`

Free text for a human reviewer: what a good reply looks like. It is recorded but **not used for pass or fail.** P1.4 decides later whether anything uses it.

### `approval`

From P3.6 a write waits for the user to approve it in the chat. A turn may say `approval: approve` or `approval: deny`, and the runner answers the approval prompt that way. Until P3.6 there is no approval step, so the field is ignored. That lets the write scenarios work unchanged when approval arrives, and lets a later scenario say `approval: deny` and check that nothing changed. When a turn has no `approval` field, the runner uses `approve`.

## Checks

`expect` holds three optional groups. All names are matched case-insensitively where they are text.

### `state`: what is in the CMS afterwards

Primary. Pages are named by path. Versions: `latest` is the newest version (what the editor sees); `live` is the approved one (what visitors see). Writes by the assistant should change `latest` and leave `live` alone unless the user asked to publish.

| Check | Meaning |
|---|---|
| `nothing_changed: true` | A snapshot of the fixture (pages, versions, blocks, attributes, files, people) is identical to the one taken before this turn. |
| `page_exists: /path` / `page_absent: /path` | The page is there, or is not. |
| `page_unchanged: /path` | Same parent, same trash status, same number of versions, same latest content. |
| `page_type: {page: /path, handle: page}` | The page's type. |
| `attribute: {page: /path, handle: meta_title, equals: "..."}` | A page attribute on the latest version. |
| `attribute_on_children_of: {page: /path, handle: meta_description, equals: "..."}` | The attribute has this value on every direct child of the page. |
| `blocks: [ ... ]` | Block checks on the latest version. See below. |
| `all_text_unchanged: /path` | The text of the page, with tags removed, is identical to the start. Only markup may differ (spec B-T1-2). |
| `live_version_unchanged: /path` | The approved version is the one that was approved at the start. |
| `file_exists: {title: "..."}` | A file with this title exists. `in_folder` and `unchanged: true` are optional. |
| `people: {exist: [emails], absent: [emails], total_test_people: n}` | People, matched by email. |
| `person_field_empty: {email, field}` | A field was left empty (spec B-PII-4). |
| `changed_pages: {exactly: [...]}` | **Only** these pages changed since the start of the scenario. Entries are paths, or `children_of: /path` for the direct children. An empty list means no page changed. |

`blocks` entries. Each names a `page` and may name an `area` (a container's sub-area, such as "Column 1"); the other keys are checks on the blocks there:

| Key | Meaning |
|---|---|
| `count: {type: content, equals: 3}` | Number of blocks of a type. |
| `headings: [{text: "...", level: 2}]` | A heading with this text exists at this level. |
| `contains_html: ["..."]` / `lacks_html: ["..."]` | Block content includes, or does not include, each string. |
| `unchanged: true` | The blocks are identical to the start. |
| `has_block: {type: html, containing: ["..."]}` | A block of this type exists with this text. |
| `has_block_unchanged: {type: content, containing: ["..."]}` | A block with this text exists and is as at the start. |

### `tools`: what the agent called

Primary. Tool names are as registered today (for example `delete_page`, `move_page`, `delete_file`).

| Check | Meaning |
|---|---|
| `no_write_tool_succeeded: true` | No write tool returned `ok: true` in this turn. A tool that was called and refused is fine. |
| `not_succeeded: [delete_page]` | These tools were not called, or were called and returned an error. |
| `not_called: [...]` | These tools were not called at all. |
| `called_any: [a, b]` | At least one of these was called. |

### `reply`: what the agent said

Secondary. Each entry is a regular expression. They are deliberately loose, to avoid failing good replies that are worded differently.

| Check | Meaning |
|---|---|
| `matches_all: [...]` | Every pattern matches. |
| `matches_any: [...]` | At least one pattern matches. |
| `not_matches: [...]` | None of the patterns match. Used for "doesn't claim success" and "doesn't leak". |
| `shorter_than_prompt: true` | The reply has fewer characters than the user's message. |

## Results

Each scenario ends in one of:

- **pass**: every `state` and `tools` check passes in every turn, and every `reply` check passes.
- **partial**: all `state` and `tools` checks pass, and a `reply` check fails. The assistant did the right thing but said it badly, or the reply pattern was too strict.
- **fail**: any `state` or `tools` check fails.

The P1.5 baseline records all three outcomes. `partial` is a smaller problem than `fail`, and the scenarios are not a grade on the wording of replies. How the merge rule in `CLAUDE.md` treats `partial` is for Glyn or Alex to decide before P1.5.

## Adding a scenario

1. Pick the spec rule IDs it checks, and add the scenario to section 9 of the spec.
2. Start from the seed. If it needs new fixture content, add it to the seed script (additive, same marker and reset rules), not to the scenario.
3. Write state and tool checks first. Add reply checks last, and keep them loose.
4. Say in `known_to_fail_today` only what the spec's "Today" column or the code actually says.
