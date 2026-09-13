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
   * The database's file name, inside whichever state directory a project uses.
   *
   * A NAME rather than a path, and the distinction is not cosmetic. This was a
   * compile-time constant pinned to `droost/droost-workflow`, and on a project
   * still using the legacy `.droost-workflow` the first evidence write CREATED
   * the new directory — which is the exact condition `resolveStateDir()` uses
   * to decide which one is live. The guard hook then flipped to the new dir,
   * found no run.json there, concluded there was no active run, and stood down.
   *
   * One `upsertRun()` silently disarmed the wall. Not by an attacker: on the
   * first gate of any legacy project. It is precisely the "silent, permanent
   * self-disarm" the hook's own comments were written to prevent, arriving
   * through a door nobody was watching.
   */
  public const string FILENAME = 'evidence.sqlite';

  /**
   * The schema this build writes.
   *
   * Migrations are forward-only and additive. A store written by a newer droost
   * is READ rather than refused: the evidence is the point, and a column this
   * build does not know about costs it nothing. A store written by an older one
   * is migrated up in place.
   */
  public const int SCHEMA_VERSION = 3;

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
    return rtrim($projectRoot, '/') . '/'
      . RunStateStore::resolveStateDir($projectRoot) . '/' . self::FILENAME;
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

  /**
   * Brings the schema up to SCHEMA_VERSION, one version at a time.
   *
   * A step ladder rather than a chain of `if ($at < N)`: the last rung of a
   * chain is always true by construction, which is both a lie the analyser can
   * prove and a rung somebody forgets to add when the version moves.
   *
   * @param \PDO $pdo
   *   The connection.
   */
  private function migrate(\PDO $pdo): void {
    $statement = $pdo->query('PRAGMA user_version');
    $at = $statement === FALSE ? 0 : (int) ($statement->fetchColumn() ?: 0);
    if ($at === self::SCHEMA_VERSION) {
      return;
    }
    // A store written by a NEWER build. Returning quietly here — which is what
    // `$at >= SCHEMA_VERSION` used to do — left every later query running
    // against a schema this build does not know, so the run died on "no such
    // column: work_type" with nothing pointing at the cause. Say the actual
    // sentence instead. Never downgrade: the newer build's rows are not this
    // build's to reinterpret.
    if ($at > self::SCHEMA_VERSION) {
      throw new \RuntimeException(sprintf(
        'The evidence store at %s was written at schema v%d and this build of droost/workflow understands v%d. Upgrade droost/workflow, or move that file aside to start a fresh store.',
        self::pathFor($this->projectRoot),
        $at,
        self::SCHEMA_VERSION,
      ));
    }
    for ($version = $at + 1; $version <= self::SCHEMA_VERSION; $version++) {
      match ($version) {
        1 => $this->migrateToV1($pdo),
        2 => $this->migrateToV2($pdo),
        3 => $this->migrateToV3($pdo),
        default => NULL,
      };
      // Stamped per rung, so an interrupted upgrade resumes where it stopped
      // rather than replaying a rung that already ran.
      $pdo->exec('PRAGMA user_version = ' . $version);
    }
  }

  /**
   * V1 — the original schema.
   *
   * @param \PDO $pdo
   *   The connection.
   */
  private function migrateToV1(\PDO $pdo): void {
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
        spec_text       TEXT,
        work_type       TEXT,
        work_type_declared_at TEXT
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
        adjudicated_at  TEXT NOT NULL,
        provider        TEXT
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

  /**
   * V2 — the two joining dimensions the first cut lacked.
   *
   * @param \PDO $pdo
   *   The connection.
   */
  private function migrateToV2(\PDO $pdo): void {
    //
    // `provider` because a contributed check needs to be attributable: with
    // droost_jira contributing its own checks, "show me everything that
    // module asserted" and "this provider's checks all failed — is the
    // provider broken or is the work bad?" are the first two questions
    // anybody asks, and neither is answerable from kind+name alone.
    //
    // `work_type` because the effort dial answers "how hard do you try" and
    // says nothing about WHAT is being built. A content-model ticket runs
    // phpcs over zero PHP files and reports "nothing to analyse" three times
    // — honest, and noise that teaches people to skim.
    foreach ([
      'ALTER TABLE check_result ADD COLUMN provider TEXT',
      'ALTER TABLE run ADD COLUMN work_type TEXT',
      'ALTER TABLE run ADD COLUMN work_type_declared_at TEXT',
    ] as $statement) {
      try {
        $pdo->exec($statement);
      }
      catch (\PDOException $e) {
        // A column this build added to a store some other build already
        // migrated. Additive migrations are idempotent by intent, and
        // SQLite has no ADD COLUMN IF NOT EXISTS.
      }
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS check_by_provider ON check_result (run_id, provider)');
  }

  /**
   * V3 — whether a gate actually examined anything.
   *
   * `GateStatus::Passed` is overloaded. Three code paths return it over a run
   * that examined nothing: a path set resolving to nothing, phpcs exit 16
   * ("No files were checked"), and phpunit discovering no tests. Each labelled
   * itself in prose — one literally says "a labeled pass, not a measurement" —
   * and prose is not a field, so all three landed as ordinary `satisfied` rows.
   *
   * `type_coverage`, the one blocking check derived from "measured", then
   * reported that phpcs, phpstan and phpunit had all measured something on a
   * run
   * where two analysed zero files and the third ran zero tests. The check
   * written to catch that exact case passed in that exact case, while the same
   * store's own report said "1 measured something" — two implementations of one
   * question, and the blocking one was the looser.
   *
   * @param \PDO $pdo
   *   The connection.
   */
  private function migrateToV3(\PDO $pdo): void {
    try {
      $pdo->exec('ALTER TABLE check_result ADD COLUMN measured INTEGER');
    }
    catch (\PDOException $e) {
      // A column another build already added. Additive migrations are
      // idempotent by intent and SQLite has no ADD COLUMN IF NOT EXISTS.
    }
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
      'work_type', 'work_type_declared_at',
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
         subject_hash, exit_code, invocation, started_at, duration_ms, adjudicated_at,
         provider, measured)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
      $check->provider,
      $check->measured === NULL ? NULL : (int) $check->measured,
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

    return self::rows($statement);
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

    return self::rows($statement);
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
      static fn (array $row): bool => (CheckState::tryFrom(self::text($row, 'state')) ?? CheckState::Pending)->blocksAdvance(),
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
    $row = self::rows($statement)[0] ?? NULL;
    if ($row === NULL) {
      return FALSE;
    }

    return self::text($row, 'state') === CheckState::Satisfied->value
      && is_string($row['subject_hash'] ?? NULL)
      && hash_equals((string) $row['subject_hash'], $subjectHash);
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
   * Clears a run's declarations of one kind, so a re-declaration replaces.
   *
   * Insert-only was a trap. `declared()` reads across the whole run, so an
   * agent correcting a bad declaration ADDED a second one and inherited both —
   * and with no `undeclare` verb, a declaration that could not be satisfied had
   * no legal move except abandoning the run. Re-declaring is now the escape it
   * always looked like.
   *
   * @param string $runId
   *   The run.
   * @param string $kind
   *   Either 'file' or 'test'.
   */
  public function clearDeclarations(string $runId, string $kind): void {
    $this->connection()
      ->prepare('DELETE FROM declaration WHERE run_id = ? AND kind = ?')
      ->execute([$runId, $kind]);
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

    return array_map(static fn (array $row): string => self::text($row, 'value'), self::rows($statement));
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
    foreach (self::rows($statement) as $row) {
      foreach (['summary', 'invocation'] as $column) {
        $value = self::text($row, $column);
        if ($value !== '') {
          $seen[] = $value;
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
   * What kind of work this run declared itself to be.
   *
   * @param string $runId
   *   The run.
   *
   * @return \Droost\Workflow\Evidence\WorkType|null
   *   The type, or NULL when none was declared.
   */
  public function workType(string $runId): ?WorkType {
    $statement = $this->connection()->prepare('SELECT work_type FROM run WHERE run_id = ?');
    $statement->execute([$runId]);
    $value = $statement->fetchColumn();

    return is_string($value) && $value !== '' ? WorkType::tryFrom($value) : NULL;
  }

  /**
   * The gates that actually measured something in this run.
   *
   * "Measured" is a stricter claim than "passed": a gate that was off by
   * preset, skipped for want of a site, or passed over an empty path set has
   * not looked at anything. Only `satisfied` and `recorded` rest on a
   * measurement — which is exactly the distinction a work type needs, because
   * a content-model run whose config_clean passed over an empty export has not
   * been checked, whatever colour the report is.
   *
   * @param string $runId
   *   The run.
   *
   * @return list<string>
   *   Gate names, deduplicated.
   */
  public function measuredGates(string $runId): array {
    $measured = array_values(array_filter(
      CheckState::cases(),
      static fn (CheckState $state): bool => $state->measured(),
    ));
    $placeholders = implode(', ', array_fill(0, count($measured), '?'));
    $statement = $this->connection()->prepare(
      // `measured = 0` is the executor saying it examined nothing: a path
      // set that resolved to nothing, phpcs exit 16, phpunit finding no
      // tests. All three are `Passed`, so filtering on the state word alone
      // reported "phpcs, phpstan and phpunit all measured something" about a
      // run that analysed zero files and ran zero tests — the check written
      // to catch that case, passing in that case. NULL is a v2 row, written
      // before the executor could say; it keeps its old reading rather than
      // being called a lie retroactively.
      'SELECT DISTINCT name FROM check_result
        WHERE run_id = ? AND kind = \'gate\' AND state IN (' . $placeholders . ')
          AND (measured IS NULL OR measured = 1)'
    );
    $statement->execute(array_merge(
      [$runId],
      array_map(static fn (CheckState $state): string => $state->value, $measured),
    ));

    return array_map(static fn (array $row): string => self::text($row, 'name'), self::rows($statement));
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
    foreach (self::rows($statement) as $row) {
      $tally[self::text($row, 'tool')] = self::number($row, 'n');
    }

    return $tally;
  }

  /**
   * The rows of an executed statement, in the shape the callers claim.
   *
   * PDO hands back `mixed`, so every caller that reached into a row was
   * reaching into `mixed` and every `(string) $row['name']` was a cast the
   * analyser could not check. One narrowing point instead of thirty casts: a
   * row that is not an array is dropped rather than indexed, which is the
   * behaviour every caller already assumed and none of them stated.
   *
   * @param \PDOStatement $statement
   *   An executed statement.
   *
   * @return list<array<string, mixed>>
   *   The rows.
   */
  private static function rows(\PDOStatement $statement): array {
    $rows = [];
    foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
      if (!is_array($row)) {
        continue;
      }
      $typed = [];
      foreach ($row as $column => $value) {
        $typed[(string) $column] = $value;
      }
      $rows[] = $typed;
    }

    return $rows;
  }

  /**
   * One column of a row as a string.
   *
   * A column holding something unstringable is a column the schema does not
   * have, so the empty string is the honest reading — never a fatal from a
   * cast, and never a silent `Array` either.
   *
   * @param array<string, mixed> $row
   *   The row.
   * @param string $column
   *   The column.
   *
   * @return string
   *   The value.
   */
  private static function text(array $row, string $column): string {
    $value = $row[$column] ?? NULL;

    return is_scalar($value) ? (string) $value : '';
  }

  /**
   * One column of a row as an integer.
   *
   * @param array<string, mixed> $row
   *   The row.
   * @param string $column
   *   The column.
   *
   * @return int
   *   The value, or zero when the column holds nothing countable.
   */
  private static function number(array $row, string $column): int {
    $value = $row[$column] ?? NULL;

    return is_numeric($value) ? (int) $value : 0;
  }

}
