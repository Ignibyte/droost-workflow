# The cockpit provider API, `/work-items/v1`

The contract between droost/workflow's `droost_cockpit` work-item provider
(the client) and any server that runs a work-item queue for it: the Droost
Cockpit, co-located or remote, or a hub. The client ships with droost/workflow
(`src/WorkItem/CockpitWorkItemSource.php`, `CockpitEventRelay.php`,
`CockpitHttp.php`); `tests/fixtures/cockpit-stub.php` is this document's
executable form, and a test holds the two to each other route for route.

## Base, auth, errors

- **Base:** the value of the environment variable named by
  `work_item.cockpit.url_env`, then `work_item.cockpit.path` (default
  `/work-items/v1`). Every route below is under it. Only `http` and `https`
  are spoken.
- **Auth:** every request carries `Authorization: Bearer <token>`, the value
  of the variable named by `work_item.cockpit.token_env`. A missing or unknown
  token is **401**. A token that is valid but not allowed the request (an
  event for another seat's run, say) is **403**.
- **Bodies** are JSON (`Content-Type: application/json`) both ways.
- **Errors** are `{"error": "<one line>"}` with the status below, and
  `Content-Type: application/json`. The one line must never contain a token;
  should it echo the client's, the client prints `[token]` in its place.
- **Redirects** are never followed by the client: answer where you are asked.
- **Versioning:** `/v1` is additive only. A server may add keys and routes; a
  client ignores what it does not know. Renaming or removing anything is `/v2`.

## Routes

| Method | Path | Request | Success | Errors |
|---|---|---|---|---|
| GET | `/items/{id}` | none | 200 `{"item": WorkItem}` | 401, 403, 404 |
| GET | `/items` | `?status=<state>`, optional | 200 `{"items": [WorkItem, …]}` | 400 (an unknown state), 401, 403 |
| POST | `/items` | `{"title": "…", "type": "…"}` | 201 `{"item": WorkItem}`, created in `backlog` | 400 (no title or type), 401, 403 |
| POST | `/events` | `{"events": [RunEvent, …]}` | 200 `{"accepted": n, "duplicates": m}` | 400 (not a list of events), 401, 403, 413 (over the limits) |

`{id}` is the ticket's id, URL-encoded.

## WorkItem

The shape droost/workflow's `WorkItem::toArray()` writes:

| Key | Type | |
|---|---|---|
| `id` | string | the ticket's identity, e.g. `TICKET-12` |
| `number` | integer or null | |
| `title` | string | |
| `status` | string | one of `backlog`, `ready`, `in_progress`, `review`, `done` (Drupal Workflow state ids) |
| `type` | string or null | `feature`, `bug`, … |
| `body` | string | the ticket's text |
| `extra` | object | any other fields, kept as the server has them |
| `path` | string or null | may be omitted or null |
| `source` | string | may be omitted: the client sets `droost_cockpit` itself |

`id`, `title` and `status` are required. A status outside the five is refused
by the client.

## RunEvent

One line of the run-event log, as `schema/run-event.v1.json` in droost/workflow
describes it: `schema` (`droost.run-event/1`), `event_id`, `seq`, `run_id`,
`work_item_id`, `at`, `type`, `payload`.

- **`event_id` is the dedup key.** The client delivers at least once: a batch
  whose answer was lost is sent again. The server stores an event whose
  `event_id` it has already stored as a duplicate, never twice, and counts it
  in `duplicates`. `accepted + duplicates` is the batch's size.
- **`seq` is the client's cursor, never an identity.** It is strictly
  increasing per project log, not per server.
- **Limits:** at most **100 events** and **256 KiB** of events per request
  (**413** beyond either). The client never sends more. An event that alone
  passes 256 KiB is sent as a **stand-in**: its envelope unchanged and its
  `payload` exactly `{"omitted_bytes": n}`, the size of the line left out. The
  whole event stays in the project's `events.jsonl`. A server accepts the
  stand-in like any other event, and a payload of that shape is never one the
  client writes to its log.
- Events are sent in `seq` order. A server that needs a run's state derives it
  from them: `run.started` binds the run's `work_item_id` to the ticket, and in
  cockpit mode the server, not the client, moves the ticket
  (`in_progress` on `run.started`, `review` on `run.completed`, if it chooses).

## What the client does with each answer

- `GET /items/{id}` at `run --ticket=<id>`: 200 binds the ticket and caches it
  in the state directory (`work-item-<id>.json`), provided the item's `id` is
  the one asked for (an item for another ticket refuses the run); 404 refuses
  the run ("no ticket"); anything else binds from the cache when one exists,
  and otherwise refuses the run with one line naming the server's origin. A ticket never
  seen is never bound.
- `POST /events`: a 2xx with integer `accepted` and `duplicates` moves the
  client's cursor to the batch's last `seq`. Anything else leaves the cursor,
  stops the relay for that command (one stderr line), and is retried by the
  next command, or `droost-workflow relay`.
- The client waits at most **5 seconds** to connect, and at most 5 seconds for
  each read of the answer. A server that goes silent for longer is a failure
  to the client, and the batch comes again. (A server that drips its answer a
  byte at a time can hold a request longer; answer whole.)
