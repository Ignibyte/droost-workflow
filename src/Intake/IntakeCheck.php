<?php

declare(strict_types=1);

namespace Droost\Workflow\Intake;

/**
 * What an intake must hold before the human can approve it.
 *
 * Six checks over the files in `droost/intake/`, each finding naming what to
 * do. None of them judges whether a decision is GOOD: that is the human's at
 * approval, and the observer's after. Each one closes a way the human's part,
 * or droost's, could be skipped or forged:
 *
 *   files      the four files are there, and `audit.json` is the one `intake
 *              audit` wrote (its sha256 is in the state directory)
 *   evidence   every id a decision cites is in the audit
 *   coverage   every route, every repeated structure and form in a page's
 *              main content, every declared set of records and the frame is
 *              decided: cited by a construct, or set aside under `## Not
 *              built` with a reason
 *   consulted  the model, as it stands, was put to droost (`droost_consult`
 *              with `spec=droost/intake/model.md`): a consult row holds its
 *              sha256
 *   answered   every question has the human's answer on record (the guard
 *              writes it from `AskUserQuestion`, the operator from a terminal),
 *              and the file says what the record says
 *   ladder     rungs in order, each consuming one below it, each with its
 *              ticket; every route and the frame built by a rung; every
 *              construct in a rung, and shown only on pages a rung at or
 *              after its own builds
 */
final class IntakeCheck {

  /**
   * The intake's files, by their role.
   */
  public const FILES = [
    'audit' => 'audit.json',
    'model' => 'model.md',
    'questions' => 'questions.md',
    'roadmap' => 'roadmap.md',
  ];

  /**
   * The audit's schema.
   */
  public const AUDIT_SCHEMA = 'droost-source/audit@1';

  /**
   * Structures a page's main content repeats that a decision must cover.
   */
  private const DECIDED_KINDS = ['cards', 'items'];

  /**
   * Runs every check.
   *
   * @param string $projectRoot
   *   The repository.
   * @param \Droost\Workflow\Intake\IntakeState $state
   *   The intake.
   * @param list<array{question: string, answer: string}> $answers
   *   The answers on record for this intake.
   * @param list<array<mixed>> $ledger
   *   The tool-call ledger's rows.
   *
   * @return list<\Droost\Workflow\Intake\IntakeFinding>
   *   What is missing or wrong; empty when the intake may be approved.
   */
  public static function run(string $projectRoot, IntakeState $state, array $answers, array $ledger): array {
    $dir = rtrim($projectRoot, '/') . '/' . IntakeStore::DIR;
    $text = [];
    $findings = [];
    foreach (self::FILES as $role => $file) {
      $raw = @file_get_contents($dir . '/' . $file);
      if ($raw === FALSE) {
        $findings[] = new IntakeFinding('files', sprintf('%s/%s is missing: %s.', IntakeStore::DIR, $file, self::purpose($role)));
        continue;
      }
      $text[$role] = $raw;
    }

    $audit = NULL;
    if (isset($text['audit'])) {
      $decoded = json_decode($text['audit'], TRUE);
      if (!is_array($decoded) || ($decoded['schema'] ?? NULL) !== self::AUDIT_SCHEMA) {
        $findings[] = new IntakeFinding('files', sprintf('%s/audit.json is not a source audit (%s): run `droost-workflow intake audit`.', IntakeStore::DIR, self::AUDIT_SCHEMA));
      }
      elseif ($state->auditSha === NULL) {
        $findings[] = new IntakeFinding('files', 'the audit was not written by `droost-workflow intake audit`, so nothing records that a tool wrote it: run it through the intake.');
      }
      elseif (hash('sha256', $text['audit']) !== $state->auditSha) {
        $findings[] = new IntakeFinding('files', 'audit.json has changed since `intake audit` wrote it: the decisions cite the audit, so it is the tool\'s to write. Run `intake audit` again.');
        $audit = $decoded;
      }
      else {
        $audit = $decoded;
      }
    }

    $plan = isset($text['model']) ? IntakeTables::rows($text['model'], '## Tooling plan') : [];
    $notBuilt = isset($text['model']) ? IntakeTables::rows($text['model'], '## Not built') : [];
    $modelRoutes = isset($text['model']) ? self::modelRoutes($text['model']) : NULL;
    if (isset($text['model'])) {
      if ($plan === []) {
        $findings[] = new IntakeFinding('files', 'model.md has no `## Tooling plan` table: one row per construct, with the columns Construct, What it is, Evidence and Rung.');
      }
      if ($modelRoutes === NULL) {
        $findings[] = new IntakeFinding('files', 'model.md has no `## Routes` section: one line per page the site will serve, `- /path — what it is`.');
      }
      foreach (['construct', 'what it is', 'evidence', 'rung'] as $column) {
        if ($plan !== [] && !array_key_exists($column, $plan[0])) {
          $findings[] = new IntakeFinding('files', sprintf('model.md\'s Tooling plan has no "%s" column.', ucfirst($column)));
        }
      }
    }

    $cited = [];
    foreach (array_merge($plan, $notBuilt) as $row) {
      foreach (IntakeTables::citations($row['evidence'] ?? '') as $id) {
        $cited[$id] = TRUE;
      }
    }
    $setAside = [];
    foreach ($notBuilt as $row) {
      foreach (IntakeTables::citations($row['evidence'] ?? '') as $id) {
        $setAside[$id] = TRUE;
      }
    }

    $rungs = isset($text['roadmap']) ? self::rungs($text['roadmap'], $dir, $projectRoot, $findings) : [];

    if ($audit !== NULL) {
      $ids = self::auditIds($audit);
      foreach (array_keys($cited) as $id) {
        if (!isset($ids[$id])) {
          $findings[] = new IntakeFinding('evidence', sprintf('"%s" is cited and is not in the audit: cite an id audit.md lists.', $id));
        }
      }
      if ($modelRoutes !== NULL) {
        self::coverage($audit, $cited, $setAside, $modelRoutes, $rungs, $findings);
      }
    }

    if (isset($text['model'])) {
      $sha = hash('sha256', $text['model']);
      $consulted = FALSE;
      foreach ($ledger as $row) {
        $detail = is_array($row['detail'] ?? NULL) ? $row['detail'] : [];
        if (($row['tool'] ?? NULL) === 'droost_consult' && ($row['outcome'] ?? NULL) === 'ok' && ($detail['sha'] ?? NULL) === $sha) {
          $consulted = TRUE;
          break;
        }
      }
      if (!$consulted) {
        $findings[] = new IntakeFinding('consulted', 'the model as it stands was never put to droost: call droost_consult with spec=droost/intake/model.md, and again after any change to it.');
      }
    }

    if (isset($text['questions'])) {
      self::answered(IntakeTables::rows($text['questions']), $answers, $findings);
    }

    if ($plan !== [] && $rungs !== []) {
      self::ladder($plan, $rungs, $findings);
    }

    return $findings;
  }

  /**
   * What a file is for, in a finding.
   */
  private static function purpose(string $role): string {
    return match ($role) {
      'audit' => 'run `droost-workflow intake audit --url <served source> --files <source>`',
      'model' => 'the content model: a `## Tooling plan` table (Construct, What it is, Evidence, Rung) and `## Routes`',
      'questions' => 'the Q&A: a table of Id, Question, Recommendation, Answer and Decided by',
      default => 'the roadmap: a table of Rung, Ticket, Builds, Consumes and Pages',
    };
  }

  /**
   * The routes model.md's `## Routes` names, or NULL with no such section.
   *
   * @return list<string>|null
   *   Each path, a refusal's status taken off.
   */
  private static function modelRoutes(string $text): ?array {
    if (preg_match('/^## Routes\b[^\n]*\n(.*?)(?=^#{1,2} |\z)/msi', $text, $m) !== 1) {
      return NULL;
    }
    $routes = [];
    foreach (preg_split('/\R/', $m[1]) ?: [] as $line) {
      $item = (string) preg_replace('/^\s*(?:[-*+]|\d+[.)]|\|)\s*/', '', $line);
      if (preg_match('~^`?(/[^\s`|]*)`?~', $item, $r) === 1) {
        $routes[] = rtrim($r[1], '`');
      }
    }
    return array_values(array_unique($routes));
  }

  /**
   * Every id the audit holds.
   *
   * @param array<mixed> $audit
   *   The audit.
   *
   * @return array<string, true>
   *   The ids.
   */
  private static function auditIds(array $audit): array {
    $ids = ['frame' => TRUE];
    foreach (is_array($audit['routes'] ?? NULL) ? $audit['routes'] : [] as $r) {
      if (is_array($r) && is_string($r['id'] ?? NULL)) {
        $ids[$r['id']] = TRUE;
      }
    }
    foreach (is_array($audit['pages'] ?? NULL) ? $audit['pages'] : [] as $p) {
      if (!is_array($p) || !is_string($p['id'] ?? NULL)) {
        continue;
      }
      $ids[$p['id']] = TRUE;
      foreach (['sections', 'repeats', 'forms'] as $part) {
        foreach (is_array($p[$part] ?? NULL) ? $p[$part] : [] as $x) {
          if (is_array($x) && is_string($x['id'] ?? NULL)) {
            $ids[$x['id']] = TRUE;
          }
        }
      }
    }
    foreach (is_array($audit['data'] ?? NULL) ? $audit['data'] : [] as $d) {
      if (is_array($d) && is_string($d['id'] ?? NULL)) {
        $ids[$d['id']] = TRUE;
      }
    }
    return $ids;
  }

  /**
   * The roadmap's rungs by number, with their pages; findings for its shape.
   *
   * @param string $text
   *   The roadmap's text.
   * @param string $dir
   *   The intake's directory.
   * @param string $projectRoot
   *   The repository.
   * @param list<\Droost\Workflow\Intake\IntakeFinding> $findings
   *   Where findings go.
   *
   * @return array<int, list<string>>
   *   Each rung's pages.
   */
  private static function rungs(string $text, string $dir, string $projectRoot, array &$findings): array {
    $rows = IntakeTables::rows($text);
    if ($rows === []) {
      $findings[] = new IntakeFinding('files', 'roadmap.md holds no table of rungs: Rung, Ticket, Builds, Consumes, Pages.');
      return [];
    }
    foreach (['rung', 'ticket', 'consumes', 'pages'] as $column) {
      if (!array_key_exists($column, $rows[0])) {
        $findings[] = new IntakeFinding('files', sprintf('roadmap.md\'s table has no "%s" column.', ucfirst($column)));
        return [];
      }
    }
    $rungs = [];
    $expected = 1;
    foreach ($rows as $row) {
      $number = IntakeTables::numbers($row['rung'])[0] ?? NULL;
      if ($number === NULL || $number !== $expected) {
        $findings[] = new IntakeFinding('ladder', sprintf('rung "%s" is out of order: rungs are numbered 1, 2, 3 … in the order they run.', $row['rung']));
        $number ??= $expected;
      }
      $expected = $number + 1;
      $consumes = IntakeTables::numbers($row['consumes']);
      if ($number > 1 && $consumes === []) {
        $findings[] = new IntakeFinding('ladder', sprintf('rung %d consumes nothing: each rung builds on one below it, so name the rung(s) it consumes.', $number));
      }
      foreach ($consumes as $below) {
        if ($below >= $number) {
          $findings[] = new IntakeFinding('ladder', sprintf('rung %d consumes rung %d, which is not below it.', $number, $below));
        }
      }
      $ticket = trim(str_replace('`', '', $row['ticket']));
      $ticket = (string) preg_replace('/^\[[^\]]*\]\(([^)]*)\)$/', '$1', $ticket);
      $found = $ticket !== '' && (is_file($dir . '/' . $ticket) || is_file(rtrim($projectRoot, '/') . '/' . $ticket));
      if (!$found) {
        $findings[] = new IntakeFinding('ladder', sprintf('rung %d\'s ticket "%s" is not a file in %s/ (tickets/<id>.md).', $number, $ticket, IntakeStore::DIR));
      }
      $rungs[$number] = IntakeTables::pages($row['pages']);
    }
    return $rungs;
  }

  /**
   * Coverage: every route, structure, form, set of records and the frame.
   *
   * @param array<mixed> $audit
   *   The audit.
   * @param array<string, true> $cited
   *   Every id a construct or a set-aside row cites.
   * @param array<string, true> $setAside
   *   The ids `## Not built` cites.
   * @param list<string> $modelRoutes
   *   The routes model.md names.
   * @param array<int, list<string>> $rungs
   *   Each rung's pages.
   * @param list<\Droost\Workflow\Intake\IntakeFinding> $findings
   *   Where findings go.
   */
  private static function coverage(array $audit, array $cited, array $setAside, array $modelRoutes, array $rungs, array &$findings): void {
    $built = [];
    foreach ($rungs as $pages) {
      foreach ($pages as $page) {
        $built[$page] = TRUE;
      }
    }
    foreach (is_array($audit['routes'] ?? NULL) ? $audit['routes'] : [] as $r) {
      if (!is_array($r) || !is_string($r['path'] ?? NULL) || ($r['notFound'] ?? FALSE) === TRUE) {
        continue;
      }
      $path = $r['path'];
      $pattern = is_string($r['pattern'] ?? NULL) ? $r['pattern'] : NULL;
      $names = $pattern === NULL ? [$path] : [$path, $pattern];
      foreach (is_array($r['instances'] ?? NULL) ? $r['instances'] : [] as $instance) {
        if (is_string($instance)) {
          $names[] = $instance;
        }
      }
      if (isset($setAside['r:' . $path])) {
        continue;
      }
      if (!self::named($names, $modelRoutes)) {
        $findings[] = new IntakeFinding('coverage', sprintf('route %s is not decided: name it under model.md\'s `## Routes` (a pattern, `/events/:id`, names its instances), or set it aside under `## Not built` (cite `r:%s`) with a reason.', $path, $path));
      }
      elseif ($rungs !== [] && !self::named($names, array_keys($built))) {
        $findings[] = new IntakeFinding('coverage', sprintf('route %s is in the model and in no rung\'s Pages: say which rung builds it.', $path));
      }
    }
    foreach (is_array($audit['pages'] ?? NULL) ? $audit['pages'] : [] as $p) {
      if (!is_array($p)) {
        continue;
      }
      foreach (is_array($p['repeats'] ?? NULL) ? $p['repeats'] : [] as $rep) {
        if (is_array($rep) && in_array($rep['kind'] ?? NULL, self::DECIDED_KINDS, TRUE) && ($rep['inside'] ?? NULL) === 'main'
          && is_string($rep['id'] ?? NULL) && !isset($cited[$rep['id']])) {
          $findings[] = new IntakeFinding('coverage', sprintf('%s (%s, %d of them) is not decided: cite it from the construct that renders it, or set it aside under `## Not built`.', $rep['id'], $rep['kind'], is_int($rep['count'] ?? NULL) ? $rep['count'] : 0));
        }
      }
      foreach (is_array($p['forms'] ?? NULL) ? $p['forms'] : [] as $form) {
        if (is_array($form) && is_string($form['id'] ?? NULL) && !isset($cited[$form['id']])) {
          $findings[] = new IntakeFinding('coverage', sprintf('the form %s is not decided: cite it from the construct that builds it, or set it aside.', $form['id']));
        }
      }
    }
    foreach (is_array($audit['data'] ?? NULL) ? $audit['data'] : [] as $d) {
      if (!is_array($d) || !is_string($d['id'] ?? NULL)) {
        continue;
      }
      $content = in_array($d['kind'] ?? NULL, ['records', 'table'], TRUE)
        || (($d['kind'] ?? NULL) === 'api' && ($d['note'] ?? NULL) === NULL);
      if ($content && !isset($cited[$d['id']])) {
        $findings[] = new IntakeFinding('coverage', sprintf('%s (%s) is not decided: cite it from the construct its records become, or set it aside.', $d['id'], is_string($d['kind'] ?? NULL) ? $d['kind'] : 'data'));
      }
    }
    $frame = is_array($audit['frame'] ?? NULL) ? $audit['frame'] : [];
    $count = static function (mixed $part): int {
      $links = is_array($part) ? ($part['links'] ?? NULL) : NULL;
      return is_array($links) ? count($links) : 0;
    };
    $links = $count($frame['header'] ?? NULL) + $count($frame['footer'] ?? NULL);
    if ($links > 0 && !isset($cited['frame'])) {
      $findings[] = new IntakeFinding('coverage', 'the frame (the header\'s and footer\'s menus) is not decided: cite `frame` from the constructs that build it.');
    }
    elseif ($links > 0 && $rungs !== [] && !isset($built['frame']) && !isset($setAside['frame'])) {
      $findings[] = new IntakeFinding('coverage', 'no rung builds the frame: put `frame` in the Pages of the rung that does.');
    }
  }

  /**
   * Whether any of a route's names is among the routes a file names.
   *
   * A named pattern (`/events/:id`, `/events/{id}`, `/events/[id]`) names
   * every path it matches.
   *
   * @param list<string> $names
   *   The route's path, its pattern and its instances.
   * @param list<string> $named
   *   The routes the file names.
   *
   * @return bool
   *   TRUE when one is named.
   */
  private static function named(array $names, array $named): bool {
    if (array_intersect($names, $named) !== []) {
      return TRUE;
    }
    foreach ($named as $route) {
      if (preg_match('~(^|/)(:[^/]+|\{[^/]+\}|\[[^/]+\])~', $route) !== 1) {
        continue;
      }
      $parts = array_map(
        static fn (string $part): string => preg_match('~^(:.+|\{.+\}|\[.+\])$~', $part) === 1 ? '[^/]+' : preg_quote($part, '~'),
        explode('/', $route),
      );
      $regex = '~^' . implode('/', $parts) . '$~';
      foreach ($names as $name) {
        if (preg_match($regex, $name) === 1) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Answered: each question's answer on record, and the file agreeing.
   *
   * @param list<array<string, string>> $rows
   *   The rows of questions.md.
   * @param list<array{question: string, answer: string}> $answers
   *   The answers on record.
   * @param list<\Droost\Workflow\Intake\IntakeFinding> $findings
   *   Where findings go.
   */
  private static function answered(array $rows, array $answers, array &$findings): void {
    if ($rows === []) {
      $findings[] = new IntakeFinding('answered', 'questions.md holds no questions: ask the human what the source cannot say (who edits what, what is real content, what is out of scope), each with your recommendation.');
      return;
    }
    if (!array_key_exists('question', $rows[0]) || !array_key_exists('answer', $rows[0])) {
      $findings[] = new IntakeFinding('files', 'questions.md\'s table needs a Question and an Answer column.');
      return;
    }
    $onRecord = [];
    foreach ($answers as $a) {
      $onRecord[IntakeTables::normal($a['question'])] = $a['answer'];
    }
    foreach ($rows as $i => $row) {
      $id = trim($row['id'] ?? '') !== '' ? trim($row['id']) : 'Q' . ($i + 1);
      $recorded = $onRecord[IntakeTables::normal($row['question'])] ?? NULL;
      if ($recorded === NULL) {
        $findings[] = new IntakeFinding('answered', sprintf('%s has no answer on record: ask it through AskUserQuestion with these exact words, or have the operator record the answer (`droost-workflow intake answer "<question>" "<answer>"`).', $id));
        continue;
      }
      if (!str_contains(IntakeTables::normal($row['answer']), IntakeTables::normal($recorded))) {
        $findings[] = new IntakeFinding('answered', sprintf('%s: the answer on record is "%s", and the file says "%s". The file carries what the human said.', $id, $recorded, $row['answer']));
      }
    }
  }

  /**
   * Ladder: every construct in a rung, shown only where a rung builds it.
   *
   * @param list<array<string, string>> $plan
   *   The Tooling plan's rows.
   * @param array<int, list<string>> $rungs
   *   Each rung's pages.
   * @param list<\Droost\Workflow\Intake\IntakeFinding> $findings
   *   Where findings go.
   */
  private static function ladder(array $plan, array $rungs, array &$findings): void {
    foreach ($plan as $row) {
      $construct = trim($row['construct'] ?? '');
      $rung = IntakeTables::numbers($row['rung'] ?? '')[0] ?? NULL;
      if ($rung === NULL || !isset($rungs[$rung])) {
        $findings[] = new IntakeFinding('ladder', sprintf('"%s" is in no rung: its Rung cell names a rung of the roadmap.', $construct));
        continue;
      }
      $shown = [];
      foreach (IntakeTables::citations($row['evidence'] ?? '') as $id) {
        if (preg_match('~^p:(/[^#]*)~', $id, $m) === 1) {
          $shown[$m[1]] = TRUE;
        }
      }
      foreach (array_keys($shown) as $page) {
        $at = array_keys(array_filter($rungs, static fn (array $pages): bool => in_array($page, $pages, TRUE)));
        if ($at !== [] && max($at) < $rung) {
          $findings[] = new IntakeFinding('ladder', sprintf('"%s" is built in rung %d and shown on %s, which only rung(s) %s build: a page cannot show what a later rung makes, so list %s in rung %d\'s Pages too, or move the construct.', $construct, $rung, $page, implode(', ', $at), $page, $rung));
        }
      }
    }
  }

}
