<?php

declare(strict_types=1);

namespace Droost\Workflow\Evidence;

use Droost\Workflow\State\RunStateStore;

/**
 * What a run actually measured, in rows droost wrote and the agent cannot.
 *
 * The run record already held this, badly. `gate_results` is a JSON blob inside
 * run.json, round-tripped without validation, holding only the LAST attempt per
 * gate, and on a real record it measured 113,313 bytes of a 114,250-byte file —
 * 99.2% of the run's state was one phpcs report, stored twice, unqueryable. You
 * could not ask it "which gates have ever failed in this run", "was this green
 * about the code as it stands now", or "how many tests did phpunit actually
 * run", because none of those are things a blob answers.
 *
 * So evidence moves here and control stays there. RunState keeps deciding
 * phases — it is a 27-field value object with seven separate rebuild sites and
 * a schema version checked strictly on load, and none of that needs disturbing
 * to fix a storage problem. Nothing in this class knows what a phase means.
 *
 * SQLite, via PDO, and deliberately not Drupal's database: half of droost runs
 * with no Drupal booted at all (the standalone `droost-workflow` binary, the
 * guard hook), and a connection they cannot open is a record they cannot read.
 * PDO ships with PHP. The guard hook in particular has no autoloader and must
 * never acquire one, so everything it needs is reachable with a bare `new PDO`.
 *
 * The file sits beside run.json in the gitignored state directory. It is local
 * evidence, not a review artefact: a binary in git conflicts unresolvably the
 * first time two runs happen on two branches. What gets committed is the digest
 * rendered from it.
 */
final class EvidenceStore {

  /**
   * The database, project-relative.
   */
  public const string FILE = RunStateStore::STATE_DIR . '/evidence.sqlite';

  /**
   * The schema this build writes.
   *
   * Migrations are forward-only and additive. A store written by a newer droost
   * is READ rather than refused: the evidence is the point, and a column this
   * build does not know about costs it nothing. A store written by an older one
   * is migrated up in place.
   */
  public const int SCHEMA_VERSION = 1;

  /**
   * The open connection, or NULL until first use.
   */
  private ?\PDO $pdo = NULL;

  /**
   * Constructs an EvidenceStore.
   *
   * @param string $projectRoot
   *   The repository root.
   */
  public function __construct(private readonly string $projectRoot) {}

  /**
   * The database path for a project root.
   *
   * Static so the guard hook can find it with no autoloader and no instance.
   *
   * @param string $projectRoot
   *   The repository root.
   *
   * @return string
   *   The absolute path.
   */
  public static function pathFor(string $projectRoot): string {
    return rtrim($projectRoot, '/') . '/' . self::FILE;
  }

  /**
   * Opens the database, creating and migrating it if need be.
   *
   * @return \PDO
   *   The connection.
   *
   * @throws \Droost\Workflow\Evidence\EvidenceError
   *   When the directory cannot be made or the file cannot be opened.
   */
  public function connection(): \PDO {
    if ($this->pdo instanceof \PDO) {
      return $this->pdo;
    }
    $path = self::pathFor($this->projectRoot);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, TRUE) && !is_dir($dir)) {
      throw EvidenceError::unwritable($dir, 'the state directory could not be created');
    }
    try {
      $pdo = new \PDO('sqlite:' . $path, NULL, NULL, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
      ]);
    }
    catch (\PDOException $e) {
      throw EvidenceError::unwritable($path, $e->getMessage());
    }
    // WAL so a reader — the guard hook fires on every edit — never blocks the
    // writer, and NORMAL because losing the last row to a power cut costs a
    // re-run of one gate, while fsync on every insert costs every gate.
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $this->pdo = $pdo;
    $this->migrate($pdo);

    return $pdo;
  }

  /**
   * Brings the schema up to SCHEMA_VERSION.
   *
   * @param \PDO $pdo
   *   The connection.
   */
  private function migrate(\PDO $pdo): void {
    $at = (int) ($pdo->query('PRAGMA user_version')->fetchColumn() ?: 0);
    if ($at >= self::SCHEMA_VERSION) {
      return;
    }
    if ($at < 1) {
      $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS run (
          run_id          TEXT PRIMARY KEY,
          started_at      TEXT NOT NULL,
          preset          TEXT NOT NULL DEFAULT '',
          mode            TEXT NOT NULL DEFAULT '',
          enforcement     TEXT NOT NULL DEFAULT '',
          base_commit     TEXT,
          spec_path       TEXT,
          spec_hash       TEXT,
          spec_frozen_at  TEXT,
          spec_text       TEXT
        );

        CREATE TABLE IF NOT EXISTS check_result (
          id              INTEGER PRIMARY KEY AUTOINCREMENT,
          run_id          TEXT NOT NULL,
          phase           TEXT NOT NULL,
          attempt         INTEGER NOT NULL,
          kind            TEXT NOT NULL,
          name            TEXT NOT NULL,
          state           TEXT NOT NULL,
          fault           TEXT NOT NULL DEFAULT 'none',
          summary         TEXT NOT NULL DEFAULT '',
          remedy          TEXT,
          subject_hash    TEXT,
          exit_code       INTEGER,
          invocation      TEXT,
          started_at      TEXT,
          duration_ms     INTEGER,
          adjudicated_at  TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS check_by_run   ON check_result (run_id, phase, name);
        CREATE INDEX IF NOT EXISTS check_by_state ON check_result (run_id, state);

        CREATE TABLE IF NOT EXISTS finding (
          check_id  INTEGER NOT NULL REFERENCES check_result (id) ON DELETE CASCADE,
          seq       INTEGER NOT NULL,
          file      TEXT,
          line      INTEGER,
          rule      TEXT,
          message   TEXT,
          detail    TEXT
        );
        CREATE INDEX IF NOT EXISTS finding_by_check ON finding (check_id);

        CREATE TABLE IF NOT EXISTS transcript (
          check_id  INTEGER NOT NULL REFERENCES check_result (id) ON DELETE CASCADE,
          stream    TEXT NOT NULL,
          content   TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS transcript_by_check ON transcript (check_id);

        CREATE TABLE IF NOT EXISTS tool_call (
          run_id   TEXT NOT NULL,
          phase    TEXT,
          tool     TEXT NOT NULL,
          outcome  TEXT NOT NULL,
          at       TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS tool_call_by_run ON tool_call (run_id, phase, tool);

        CREATE TABLE IF NOT EXISTS grounding_row (
          run_id    TEXT NOT NULL,
          phase     TEXT NOT NULL,
          tier      TEXT NOT NULL,
          asked     TEXT NOT NULL DEFAULT '',
          found     TEXT NOT NULL DEFAULT '',
          citation  TEXT NOT NULL DEFAULT '',
          resolved  INTEGER NOT NULL DEFAULT 0,
          store     TEXT NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS grounding_by_run ON grounding_row (run_id, phase, tier);

        CREATE TABLE IF NOT EXISTS declaration (
          run_id       TEXT NOT NULL,
          phase        TEXT NOT NULL,
          kind         TEXT NOT NULL,
          value        TEXT NOT NULL,
          declared_at  TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS declaration_by_run ON declaration (run_id, phase, kind);

        CREATE TABLE IF NOT EXISTS seeker_finding (
          run_id    TEXT NOT NULL,
          phase     TEXT NOT NULL,
          round     INTEGER NOT NULL,
          ref       TEXT NOT NULL,
          severity  TEXT NOT NULL,
          location  TEXT NOT NULL DEFAULT '',
          finding   TEXT NOT NULL DEFAULT '',
          status    TEXT NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS seeker_by_run ON seeker_finding (run_id, round);
        SQL);
    }
    $pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
  }

  /**
   * Records the run itself, or updates what is already known about it.
   *
   * @param string $runId
   *   The run.
   * @param array<string, string|null> $facts
   *   Any of started_at, preset, mode, enforcement, base_commit, spec_path,
   *   spec_hash, spec_frozen_at, spec_text.
   */
  public function upsertRun(string $runId, array $facts): void {
    $allowed = [
      'started_at', 'preset', 'mode', 'enforcement', 'base_commit',
      'spec_path', 'spec_hash', 'spec_frozen_at', 'spec_text',
    ];
    $facts = array_intersect_key($facts, array_flip($allowed));
    $pdo = $this->connection();
    $pdo->prepare('INSERT OR IGNORE INTO run (run_id, started_at) VALUES (?, ?)')
      ->execute([$runId, $facts['started_at'] ?? date('c')]);
    if ($facts === []) {
      return;
    }
    // A NULL never overwrites something already known: the run row is filled in
    // by several surfaces at different moments, and the spec is frozen long
    // after the run opens.
    $sets = [];
    $args = [];
    foreach ($facts as $column => $value) {
      if ($value === NULL) {
        continue;
      }
      $sets[] = $column . ' = ?';
      $args[] = $value;
    }
    if ($sets === []) {
      return;
    }
    $args[] = $runId;
    $pdo->prepare('UPDATE run SET ' . implode(', ', $sets) . ' WHERE run_id = ?')->execute($args);
  }

  /**
   * Stores one adjudicated check, with its findings.
   *
   * Append-only. A retried gate gets a NEW row with the next attempt number,
   * which is how "it failed, we fixed it, it passed" stays legible — the old
   * record overwrote the failing attempt and kept only a counter, so a run
   * could prove the feedback loop fired and never what it corrected.
   *
   * @param string $runId
   *   The run.
   * @param string $phase
   *   The phase.
   * @param \Droost\Workflow\Evidence\CheckRecord $check
   *   The adjudicated item.
   * @param string|null $now
   *   The timestamp, ISO-8601. Defaults to now.
   *
   * @return int
   *   The stored row's id, for attaching a transcript.
   */
  public function record(string $runId, string $phase, CheckRecord $check, ?string $now = NULL): int {
    $pdo = $this->connection();
    $next = $pdo->prepare('SELECT COALESCE(MAX(attempt), 0) + 1 FROM check_result WHERE run_id = ? AND phase = ? AND kind = ? AND name = ?');
    $next->execute([$runId, $phase, $check->kind, $check->name]);
    $attempt = (int) $next->fetchColumn();

    $statement = $pdo->prepare(
      'INSERT INTO check_result
        (run_id, phase, attempt, kind, name, state, fault, summary, remedy,
         subject_hash, exit_code, invocation, started_at, duration_ms, adjudicated_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([
      $runId,
      $phase,
      $attempt,
      $check->kind,
      $check->name,
      $check->state->value,
      $check->fault->value,
      $check->summary,
      $check->remedy,
      $check->subjectHash,
      $check->exitCode,
      $check->invocation,
      $check->startedAt,
      $check->durationMs,
      $now ?? date('c'),
    ]);
    $id = (int) $pdo->lastInsertId();
    $this->recordFindings($pdo, $id, $check->findings);
    $this->recordTranscript($pdo, $id, $check->stdout, $check->stderr);

    return $id;
  }

  /**
   * Flattens a gate's findings into rows.
   *
   * The shapes are not uniform — six are in production, from
   * `['key','detail']` to `['file','line','rule','message']` — so this keeps
   * the four columns a reader can sort by and puts whatever is left in
   * `detail` as JSON rather than losing it.
   *
   * @param \PDO $pdo
   *   The connection.
   * @param int $checkId
   *   The owning check.
   * @param list<array<string, mixed>> $findings
   *   The findings.
   */
  private function recordFindings(\PDO $pdo, int $checkId, array $findings): void {
    if ($findings === []) {
      return;
    }
    $statement = $pdo->prepare('INSERT INTO finding (check_id, seq, file, line, rule, message, detail) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach (array_values($findings) as $seq => $finding) {
      if (!is_array($finding)) {
        $finding = ['detail' => $finding];
      }
      $known = ['file', 'line', 'rule', 'message'];
      $rest = array_diff_key($finding, array_flip($known));
      $statement->execute([
        $checkId,
        $seq,
        isset($finding['file']) && is_scalar($finding['file']) ? (string) $finding['file'] : NULL,
        isset($finding['line']) && is_numeric($finding['line']) ? (int) $finding['line'] : NULL,
        isset($finding['rule']) && is_scalar($finding['rule']) ? (string) $finding['rule'] : NULL,
        isset($finding['message']) && is_scalar($finding['message']) ? (string) $finding['message'] : NULL,
        $rest === [] ? NULL : json_encode($rest, JSON_UNESCAPED_SLASHES),
      ]);
    }
  }

  /**
   * Stores what the tool actually said, beside the verdict.
   *
   * Separate rows from the findings on purpose: findings are what droost
   * PARSED and a report can count, while this is what the tool said — the only
   * thing that helps when the parse was wrong, when the tool died before
   * producing anything structured, or when a reader does not believe the
   * summary. Already capped by the time it arrives.
   *
   * @param \PDO $pdo
   *   The connection.
   * @param int $checkId
   *   The owning check.
   * @param string $stdout
   *   What it wrote to stdout.
   * @param string $stderr
   *   What it wrote to stderr.
   */
  private function recordTranscript(\PDO $pdo, int $checkId, string $stdout, string $stderr): void {
    $statement = $pdo->prepare('INSERT INTO transcript (check_id, stream, content) VALUES (?, ?, ?)');
    foreach (['stdout' => $stdout, 'stderr' => $stderr] as $stream => $content) {
      if ($content !== '') {
        $statement->execute([$checkId, $stream, $content]);
      }
    }
  }

  /**
   * What a phase's tools said, for reading back later.
   *
   * @param string $runId
   *   The run.
   * @param string $phase
   *   The phase.
   *
   * @return list<array<string, mixed>>
   *   One row per stream, with the check it belongs to.
   */
  public function transcripts(string $runId, string $phase): array {
    $statement = $this->connection()->prepare(
      'SELECT c.name, c.attempt, c.state, t.stream, t.content
         FROM transcript t JOIN check_result c ON c.id = t.check_id
        WHERE c.run_id = ? AND c.phase = ?
        ORDER BY c.name, c.attempt, t.stream'
    );
    $statement->execute([$runId, $phase]);

    return $statement->fetchAll() ?: [];
  }

  /**
   * The latest adjudication of every item in a phase.
   *
   * @param string $runId
   *   The run.
   * @param string $phase
   *   The phase.
   *
   * @return list<array<string, mixed>>
   *   One row per item, newest attempt only.
   */
  public function checklist(string $runId, string $phase): array {
    $statement = $this->connection()->prepare(
      'SELECT c.* FROM check_result c
        JOIN (SELECT kind, name, MAX(attempt) AS attempt
                FROM check_result WHERE run_id = ? AND phase = ?
               GROUP BY kind, name) latest
          ON c.kind = latest.kind AND c.name = latest.name AND c.attempt = latest.attempt
       WHERE c.run_id = ? AND c.phase = ?
       ORDER BY c.kind, c.name'
    );
    $statement->execute([$runId, $phase, $runId, $phase]);

    return $statement->fetchAll() ?: [];
  }

  /**
   * The items of a phase that stop it ending.
   *
   * This is what the guard hook asks before it lets a turn end.
   *
   * @param string $runId
   *   The run.
   * @param string $phase
   *   The phase.
   *
   * @return list<array<string, mixed>>
   *   The blocking rows, with their faults and remedies.
   */
  public function unresolved(string $runId, string $phase): array {
    return array_values(array_filter(
      $this->checklist($runId, $phase),
      static fn (array $row): bool => (CheckState::tryFrom((string) $row['state']) ?? CheckState::Pending)->blocksAdvance(),
    ));
  }

  /**
   * Whether a satisfied check still describes the code as it stands.
   *
   * A green recorded against a fingerprint is only a green while the
   * fingerprint holds. This is the difference between droost determining that
   * something is green and droost reading a stored TRUE.
   *
   * @param string $runId
   *   The run.
   * @param string $phase
   *   The phase.
   * @param string $name
   *   The item.
   * @param string $subjectHash
   *   The fingerprint as it is now.
   *
   * @return bool
   *   TRUE when the newest adjudication is satisfied AND was about this code.
   */
  public function stillGreen(string $runId, string $phase, string $name, string $subjectHash): bool {
    $statement = $this->connection()->prepare(
      'SELECT state, subject_hash FROM check_result
        WHERE run_id = ? AND phase = ? AND name = ?
        ORDER BY attempt DESC LIMIT 1'
    );
    $statement->execute([$runId, $phase, $name]);
    $row = $statement->fetch();
    if (!is_array($row)) {
      return FALSE;
    }

    return (string) $row['state'] === CheckState::Satisfied->value
      && is_string($row['subject_hash'])
      && hash_equals($row['subject_hash'], $subjectHash);
  }

  /**
   * Records one declared file or test.
   *
   * @param string $runId
   *   The run.
   * @param string $phase
   *   The phase declaring it.
   * @param string $kind
   *   Either 'file' or 'test'.
   * @param string $value
   *   The path, class or method.
   * @param string|null $at
   *   The timestamp, ISO-8601.
   */
  public function declare(string $runId, string $phase, string $kind, string $value, ?string $at = NULL): void {
    $this->connection()
      ->prepare('INSERT INTO declaration (run_id, phase, kind, value, declared_at) VALUES (?, ?, ?, ?, ?)')
      ->execute([$runId, $phase, $kind, $value, $at ?? date('c')]);
  }

  /**
   * What a run declared, by kind.
   *
   * Across the whole run, not one phase: a declaration made while planning is
   * what the code phase is audited against, and asking only about the current
   * phase would find nothing.
   *
   * @param string $runId
   *   The run.
   * @param string $kind
   *   Either 'file' or 'test'.
   *
   * @return list<string>
   *   The declared values, deduplicated.
   */
  public function declared(string $runId, string $kind): array {
    $statement = $this->connection()
      ->prepare('SELECT DISTINCT value FROM declaration WHERE run_id = ? AND kind = ? ORDER BY value');
    $statement->execute([$runId, $kind]);

    return array_map(static fn (array $row): string => (string) $row['value'], $statement->fetchAll() ?: []);
  }

  /**
   * The tests a run's phpunit gate actually executed, as far as it can tell.
   *
   * Read from the invocation and the summary of every phpunit-shaped check,
   * which is all the store holds about a suite run. Deliberately coarse: the
   * audit only asks whether a promised test appears anywhere in what ran, and
   * a false MATCH is far cheaper than a false accusation of dropping coverage.
   *
   * @param string $runId
   *   The run.
   *
   * @return list<string>
   *   Text in which a declared test may be looked for.
   */
  public function ranTests(string $runId): array {
    $statement = $this->connection()->prepare(
      'SELECT summary, invocation FROM check_result
        WHERE run_id = ? AND name IN (\'phpunit\', \'playwright\', \'coverage\', \'mutation\')'
    );
    $statement->execute([$runId]);
    $seen = [];
    foreach ($statement->fetchAll() ?: [] as $row) {
      foreach (['summary', 'invocation'] as $column) {
        if (is_string($row[$column] ?? NULL) && $row[$column] !== '') {
          $seen[] = $row[$column];
        }
      }
    }
    return $seen;
  }

  /**
   * Records one adjudicated grounding row.
   *
   * `store` is the column worth having: it says WHICH store answered, and the
   * asymmetry it exposes is one nothing else in the record can. Core resolves
   * against the brain and never the symbol graph, because `droost:search:index`
   * runs custom|contrib|themes|wiki and leaves core out — so a round claiming a
   * core citation resolved against the symbol graph has a broken probe rather
   * than a finding.
   *
   * @param string $runId
   *   The run.
   * @param string $phase
   *   The phase that adjudicated it.
   * @param string $tier
   *   Either custom, contrib or core.
   * @param string $asked
   *   The question the row recorded, when the caller has it.
   * @param string $found
   *   What the row said came back.
   * @param string $citation
   *   The Evidence cell.
   * @param bool $resolved
   *   Whether it resolved against this site.
   * @param string $store
   *   Which store answered.
   */
  public function recordGroundingRow(
    string $runId,
    string $phase,
    string $tier,
    string $asked,
    string $found,
    string $citation,
    bool $resolved,
    string $store,
  ): void {
    $this->connection()
      ->prepare('INSERT INTO grounding_row (run_id, phase, tier, asked, found, citation, resolved, store) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
      ->execute([$runId, $phase, $tier, $asked, $found, $citation, $resolved ? 1 : 0, $store]);
  }

  /**
   * Clears a phase's grounding rows before they are written again.
   *
   * The gate runs more than once per phase — a failed attempt, a retry — and
   * each run re-adjudicates the whole table. Appending would leave a reader
   * counting the same citation twice and unable to tell which verdict was the
   * last one. This is the one table where the latest reading replaces the
   * previous, because unlike a check it is not an attempt, it is a reading.
   *
   * @param string $runId
   *   The run.
   * @param string $phase
   *   The phase.
   */
  public function clearGrounding(string $runId, string $phase): void {
    $this->connection()
      ->prepare('DELETE FROM grounding_row WHERE run_id = ? AND phase = ?')
      ->execute([$runId, $phase]);
  }

  /**
   * Records one droost tool call, with the phase it happened in.
   *
   * The JSONL ledger records the tool and the outcome and no phase, so its
   * lines cannot be attributed to a phase from the record alone — the eval
   * harness reconstructs that from the host's own transcript, which is
   * evidence about the host rather than about droost. One column fixes it.
   *
   * @param string $runId
   *   The run.
   * @param string|null $phase
   *   The phase, or NULL for a call made before the run opened.
   * @param string $tool
   *   The tool id.
   * @param string $outcome
   *   Either 'ok' or 'fail'.
   * @param string|null $at
   *   The timestamp, ISO-8601.
   */
  public function recordToolCall(string $runId, ?string $phase, string $tool, string $outcome, ?string $at = NULL): void {
    $this->connection()
      ->prepare('INSERT INTO tool_call (run_id, phase, tool, outcome, at) VALUES (?, ?, ?, ?, ?)')
      ->execute([$runId, $phase, $tool, $outcome, $at ?? date('c')]);
  }

  /**
   * How many times each tool was called, optionally within one phase.
   *
   * @param string $runId
   *   The run.
   * @param string|null $phase
   *   The phase, or NULL for the whole run.
   *
   * @return array<string, int>
   *   Tool id to call count, busiest first.
   */
  public function toolTally(string $runId, ?string $phase = NULL): array {
    $sql = 'SELECT tool, COUNT(*) AS n FROM tool_call WHERE run_id = ?';
    $args = [$runId];
    if ($phase !== NULL) {
      $sql .= ' AND phase = ?';
      $args[] = $phase;
    }
    $statement = $this->connection()->prepare($sql . ' GROUP BY tool ORDER BY n DESC, tool');
    $statement->execute($args);
    $tally = [];
    foreach ($statement->fetchAll() ?: [] as $row) {
      $tally[(string) $row['tool']] = (int) $row['n'];
    }

    return $tally;
  }

}
