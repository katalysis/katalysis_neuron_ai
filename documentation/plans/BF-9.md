# BF-9 plan: a chat never gets permanently stuck

Status: **approved (Glyn, 2026-10-22); decisions in §10, which override the sections above where they differ.** Significant task: it changes what the store hands Neuron (stored data, the P2.5 repair) and the agent's error path (P2.6). Branch `task/BF-9-chat-never-stuck` from `development`, PR into `development`.

## 1. Goal
No stored chat can fail every later turn: a failed turn always leaves an order Neuron accepts, a chat already in a bad order is repaired as it loads, and any order that still can't be sent fails before the provider is called, so a stuck chat costs nothing.

## 2. Where the check is, and why each attempt costs money (checked in `vendor/`, Neuron 4)
- Neuron checks the order only in `HistoryTrimmer::validateAlternation()`, which runs inside `ChatHistory::addMessage()`, i.e. when a message is **committed**. Loading (`getMessages()`) never checks.
- `ChatNode` sends `history + inbound` to the provider first and commits the inbound messages after the call succeeds (`InferenceNode::pendingConversation()`). So a stored order Neuron rejects is sent, paid for, and only then refused. That is BF-9's "every attempt makes a paid call".
- The rules the validator enforces (the oracle for this task):
  - V1 the context opens with a plain user message
  - V2 plain messages alternate user / assistant
  - V3 a tool result follows a tool call, and the assistant speaks next after it
  - V4 a plain user message never directly follows a tool call; a plain assistant reply after a tool call also fails (V2)
- Tool rounds commit in pairs (`ToolNode`, "deferred pair-commit"): the call and result of round *n* are committed by the provider call of round *n+1*, after it succeeds. The `ToolCall` objects in the stored call message are the same objects the tools wrote their results into, so **a stored tool call from a normal round always carries its results.** A call written for approval (P3.6) or an external result is written *before* it runs, so it has none.

## 3. Every way a turn can leave a bad order
A turn: provider call C1 → commit user message → (tool round: run tools → C*n+1* → commit call *n* + result *n*)* → commit final reply.

| # | Failure point | Stored tail after the failure | Today | After BF-9 |
|---|---|---|---|---|
| F1 | C1 fails | nothing | `storeFailedTurn()` adds user + error reply (P2.6) | unchanged |
| F2 | a tool, or the scanner middleware, throws in round 1; or C2 fails | user message (pair 1 never committed) | adds error reply (P2.6) | unchanged |
| F3 | **C3 or later fails** (the probed case); or a tool / the middleware throws in round 2+ | **tool result** | adds nothing → **stuck** | **fixed on failure**: error reply after the result (result → assistant is valid) |
| F4 | committing the final reply fails (DB) after ≥1 round | tool result | stuck | fixed on failure as F3 if the DB is back; else repaired on load |
| F5 | **PHP timeout / fatal error / killed process** during round 2+ (`storeFailedTurn()` never runs; a slow provider makes this realistic) | tool result | stuck | **repaired on load** (§4 R1) |
| F6 | DB fails between committing call *n* and result *n* | tool call **with** results, no result message | stuck (V4 on the next message) | **repaired on load** (§4 R2). Not fixed on failure: the DB is failing, so `storeFailedTurn()`'s writes would too |
| F7 | committing the user message fails, or `storeFailedTurn()` stores the user message but not its reply | user message | P2.5 repair (user, user) | unchanged |
| F8 | failure after the final reply is committed | assistant reply | valid; `storeFailedTurn()` adds nothing | unchanged |
| F9 | two tabs on one chat: interleaved commits | user/user, assistant/assistant (P2.5), **result → user** (F3's shape), or rarer shapes (a request's row landing between another's call and result) | partly repaired | targeted rules cover the known shapes; anything else goes to the fallback (§4 R3) |
| F10 | a tool call waiting for approval or an external result (P3.6) | tool call **without** results | left alone | **left alone** (§4); a new message on it fails before the provider (§5) |
| — | max tool runs | can't happen as a failure: the tool error handler turns it into a result | | |
| — | a context-window trim opening the context with a tool message | can't happen: the trimmer only cuts at a plain user message, and `archive()` counts placeholders as Neuron does (P2.5) | | |
| — | `ParallelToolNode` | not used (`parallelToolCalls` is off) | | |

## 4. The repair on load (`ChatMessageStore::repair()`, in memory only, as P2.5)
P2.5's rules stay (placeholder between two plain user or two plain assistant messages; before an assistant reply that opens the context). Added:

- **R1, tool result then user (F3–F5, F9).** A tool result followed by a plain user message, or ending the context (the next turn's message comes after it), gets the assistant placeholder after it: "[Recovered: the assistant's reply after this tool result was not saved]". Its ID is `msg_recovered_` + the next message's ID, or + the result's own ID at the end, so it's the same on every load.
- **R2, tool call with results but no result message (F6).** When every call in a tool call message has a result and the next message isn't a tool result, a result message is rebuilt in memory from those calls (copies; the same results the model would have got), followed by R1 if a user message or the end comes next. ID `msg_recovered_` + the call message's ID.
- **How a waiting tool call (P3.6) is told apart:** by its calls' results, from the stored row, not by position. A call from a normal round always has its result (§2); one written for approval or an external result has none (`ToolCall::hasResult()` false; approval `pending`, or `deferred`). A tool call message in which **any** call has no result is never touched, wherever it is. Test: P2.5's `testAThreadWaitingForApprovalIsLeftAlone` stays, plus one with the pending call followed by a user message (still untouched; §5 refuses the turn).
- **R3, the fallback (the general rule).** After the targeted rules, the result is checked with Neuron's own validator (`HistoryTrimmer::trim($messages + a probe user message, PHP_INT_MAX)`; without the probe when the context ends in a waiting tool call). If it still fails, the context starts at the earliest plain user message from which it passes, and at worst is empty. Logged as a notice with the chat ID and the cut position. Using Neuron's check as the oracle means a future change to it (4.x) can't bring the stuck chat back. The rows before the cut stay stored and in the transcript; they're just not sent. `archive()` already re-runs `repair()`, so its counts stay right.
- Loading still never writes (P2.5). The transcript (`transcriptJson()`) still shows only stored rows.

## 5. Before the provider is called (`ConcreteCmsAgent::handle()`)
- **`storeFailedTurn()`** (F3, F4): also adds the error reply when the last stored message is a tool result. One condition.
- **Pre-flight check:** before `chat()`, `handle()` checks `getChatHistory()->getMessages()` plus the new user message with Neuron's validator (as R3). If it fails, the turn fails at once: redacted log line, `ERROR_REPLY`, nothing stored (storing would fail the same check), **no provider call.** After §4 only F10 (a waiting tool call, then a new message) reaches this; today it can't happen, because no tool needs approval and none is deferred.
- Cost of the check: one extra `loadActive()` (a SELECT on the active rows) per turn. A repaired chat logs its repair notice twice per turn (pre-flight and Neuron's own load); acceptable, or I can drop the pre-flight's log line (Q3).

## 6. Tests (red first; no AI call, no API key; `FakeAIProvider` as the model)
`tests/Integration/AgentErrorPathTest.php`:
1. **The probed case:** a turn that fails on C3 after two tool rounds returns `ERROR_REPLY`; stored: user, call 1, result 1, error reply; the stored call row carries its results (pins §2's assumption); the next turn works, the provider is called once with an order Neuron's validator accepts and no placeholder. Red today (stuck).
2. **Every failure point, one data provider:** failure on C1, C2, C3, C4 (zero to three rounds), and a tool round's middleware throwing in round 1 and round 2 (a test-only middleware on `ToolNode`). For each: `ERROR_REPLY`; the **raw stored rows** plus a probe user message pass Neuron's validator (fixed on failure, without the load repair); the next turn works with one provider call. C3, C4 and round-2 cases are red today.

`tests/Integration/ChatMessageStoreTest.php` (rows appended directly, as a timeout or a failed write leaves them):

3. R1: [user, call, result] → placeholder at the end; [user, call, result, user, reply] → placeholder before the second user message; same IDs on every load; nothing written (checksum)
4. R2: [user, call with results] and [user, call with results, user, reply] → rebuilt result + placeholder; validator passes
5. waiting tool call followed by a user message → untouched (and not cut by R3)
6. R3: a shape the rules don't cover (a tool result straight after a user message; a context opening with a tool result) → context starts at the next valid user message; notice logged; nothing written
7. `archive()` with R1/R2 placeholders counts them as Neuron does (as P2.5's archive test)

`tests/Integration/AgentRepairBeforeProviderTest.php`:

8. a chat already stuck as BF-9 left it (rows appended: user, call, result) → the next turn answers, one provider call, the chat then holds the new turn
9. pre-flight: a waiting tool call then a new message → `ERROR_REPLY`, **provider call count 0**, rows unchanged
10. **Change to an existing test (flagged, stop-and-ask list):** `testControlNeuronsOwnStoreSendsTheBrokenChatAndTheTurnFails` asserts the provider *is* called once with the broken chat on Neuron's in-memory store. With the pre-flight it isn't called. I'd change it to assert call count 0 and rename it (`...IsRefusedBeforeTheProvider`); it still shows Neuron's own store doesn't repair. Its own commit, with the reason.

Tiers: fast after every change; full before the PR. All new tests are short (a few fake turns); none expected in `slow`.

**Scenarios: none.** The model sees something new only in a chat that was stuck or had a failed multi-round turn (the R1/R2 placeholders, the error reply after a tool result). No scenario creates those, and the prompt, tools and instructions are unchanged (`PromptBaselineTest`, `ToolBaselineTest` untouched). If you want a check anyway: the runner's free `--fake` dry run.

## 7. Files
- `src/Chat/ChatMessageStore.php`: `repair()` (R1–R3), its docblock and the class docblock's repair paragraph
- `src/ConcreteCmsAgent.php`: `storeFailedTurn()` (one condition, docblock), `handle()` (pre-flight check, a private helper)
- `tests/Integration/AgentErrorPathTest.php`, `ChatMessageStoreTest.php`, `AgentRepairBeforeProviderTest.php`
- `documentation/BEHAVIOUR_SPEC.md`: none of its entries covers chat history today; I'd add none unless you want one (Q4)
- last commit: PROGRESS entry, Current position, DELIVERY_PLAN tick

## 8. Out of scope
- P3.6: approvals and external results, resuming them, what a new message means while one waits (§9 Q2), and recording the tool pair of a turn that failed in round 1 (F2 still drops it, as P2.6 decided)
- Provider-level pairing rules beyond Neuron's validator (for example a provider refusing a tool call whose call IDs don't match): not something a stored chat reaches today
- Writing repairs back to the database (loading never writes, P2.5); cleaning up existing stuck chats on the dev site (they recover on their next load)
- The panel, the controller, the prompt, the tools, `vendor/`

## 9. Risks and questions
- **Q1. R2 rebuilds the missing result from the results stored on the call** (the model sees what the tool really returned, e.g. that a draft was written), rather than a placeholder saying the result was lost. The results are already stored and already went through the scanner. **Recommend: rebuild.**
- **Q2. For P3.6:** an approval that was answered and ran, followed by a failed C*n*, leaves the stored call *still marked pending with no result* (Neuron writes it before the decision and never updates the row). §4 can't tell that from a real wait, so it stays untouched and a new message is refused before the provider (free, but stuck until P3.6 handles it). P3.6 should decide what a new message does while a call waits (e.g. reject the waiting calls). Proposed as a note on P3.6, not done here.
- **Q3.** Keep the pre-flight's repair notice (two log lines per turn on a repaired chat), or silence it? **Recommend: silence it in the pre-flight; Neuron's own load logs once.**
- **Q4.** A BEHAVIOUR_SPEC entry ("a failed or interrupted turn never stops later turns; a chat that can't be sent fails before the provider is called")? It would make this a spec'd behaviour for later phases. **Recommend: yes, one entry**, if you agree it belongs there.
- **R3 drops context** in the shapes it handles: the model loses the turns before the cut. Only for shapes nothing else covers (rare interleavings, hand-edited rows); the alternative is a chat that never works again.
- **Deletions:** none planned beyond a changed condition in `storeFailedTurn()`.

## 10. Decisions (Glyn, 2026-10-22, on approving this plan)
These override the sections above where they differ.
- **§6 test 10:** approved. The control test changes to zero provider calls, in its own commit.
- **Q1, rebuild:** approved. A rebuilt result must not bring flagged content back without the notice. The copy keeps the flag the scanner already put in the stored result (`suspectedInstructions`). The turn that first sends it (no stored user message after it yet) also passes it through the scanner, so that turn's reply carries the notice. Later turns don't repeat it, as for any flagged result already in the history. Test: a stored call whose result holds flagged text.
  - How: a middleware before Neuron's `ChatNode` scans the recovered results in the history Neuron is about to send (the same message objects). The store has no scanner, so it can't do this itself.
- **Q2, the P3.6 note:** approved, and the panel must show a clear "waiting for approval" message in that case, not the generic error. Recorded as a proposal in the PROGRESS entry for P3.6. Not built here: no tool needs approval yet, so there is nothing to approve.
- **Q3:** silence the pre-flight's duplicate repair log line.
- **Q4:** add the BEHAVIOUR_SPEC entry: a failed turn never stops later turns (B-ERR-5).
- **R3 log:** when the fallback starts the model's view at a later user message, the log line says how many messages it left out.
- **Implementation note (found while writing the tests):** `storeFailedTurn()` reads the history through the repaired view, which ends in R1's placeholder after a stored tool result, not in the tool result itself. Adding the error reply through the history would then put two assistant messages in a row. So in that case the error reply is appended to the store directly, taking the placeholder's place. The stored rows then end in the tool result followed by the error reply, a valid order without any repair.
- **R3 and a waiting tool call:** R3 never cuts a context that holds a waiting tool call (§6 test 5). The pre-flight stops the paid call instead.
- **Found during implementation (Glyn approved repairing on load):** F2 also has a timeout form. A process that dies after C1 commits the user message, before the first tool pair is saved, leaves the chat ending in the person's own message, and every later turn is refused. The load repair adds a placeholder reply after a final user message too (ID `msg_recovered_end_<id>`, as for a final tool result). Two existing tests changed setup, each in its own commit (Glyn approved): the store's order test reads through `loadAll()`; `PageContextTagTest` writes a turn through one history. `AgentPageContextTest`'s fake provider now gives a new reply object per turn. Its shared object had left turn 3 in an order Neuron refuses, which the test never saw because it only looked at what was sent.
- **Found during implementation:** Neuron's deserializer drops a tool call's results (`deserializeToolCall()`), but the stored row keeps them. So §4's check ("every call has a result") reads the row, and R2 rebuilds the result message from the row through Neuron's own deserializer (`tool_call_result`).

