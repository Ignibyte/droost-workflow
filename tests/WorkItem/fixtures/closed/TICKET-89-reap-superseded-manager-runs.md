---
title: TICKET-89-reap-superseded-manager-runs
status: done
ticket: cc6c6cec-5e09-445f-8ae0-2d6177952933
ticket_number: 89
type: bug
created: 2026-07-19
intake:
pipeline_spec: docs/planning/pipeline/completed/pipeline-reap-superseded-manager-runs.spec.md
---

# TICKET-89-reap-superseded-manager-runs

## Summary

tmux-bridge limit 3, discharged: `active` bare runs of RETIRED manager
lineages flip to `done` at the post-liveness choke points — after a
successful `manager_start` (both arms) and once at boot after the startup
reconcile (sparing the newest-retired lineage, the standing resume
candidate). `reconcile_managers`, `db.rs`, and the singleton index stay
byte-untouched.

## Why

Every `manager_start` mints its own project + run (`web.rs:1474-1482`); a
healthy manager run is born `'active'` (`create_run` hardcodes it,
`store.rs:306-307`) with `plan_path NULL` and has NO terminal path — only
the start compensators ever fail one (`web.rs:1492`, `:1518`).
`reconcile_managers` deliberately reaps only SESSIONS (its documented
contract, `tmux.rs:516-518`: "the manager's RUN row is deliberately left
as-is"). So every dead manager leaves
`{title:"mediation manager", status:"active", plan_path_present:false}`
in the manager's OWN `control list_runs` (limit 200, `web.rs:910-930`) —
the manager sees every prior dead manager's ghost — and in `GET /runs`,
forever. Recorded at limit 3 (`tmux-bridge.md:340-342`: "TICKET-72 does
not introduce it and does not clean it") and confirmed still-out at
TICKET-86's scope (`TICKET-86:97`).

## The seam, at HEAD `dca20f2` (evidence)

- **The mint**: `create_project` (`web.rs:1474-1477`, slug
  `manager-{uuid}`) → `create_run` (`:1478-1481`, status `'active'` in
  the SQL literal, `store.rs:300-316`) → `create_session(Manager)`
  (`:1482`; `sessions.run_id` NOT NULL FK, `schema.sql:36`).
- **The status vocabulary**: `RunStatus` = Active / Proposed / Confirmed /
  Building / Verifying / Done / Failed (`store.rs:57-76`); setters:
  `set_run_status` unconditional (`:428-436`), the CAS pair
  (`try_begin_building` `:466-489`, `try_confirm_run` `:506-519`), the
  building→failed fns (`:582-586`, `:604-609`). A bare manager run takes
  NO transition on the healthy path.
- **Who reads runs**: `Store::list_runs` has no kind/status filter
  (`store.rs:541-551`) → `GET /runs` (limit 50, `web.rs:702-705`) and the
  manager tool `list_runs` (limit 200, `web.rs:910-930`) both show the
  ghosts. NOT polluted: `/api/health` (reads no runs), the scheduler's
  build pass (`building_build_runs` filters `plan_path IS NOT NULL AND
  status IN ('building','verifying')`, `store.rs:1487-1492`), the
  concurrency cap (`count_building_runs`, `:559-562`).
- **The retire path**: `reconcile_managers` (`tmux.rs:524-552`) —
  `Ok(true)` adopt / `Ok(false)` retire session + revoke seat /
  `Err` skip, seat preserved. Sessions + seats ONLY. Callers: startup
  `build_state` (`web.rs:113`) and `manager_start` pre-mint
  (`web.rs:1415`).
- **The heal-shape precedent**: `ensure_manager_singleton`
  (`db.rs:137-176`) — newest-survives by
  `ORDER BY created_at DESC, id DESC`, the same ordering as
  `latest_manager_session` (`store.rs:1420-1422`).
- **The resume interplay (86)**: the resume branch
  (`web.rs:1432-1472`) reuses the SAME row — no new project/run, the
  session keeps its `run_id`; the compensator leaves the old run
  UNTOUCHED ("failing it would falsify history", `:1459-1461`). A
  resumed manager's original run is `active` and MUST stay so.

## EARS Requirements

| ID | EARS Requirement | Verification |
|---|---|---|
| REQ-001 | When a `manager_start` SUCCEEDS (fresh or resume), every `'active'` run belonging to a retired (`status <> 'active'`) manager session shall flip to `'done'`; the surviving lineage's run shall remain `'active'` (fresh mint's new run, or the resumed candidate's revived run — both auto-excluded because their session is `active`). | hermetic integration, both arms |
| REQ-002 | When the server boots, after the startup `reconcile_managers`, the same reap shall run SPARING the newest-retired manager lineage (the standing TICKET-86 resume candidate — including when a live manager was adopted). | integration + store unit |
| REQ-003 | The reap shall flip ONLY `'active'` → `'done'` and ONLY runs of `kind='manager'` sessions — never failed→done, never worker/plan runs, never rows of `active` sessions. | store unit (the SQL's sole guard) |
| REQ-004 | When a start FAILS, nothing shall be reaped; `reconcile_managers` and `db.rs` shall be byte-untouched. | integration + diff check |
| REQ-005 | After a supersede, the manager tool `list_runs` shall show the prior manager runs as `done`. | integration assert |
| REQ-006 | `docs/tmux-bridge.md`'s limit-3 row shall gain the SHIPPED note. | doc check |

## Scope

- In: the store fn + its unit; the two call sites (build_state
  post-reconcile, manager_start post-success both arms); a
  `tracing::info` count line; hermetic tests; the bridge-doc row note.
- Out: reconcile-side reaping (Locked 1); reaping worker/plan runs
  (orphaned-building already has `fail_orphaned_building_runs`); any
  schema/status-vocabulary change; deleting rows (statuses flip, history
  stays); an event-log row for the reap (observability is the tracing
  line + the visible status change).

## Locked decisions

1. **Supersede-time, never reconcile-side.** Rejected alternative: hang
   the reap off reconcile's `Ok(false)` branch, where the dead session's
   `run_id` is in hand. Rejected because reconcile's documented contract
   is sessions-only (`tmux.rs:516-518`), it is probe-coupled — an
   `Err`/ambiguous-namespace probe must NEVER reap a run (the same
   falsify-history honesty as 86's compensator), and TICKET-90 makes the
   namespace question live. The chosen choke points (start-success under
   82's lock; boot after reconcile) are post-liveness: no probe judgment
   exists there.
2. **Status `'done'`, never `'failed'`.** Nothing failed — the lineage
   ENDED. `Failed` would falsify; `Done` is the honest terminal for a
   bare run whose only meaning was "a manager lived here".
3. **Sparing is structural at start, explicit at boot.** After a
   successful start the survivor's session is `active` → auto-excluded by
   the predicate; at boot the newest-retired is spared explicitly so the
   resume candidate's run survives a restart (86's continuity doctrine).
   The always-spare-at-boot choice deliberately under-reaps the
   adopted-live edge (one extra surviving row) rather than ever reaping a
   resumable lineage.
4. **The SQL's shape is guarded by a dedicated store unit** — cargo-mutants
   does not mutate SQL strings (the 86-R1 lesson); the unit is the sole
   guard of the WHERE set.
5. **No index, no migration** — `db.rs` untouched; the reap is
   self-healing at every boot/start, so pre-existing stale rows (the dev
   DB has them NOW) discharge on the first boot after this ships.

## Design (settled)

- **D1 — the store fn.**
  `pub async fn reap_superseded_manager_runs(&self, spare_newest: bool) -> Result<u64>`:
  ```sql
  UPDATE runs SET status = 'done'
  WHERE status = 'active'
    AND id IN (SELECT run_id FROM sessions
               WHERE kind = 'manager' AND status <> 'active'
               [AND id <> (SELECT id FROM sessions
                           WHERE kind='manager' AND status <> 'active'
                           ORDER BY created_at DESC, id DESC LIMIT 1)])
  ```
  (the bracketed exclusion only when `spare_newest`; the inner ordering is
  byte-identical to `latest_manager_session`'s). Returns rows_affected.
- **D2 — call sites.** (1) `build_state`, immediately after the startup
  `reconcile_managers` (`web.rs:113`), `spare_newest = true` — sessions
  are freshly truthful there; heals the backlog every boot. (2)
  `manager_start`, after a SUCCESSFUL spawn in BOTH arms (fresh: after
  the spawn succeeds, before the 201; resume: after the spawn succeeds,
  before the 200), `spare_newest = false`. Failed starts return through
  their compensators without reaching the reap.
- **D3 — observability.** `tracing::info!(reaped, "…")` when count > 0;
  NO event-log row (deliberate — mirrors 87's Locked 6 rationale in
  reverse: the status flip IS visible in every run listing).
- **D4 — tests.**
  1. Store unit `reap_flips_only_retired_manager_lineages` — seed: an
     `active` manager session+run, two retired manager sessions with
     `active` runs (distinct created_at), a worker session+run, a
     `failed` run on a retired manager; assert with `spare_newest=true`
     only the OLDER retired lineage flips; with `false` both flip; the
     worker/active/failed rows never move (REQ-003's sole guard).
  2. Integration `manager_start_supersede_reaps_the_prior_lineage` —
     seed a retired manager (the 86 fixtures), fresh-start succeeds →
     prior run `done`, new run `active`; control `list_runs` shows it
     (REQ-001, REQ-005).
  3. Integration `manager_resume_spares_the_candidate_and_reaps_older` —
     two retired lineages, resume the newest → its run stays `active`,
     the older flips (REQ-001).
  4. Integration `boot_heal_spares_the_resume_candidate` — build_state
     against a store with two retired lineages → older reaped, newest
     spared (REQ-002).
  5. Failed-start regression: the existing spawn-failure tests assert
     run statuses — extend one assert that the OTHER seeded lineage's run
     is still `active` after the failure (REQ-004).
- **D5 — the bridge doc.** Limit-3 row (`tmux-bridge.md:340-342`) gains:
  "SHIPPED by TICKET-89 — superseded lineages reap to `done` at
  start-success/boot; the resume candidate is spared."

## Regression test plan

| REQ | Test | Kind |
|---|---|---|
| REQ-001 | D4.2 + D4.3 | integration |
| REQ-002 | D4.4 | integration |
| REQ-003 | D4.1 | store unit (SQL's sole guard) |
| REQ-004 | D4.5 + diff check | integration + review |
| REQ-005 | D4.2's list_runs assert | integration |
| REQ-006 | D5 | doc check |

Mutation surface: REAL for the Rust (the `spare_newest` branch, the two
call-site wirings, the count/tracing arm); the SQL rides D4.1.
`browser_testable: no`.

## Verification honesty

Fully provable hermetically — nothing supervised. The one soft claim: the
live box's backlog heals "at first boot after deploy", which lands
whenever the owner next deploys (nothing is deployed today; the live box
predates TICKET-74).

## Notes

- Forge ticket: #89 `cc6c6cec-5e09-445f-8ae0-2d6177952933` (unsprinted —
  Sprint-13 candidate)
- Origin: tmux-bridge limit 3 (`tmux-bridge.md:340-342`); TICKET-72's
  residual list (`TICKET-72:45-47`, `:94`); confirmed still-out at
  TICKET-86 (`TICKET-86:97`).
- Depends: none hard (86's resume shipped; the sparing logic references
  its candidate semantics). Independent of 87/88/90/91.
- Sequenced THIRD of 87–91.
- browser_testable: no.

## Outcome

**DONE** (2026-07-19, Opus 4.8) — pipeline `dedc5917`, on HEAD `4a8681e`
(88's tip). Shipped exactly as designed:

- `store::reap_superseded_manager_runs(spare_newest: bool) -> Result<u64>`
  — the two-SQL `UPDATE runs SET status='done'` over retired
  (`status<>'active'`) `kind='manager'` lineages; the spare subquery is
  byte-identical to `latest_manager_session`, so it spares exactly the
  TICKET-86 resume candidate.
- `web::reap_superseded` best-effort helper (never fails the caller) at 3
  post-liveness choke points: `build_state` post-reconcile
  (`spare_newest=true`), and `manager_start` resume + fresh success arms
  (`spare_newest=false`). Not on the live short-circuit, not on any failure
  arm. `reconcile_managers` / `db.rs` / the singleton index BYTE-UNTOUCHED.
- `docs/tmux-bridge.md` limit-3 row → SHIPPED note.

**Inspect**: two read-only critics + self found the runtime CORRECT — net
source change was ONE comment reword. A predicted "log-only match arms are
unkillable mutants" concern was REJECTED (gate survivor census + the shipped
`build_state` reconcile-match precedent) and then DISPROVEN empirically at
the gate (MSI 100%, zero log survivors). Recorded
`PR-druplit-log-only-match-arms-not-mutation-targets-001`.

**Verification**: `GATE GREEN [diff]` 16/16 — coverage 94.92% lines
(store.rs 99.31%, web.rs 92.91%), mutation **MSI 100.0% (4 caught / 0
missed)**. Tests: 2 store units (exact `rows_affected` count asserts) + 3
new integration (supersede-reaps, resume-spares-older, boot-heal) + 1
REQ-004 extension (a failed start reaps nothing).

**Honest limit**: nothing deployed (live box predates TICKET-74); the dev
DB's pre-existing stale rows self-heal on first boot after this ships.

Forge #89 `cc6c6cec` closed `done`; AAR `3a6fb9ee` submitted (effectiveness
5). Not yet sprinted (batch-end sprint-add after 91).
