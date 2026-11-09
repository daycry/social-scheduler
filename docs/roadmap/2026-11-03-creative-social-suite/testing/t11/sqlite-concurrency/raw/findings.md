# T11 register failure: causal isolation and scoped correction

Status: scoped code patch ready for fresh review. All13 State mutation methods now reserve SQLite's writer before their first read, each backed by a real two-PDO RED. Public acceptance is still pending independent rerun; the earlier post-register-only fix focal failed before register at9.09%. Parent T11 stays open (including C18).

## Baseline and original evidence

Original QA run `run-general-2d90a00c-beeb-4866-833d-5c94848817ec` and original QA reports were read only. Parent supplied the original register failure at 90.91%, one failed lease, no defer/next register part, complete 76,985-byte `.part`, no final MP4 and SHA `71b49dcf7fa18bb6a95c1d23fc4199d0a44f8664ba6b2129453cc4833a0dd96e`. The original exception was not captured; its exact class cannot be retrospectively asserted.

Baseline snapshots are in `baseline/`. Runner SHA `8c852a64d015deb2fd8576b0293a8444f5ca08d7a5bb85ac73c82c50d55bd274`; Transfer SHA `0365ca0b6cf75109a2bfb2e7955b351dd1898bc817af7c793afb735ab857995f`; State SHA `4aa9aac214818cea35ed3b1324e011089ec642fd77b3dfd115edb0237599eb87`.

## Reproductions and isolation

- Run1: sandbox spawn EPERM, no test execution. No result assigned to product.
- Run2: `run-general-99ee87de-1ae7-458d-a927-542c11ef8a8c`, mobile360 composite 1/1 passed, 31.9s; passive exception instrumentation, no captured exception.
- Run3: exact root in `run3-root.txt`, mobile360 composite 3/3 passed (31.5s, 38.5s, 32.1s), passive instrumentation. These do not erase original failure.
- Run4: `run-general-07687ab1-d5de-426a-969d-595014807499`, mobile360 composite 1/1 failed at original line77/Lista and 90.91%. Temporary `beforeExecuting` callback pauses 1.5s immediately before `UPDATE media_assets` during checkpoint, widening the real read-to-write interleaving with normal HTTP polling/session writes. No exception, query result or application state was fabricated.
- Run4 private `register-debug.jsonl`: `Illuminate\\Database\\QueryException`, `SQLSTATE[HY000]`, driver error5 `database is locked`, at State164 media import_state UPDATE. Lease `a242ce6f-4bf4-4637-812b-b72039207c34`, register failed with no abandoned successor. Trace Transfer218 -> save -> checkpointRegistration143 -> update164. Reaching this update proves both fflush and fsync had returned true because both are checked before save; no subsequent disk function precedes the failing update. `.part` SHA and byte count equal original.
- Thus the reproduced cause is a SQLite DEFERRED transaction read-to-write upgrade colliding with another writer; flushing is excluded for this reproduction. Exact original exception remains unavailable, but the controlled repro reproduces its phase, bytes, SHA, progress and state signature.

## Regression and minimal correction

Regression in StudioAvTransferTest clones only its own disposable fixture database into a separate SQLite file and launches a real second PDO writer updating a separate table. The first connection is executing the real transfer/checkpoint and real file operations. Callback only schedules the writer; no exception is injected. Both subprocesses are waited for and the app's original test connection is restored.

`red-output.txt` and final-fixture `red2-output.txt`: 1 test failed with real HY000/5 at the same media checkpoint update. A first GREEN attempt exposed a fixture connection mistake (reading the original in-memory model connection:0 instead of14); this was fixed by fetching the model from the cloned connection. It is preserved in `green-output.txt`, not counted as passing evidence.

`green2-output.txt`: 1 passed,9 assertions. Subsequent lease equality assertion is included in neighbors run.

Correction is limited to checkpointRegistration: inside its DB transaction, SQLite only, QueryBuilder `UPDATE studio_av_leases SET id=id WHERE id=?` before any fenced state read. This reserves the writer first; no row value/timestamp changes and no I/O is repeated. Existing fencing, ownership and rollback remain in their existing code path. MySQL path is unchanged and untested here. Laravel SQLiteConnection in installed PHP8.3.33 ignores transaction_mode before PHP8.4, so changing config to IMMEDIATE would not repair this runtime. No global config or manual BEGIN was introduced.

`neighbors-output.txt`/`neighbors.xml`: 58/58 passed,331 assertions,14.766s,0 skipped/errors/failures, covering State, Registration, Transfer and Delivery. This includes whole-lease row equality across the reservation, ownership/fencing/rollback/cancellation existing regressions and real two-connection contention.

## New public failure and remaining gates

Run5 clean product (no diagnostic instrumentation): `run-general-402f58b2-7398-46cf-99b7-043e313a3581`, mobile360 1failed,46.1s. Same public Lista assertion but only9.09% and one accepted plan lease; no register was reached. Do not label checkpoint fix as public acceptance. Run6 passive diagnostic passed1/1 (34.6s test/55s total), no exception captured. This does not explain or erase run5. All debug sessions terminal; port8080 has no listener. Runner was restored exactly to baseline after run6, Transfer also exactly baseline. No further repeats were run.

## Continued isolation: deadline and plan handoff

Parent requested continuing rather than treating the register-only fix as resolved. Additional RED/GREEN:

- `deadline-red-output.txt`: real lock wait made checkpoint return without phase_timeout after deadline (1fail). Optional deadline was passed from Transfer::save into State; post-lock/pre-accept guards added.
- `budget-red2-output.txt`: guard-only still waited0.437s for writer despite0.15s caller budget; fails<0.3 assertion. First `budget-red-output.txt` is a fixture mkdir failure and is not TDD evidence.
- `budget-green-output.txt`:2/2,23assertions. SQLite-only PRAGMA busy_timeout becomes min(original,ceil remaining milliseconds), restored in finally, with deadline guard after writer reservation and before checkpoint acceptance; exhausted SQLITE_BUSY/LOCKED maps to phase_timeout. Test waits for real second PDO writer's locked marker before starting timed State call, checks return<0.3s for deadline0.15s while writer holds0.4s, journal stays0 and complete lease row unchanged. Nondeadline case uses real transfer IO; deadline-only case has an explicit synthetic partial fixture and tests DB budget rather than media codecs. MySQL has no busy-timeout change or noop writer reservation.
- `probe-commit.php`/`commit-probe-output.txt` confirmed the installed Laravel `transaction()` COMMIT-error path leaves PDO inTransaction=true at Laravel level0 when a reader prevents COMMIT. `commit-red-output.txt` is a permanent real-PDO reader regression: PDOException instead of phase_timeout.
- SQLite checkpoint now uses the public Laravel beginTransaction/commit/rollBack APIs, which keep the transaction level until COMMIT succeeds, so a failed COMMIT can be rolled back to the original level. No raw BEGIN SQL or tracking mutation. MySQL continues using its previous transaction() path. Busy timeout is recomputed from remaining time before COMMIT; original PRAGMA is restored in finally. Post-reservation/pre-accept/pre-commit deadline guards reject late acknowledgements. Native runtime's external cap is unchanged.
- `commit-green-output.txt`:3/3,41assertions, proving concurrent writer success, writer-lock budget expiry and reader-blocked COMMIT budget expiry. Both deadline cases require phase_timeout, journal0, original complete lease row, timeout restored, Laravel transactionLevel0 and PDO inTransaction=false; partial bytes remain unchanged. Existing caller-owned outer transaction final commit remains owned by that caller; this test proves the standalone checkpoint transaction used by the runtime.
- `claim-red-output.txt`: real two-PDO race when plan is accepted and Queue calls State::claim. SQLITE HY000/5 occurs at INSERT studio_av_leases; no next lease is installed. Callback schedules a real independent DB writer immediately before the insert; no exception injected. This demonstrates the same read-to-write weakness in claim, matching the shape of run5 but does not retrospectively identify its uncaptured exception.
- Design sent to parent before claim fix: small shared SQLite-only QueryBuilder noop-PK writer reservation before the first read, addressed by export id in claim and lease id in checkpoint. Applied to those two methods only. `claim-green-output.txt`:1/1,9assertions, next render lease running, original plan accepted, one queued message, independent writer committed. Other mutating State methods have not been given this helper and are not claimed concurrency-safe.
- `neighbors2-output.txt`/`neighbors2.xml`:80passed/84total,455assertions,0errors/failures,4opt-in native cases skipped,19.728s. Do not count84passed or claim this reran native FFmpeg. Sources changed since neighbors1, whose58 count is historical.
- Claim/other State mutations retain their inherited busy timeout and transaction policy; no caller deadline was added to them. Only checkpoint's writer/commit waits are bounded here.

## Knowledge candidate for parent arbitration

Symptom: durable copied bytes are present but register fails generically; a plan may be accepted without installing its successor lease. Cause: SQLite read-to-write upgrades under DEFERRED transactions compete with session/queue writers; Laravel PHP8.3 ignores transaction_mode. Do: acquire the writer before fenced reads, budget waits per operation and handle failed COMMIT through tracked Laravel APIs. Evidence: TransferTest real two-PDO3dataset regression, QueueTest plan-handoff regression, and run4 source/trace/bytes. This is a pending candidate only; no knowledge approval/promotion or external publishing was performed.

## Final common-cause patch and verification

Parent chose broader cheap mutation regressions rather than discovering the same cause phase by phase through E2E.

- `mutations-red-output.txt`:8cases,1passed(claim already fixed),7realHY000/5 errors in begin/defer/accept/cancel/retry/fail/failPosition. Each test starts the second PDO writer after the first SELECT in the real State transaction. After adding reservation at each proven entry, `mutations-green-output.txt`:8/8,66assertions.
- `registration-mutations-red-output.txt`:2realHY000/5 errors in allocateRegistration INSERT media and bindRegistrationLocation UPDATE workspace. After reservation, `registration-mutations-green-output.txt`:5/5,64assertions including prior checkpoint/budget cases.
- `initialize-red-output.txt`:realHY000/5 INSERT workspace; `initialize-green-output.txt`:1/1,8assertions after reservation before the first export read.
- `reject-red-output.txt`:realHY000/5 invalidating the accepted source checkpoint during verify; adding reservation yields final `mutators-green-all-output.txt`:10/10,83assertions, covering initialize/claim/begin/defer/accept/cancel/retry/fail/failPosition/rejectCheckpoint.
- Thus all13 mutating State entry points have real concurrency evidence. `workingExport` is read-only and is explicitly verified to issue no UPDATE; task/checkpoints read paths also retain their previous code.
- `nesting-output.txt`:1/1,13assertions at that snapshot. Final nesting test also checks workingExport has no UPDATE. Test verifies caller rollback restores journal0 and executes no afterCommit callback, caller commit executes callback exactly once, and nested State methods preserve caller transaction level. Bytes remain physically available after rollback as an intentionally unacknowledged tail.
- **Final snapshot** `final-neighbors-output.txt`/`final-neighbors.xml`:96total,92passed,567assertions,4native opt-in skipped,0errors/failures,81.049s. Four skipped cases are the native FFmpeg/database-worker opt-in group and were not executed in this lightweight run. This is a local neighboring PHP verification, not QA green or full-suite acceptance. No E2E was run after the common-cause expansion because parent holds the native slot until fresh review/coordination.
- Source scope: actual changes only State.php, Transfer.php, StudioAvTransferTest.php and StudioAvQueueTest.php. Runner exactly baseline, all temporary diagnostics removed from app. `freeze.py` writes the five-source baseline/final SHA manifest and limited diff for independent review.
- Final child processes are waited for, all our tool sessions terminal and no port8080 listener. No foreign process was terminated. No ledger write, commit, push, deployment, native imaging, external network, original database write or QA-report modification.

Remaining gates: parent fresh independent diff review; coordinated native/public regression and independent QA2; C18 captions and parent T11/global roadmap work. Exact original exceptions from QA original and run5 remain unavailable; same mechanisms are now proven by controlled natural interleavings, not inferred success from repetition.

No full63 rerun, ONNX/GPU/imaging, MySQL, VPS, real R2, external service, commit, push or deployment. Original artifacts untouched. Root must perform fresh review before final regression/QA. Instrumented source snapshots are separate temporary files and must be removed from app source before completion.
