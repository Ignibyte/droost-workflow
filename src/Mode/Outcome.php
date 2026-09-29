<?php

declare(strict_types=1);

namespace Droost\Workflow\Mode;

/**
 * The seven things that can happen when a phase is worked.
 */
enum Outcome: string {

  // The phase passed its gates and the run moved on.
  case Advanced = 'advanced';

  // The gates passed, and now the seeker checkpoint holds the run: an
  // adversarial inspection must be recorded clean before it moves.
  case InspectionDue = 'inspection-due';

  // Pair mode: the run is waiting for an answer before it will continue.
  case Paused = 'paused';

  // A CHECK that is not a gate blocked, and nothing was spent doing it. The
  // agent is meant to go away, change something real and come back; a
  // legitimate correction cycle can be long, and re-running costs nothing.
  //
  // Distinct from Failed, which was carrying both meanings. A declaration block
  // reported `failed` with `retries.attempts: []` and `exhausted: false`, and
  // the README tells a reader who sees `failed` to reset — so an agent that
  // believed the envelope destroyed a live run that one `declare-changes`
  // would have freed. The enum's own comment said Failed meant a spent budget
  // or a terminal failure, and this was neither.
  case Blocked = 'blocked';

  // A gate blocked, and the retry budget is spent or the failure is terminal.
  case Failed = 'failed';

  // The fast flow's loop (0.11): a failure at test, or code that moved after
  // code's gates passed, sent the run back to code. Not a failure of the run:
  // fix what the envelope names and run code again, and test follows.
  case Returned = 'returned';

  // The fast flow's loop, at its end: the phase's failures were written up
  // as follow-up tickets and the run moved on. The phase is recorded
  // deferred, never passed, and the envelope names each ticket.
  case Deferred = 'deferred';

  // The terminal phase passed. There is nothing after this.
  case Completed = 'completed';

}
