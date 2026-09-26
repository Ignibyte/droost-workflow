<?php

declare(strict_types=1);

namespace Droost\Workflow\Spec;

use Droost\Workflow\Driver\RenderedRoutes;
use Droost\Workflow\State\RunStateStore;

/**
 * What the run requires of its spec, phase by phase.
 *
 * The owner's rule, mechanized: the spec is the living document, and the
 * phases are gated on the parts of it they depend on. Leaving plan requires
 * the tooling plan — the section that maps every deliverable to the surface
 * that builds it (a droost blueprint, drush generate, a composer tool, or
 * hand-written WITH a stated reason), so "exhaust the generators before
 * writing your own" is a checked contract instead of advice. Gating complete
 * requires the realized capture, so a run cannot close having left its own
 * document behind.
 *
 * This class only reads files. Which phases require which sections is
 * decided by the caller (the facade), because that is where phase
 * transitions are already orchestrated.
 */
final class SpecContract {

  /**
   * The section that maps deliverables to the surfaces that build them.
   */
  public const TOOLING_HEADING = '## Tooling plan';

  /**
   * The section that captures what was actually built, at complete.
   */
  public const REALIZED_HEADING = '## Realized';

  /**
   * The section recording what the agent LOOKED UP before it proposed.
   *
   * Grounding was advice and routing was a contract, and usage followed the
   * contract: across 39 graded rounds the build-surface router was called 179
   * times while the codebase knowledge behind it was called six — symbol,
   * graph, module_patterns and deprecations not once. The plan brief asks for
   * both in the same breath, but only one of them produces a row somebody
   * checks. An agent under time pressure does what is graded.
   *
   * So grounding produces a row too. Same mechanism as `## Tooling plan`:
   * a section the phase cannot end without.
   */
  public const GROUNDING_HEADING = '## Grounding';

  /**
   * The three tiers a grounding row must name.
   *
   * They are not interchangeable and a run that only ever consulted one has
   * not grounded — it has confirmed a prior. CUSTOM is what THIS site's own
   * code already does (the wiki, and search over modules/custom and
   * themes/custom); CONTRIB is what the installed modules this site depends
   * on actually offer; CORE is Drupal's own APIs and the patterns it expects.
   *
   * The commonest expensive mistake in the plan phase — building a thing that
   * already exists under another name — is a CUSTOM miss. The second
   * commonest, reimplementing what a contrib module already gives you, is a
   * CONTRIB miss. Neither is caught by knowing core well.
   */
  public const TIERS = ['custom', 'contrib', 'core'];

  /**
   * The section holding the acceptance-criteria table.
   */
  public const ACCEPTANCE_HEADING = '## Acceptance criteria';

  /**
   * The section naming the paths the ticket adds or changes.
   *
   * Rendered_check is the pipeline's artefacts-are-truth leg and is on at
   * every preset — and for three live rounds it rendered `/` while the ticket
   * built `/camps`, `/rinks`, `/private-lessons`. Mid-way through one code
   * phase the front page was 200 and `/camps` was 500; the gate would have
   * passed. Nothing connected the run's own route to the gate that exists to
   * render it, because the only source of routes was a lever an operator
   * writes once per project (F-15).
   *
   * So the spec names them. The plan gate requires the section, the drivers
   * render the union of the lever's routes and the spec's, and the record
   * says which source each route came from. A ticket that touches no route
   * says so — `none — <why>` — so "declared none" is distinguishable from
   * "nobody asked".
   */
  public const ROUTES_HEADING = '## Routes';

  /**
   * The table column that ties each criterion to the test that proves it.
   *
   * The traceability link: filled at the test phase with the PHPUnit method
   * or class, the Playwright spec, or `manual — <reason>` for a criterion no
   * test can prove. Complete refuses to gate while any cell is empty.
   */
  public const VERIFIED_COLUMN = 'Verified By';

  /**
   * Where run documents live, relative to the project root.
   *
   * The default; a project still on the legacy hidden dir is honoured by
   * RunStateStore::resolveStateDir(), which the discovery below routes through.
   */
  public const STATE_DIR = RunStateStore::STATE_DIR;

  /**
   * What a cell says when nobody has answered it yet, in any casing.
   *
   * Both tables have a column somebody must fill, and each had grown its own
   * idea of what an unfilled cell looks like. The criteria table lowercased
   * against seven spellings; grounding compared case-sensitively against five,
   * so `Tbd`, `N/A`, `TODO` and the en-dash all read as real answers there —
   * an empty cell dressed up enough to pass. One list and one comparison, so
   * the two cannot drift apart again.
   */
  private const PLACEHOLDERS = ['—', '–', '-', 'tbd', 'todo', 'n/a', 'pending'];

  /**
   * What a filled `Verified By` cell has to look like: a pointer at a test.
   *
   * Not a rule about prose quality — a rule about POINTING. Anything that
   * was not a placeholder scored as verified, so a cell reading "I tested it
   * by hand" closed a run with the traceability link written as prose, which
   * is the column doing the opposite of its job: it looks filled from the
   * report and leads nowhere from the spec. A cell earns `verified` by naming
   * something a reader can open — a `Class::method`, a class whose name ends
   * `Test`, a path through a test directory, or a test file. A cell reading
   * `manual — <reason>` is the honest other answer, and classifies as manual
   * rather than as passed.
   */
  private const TEST_REFERENCE = '~
      [A-Za-z_\\\\][\w\\\\]*::[A-Za-z_]\w*   # Class::method, namespaced or not
    | [A-Za-z_]\w*Test\b                     # a class whose name ends Test
    | \btests?/[\w.-]                        # a path through a test directory
    | [\w./\\\\-]+\.(?:php|feature)\b        # a PHP or Gherkin file
    | [\w./-]+\.(?:spec|test|cy)\.[jt]sx?\b  # a JS or TS spec file
  ~x';

  /**
   * Resolves which spec governs a run.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string|null $declared
   *   The --spec value, when one was given. Absolute, or project-relative.
   * @param string|null $recorded
   *   The path the run state already holds, when it holds one.
   *
   * @return string
   *   The governing spec, as a project-relative path.
   *
   * @throws \Droost\Workflow\Spec\SpecError
   *   When nothing resolves, the file is missing, or the declaration
   *   contradicts the record.
   */
  public static function resolve(
    string $projectRoot,
    ?string $declared,
    ?string $recorded = NULL,
  ): string {
    $root = rtrim($projectRoot, '/');

    if ($declared !== NULL && $declared !== '') {
      $relative = self::relative($root, $declared);
      if ($recorded !== NULL && $recorded !== $relative) {
        throw SpecError::conflict($recorded, $relative);
      }
      if (!is_file($root . '/' . $relative)) {
        throw SpecError::missing($relative);
      }
      return $relative;
    }

    if ($recorded !== NULL) {
      if (!is_file($root . '/' . $recorded)) {
        throw SpecError::missing($recorded);
      }
      return $recorded;
    }

    // Nothing declared and nothing recorded: adopt the spec only when the
    // choice is unambiguous. One candidate is an adoption; several are a
    // guess, and a run governed by a guessed document is worse than a
    // refusal that names the fix.
    //
    // BOTH shapes the pack writes count as candidates. A high/xhigh/max run
    // writes `spec-<slug>.md`; a medium/low run writes the quasi-spec to
    // `tmp-spec-<slug>.md`, and a glob that had never heard of that name told
    // a light run there was "no spec found" for a file its own plan phase had
    // just written. Two live medium runs only survived it because begin() had
    // already recorded the path — re-resolution would have refused.
    $stateDir = RunStateStore::resolveStateDir($root);
    $candidates = self::candidates($root);
    if (count($candidates) === 1) {
      $only = self::relative($root, $candidates[0]);
      // THE TIDY STATE IS THE DANGEROUS ONE. `reset` archives run.json and
      // leaves the spec where it is, so the next bare `run` found exactly one
      // candidate and adopted it — the FINISHED ticket's spec, silently, as the
      // contract governing a new ticket. At two or more leftover specs the
      // refusal above already fires and the operator picks; at exactly one
      // there was nothing to pick and nothing said so. A reviewer drove three
      // tickets through one repository and the second inherited the first's
      // acceptance criteria without a word.
      //
      // Adopting is still right for a spec nobody has used. Only a spec a
      // previous run already carried to its end is refused, and the refusal
      // names both ways forward — because re-running the SAME ticket after a
      // reset is a real thing to want, and it is one flag away.
      if (in_array($only, self::specsAlreadyGoverned($root, $stateDir), TRUE)) {
        throw SpecError::alreadyGoverned($only, $stateDir);
      }

      return $only;
    }
    throw SpecError::unresolvable($stateDir, count($candidates));
  }

  /**
   * The spec files the state directory holds, in both shapes the pack writes.
   *
   * Public so the facade can ask "is there anything to adopt yet?" before
   * asking resolve() to adopt it — a bare `run` on a project whose plan phase
   * has not written its spec is a run OPENING, not a run failing (F-25).
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return list<string>
   *   Absolute paths to `spec-*.md` and `tmp-spec-*.md` under the state dir.
   */
  public static function candidates(string $projectRoot): array {
    $dir = rtrim($projectRoot, '/') . '/' . RunStateStore::resolveStateDir(rtrim($projectRoot, '/'));

    return array_merge(
      glob($dir . '/spec-*.md') ?: [],
      glob($dir . '/tmp-spec-*.md') ?: [],
    );
  }

  /**
   * The specs that archived runs were governed by.
   *
   * Read from the history directory `reset` writes, which is the only record
   * that a spec has already had a run of its own. Failures are silent by
   * design: an unreadable archive must not stop a new run from starting, and
   * the consequence of missing one is the old behaviour, not a worse one.
   *
   * @param string $root
   *   The project root.
   * @param string $stateDir
   *   The resolved state directory, project-relative.
   *
   * @return list<string>
   *   Project-relative spec paths.
   */
  private static function specsAlreadyGoverned(string $root, string $stateDir): array {
    $seen = [];
    foreach (glob($root . '/' . $stateDir . '/history/*.json') ?: [] as $archived) {
      $decoded = json_decode((string) @file_get_contents($archived), TRUE);
      if (!is_array($decoded)) {
        continue;
      }
      $spec = $decoded['spec_path'] ?? NULL;
      if (is_string($spec) && $spec !== '') {
        $seen[] = $spec;
      }
    }

    return array_values(array_unique($seen));
  }

  /**
   * Requires a heading to be present in the governing spec.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $spec
   *   The governing spec, project-relative.
   * @param string $heading
   *   The required heading.
   * @param string $why
   *   The refusal's explanation-plus-remedy.
   *
   * @throws \Droost\Workflow\Spec\SpecError
   *   When the file is gone or the section is absent.
   */

  /**
   * Whether a section is present, without throwing about it.
   *
   * `requireSection()`'s sibling, for callers that RECORD what the document
   * says rather than refusing over it (F-35). Same matching rules: heading at
   * line start, trailing words on the line tolerated, a deeper level not
   * accepted, and a heading quoted inside a fence invisible because `read()`
   * has already blanked it.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $spec
   *   The spec, project-relative.
   * @param string $heading
   *   The heading to look for, e.g. `## Tooling plan`.
   *
   * @return bool
   *   TRUE when the section is there. FALSE when it is not, and also when the
   *   spec cannot be read — a caller that only records has nothing to say
   *   about an unreadable file that the phase audit does not already say.
   */
  public static function hasSection(string $projectRoot, string $spec, string $heading): bool {
    $text = self::read(rtrim($projectRoot, '/') . '/' . $spec);
    if ($text === NULL) {
      return FALSE;
    }

    return preg_match('/^' . preg_quote($heading, '/') . '\b/mi', $text) === 1;
  }

  /**
   * Requires a section, throwing when it is absent.
   *
   * Kept for callers that genuinely want the refusal. Phase A's spec-shape
   * recorder uses hasSection() instead (F-35).
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $spec
   *   The spec, project-relative.
   * @param string $heading
   *   The heading required.
   * @param string $why
   *   What the section is for, quoted in the refusal.
   *
   * @throws \Droost\Workflow\Spec\SpecError
   *   When the spec cannot be read or the section is absent.
   */
  public static function requireSection(
    string $projectRoot,
    string $spec,
    string $heading,
    string $why,
  ): void {
    $text = self::read(rtrim($projectRoot, '/') . '/' . $spec);
    if ($text === NULL) {
      throw SpecError::missing($spec);
    }
    // Heading match at line start, tolerant of trailing words on the same
    // line ("## Tooling plan (all surfaces exhausted)") but never of a
    // deeper heading level standing in for the required one — nor of a
    // heading QUOTED inside a fenced block, which read() has already blanked.
    $pattern = '/^' . preg_quote($heading, '/') . '\b/mi';
    if (preg_match($pattern, $text) !== 1) {
      throw SpecError::sectionMissing($spec, $heading, $why);
    }
  }

  /**
   * Whether the realized capture exists — section, or companion file.
   *
   * The pack wrote captures to a sibling `realized-<slug>.md` before the
   * section moved into the spec itself; a run mid-transition satisfies the
   * contract either way, and the section is the documented form.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $spec
   *   The governing spec, project-relative.
   *
   * @return bool
   *   TRUE when either form exists.
   */
  public static function hasRealizedCapture(
    string $projectRoot,
    string $spec,
  ): bool {
    $root = rtrim($projectRoot, '/');
    $text = self::read($root . '/' . $spec);
    if ($text !== NULL
      && preg_match('/^' . preg_quote(self::REALIZED_HEADING, '/') . '\b/mi', $text) === 1) {
      return TRUE;
    }
    // The companion is named for the slug, and a light run's spec carries the
    // same slug behind a `tmp-` prefix — so the derivation reads through it
    // rather than failing to match and reporting no capture at all.
    $companion = preg_replace('~/(?:tmp-)?spec-([^/]+)\.md$~', '/realized-$1.md', '/' . $spec);
    return is_string($companion) && is_file($root . $companion);
  }

  /**
   * What the run looked up, per phase and per tier.
   *
   * Reads the first markdown table under `## Grounding`. Every row names the
   * phase that did the looking, the tier it reached into, what was asked, and
   * what came back. A row whose "Found" cell is empty is not grounding — it is
   * a claim to have looked — and is reported unanswered.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $spec
   *   The governing spec, project-relative.
   *
   * @return array{rows: int, phases: array<string, list<string>>, unanswered: list<string>, uncited: list<string>, evidence: list<array{row: int, phase: string, tier: string, cite: string}>}|null
   *   Rows counted, the tiers each phase reached, the rows whose Found cell is
   *   empty, the rows citing no evidence, and every citation for a site-side
   *   gate to RESOLVE. NULL when the spec has no grounding table at all.
   */
  public static function grounding(string $projectRoot, string $spec): ?array {
    $text = self::read(rtrim($projectRoot, '/') . '/' . $spec);
    if ($text === NULL) {
      return NULL;
    }
    $heading = preg_quote(self::GROUNDING_HEADING, '/');
    if (preg_match('/^' . $heading . '\b[^\n]*\n(.*?)(?=^#{1,2} |\z)/msi', $text, $m) !== 1) {
      return NULL;
    }
    $rows = [];
    foreach (preg_split('/\R/', $m[1]) ?: [] as $line) {
      $line = trim($line);
      if ($line !== '' && $line[0] === '|') {
        $rows[] = $line;
      }
    }
    if (count($rows) < 2) {
      return NULL;
    }
    $header = self::cells($rows[0]);
    $idx = ['phase' => NULL, 'tier' => NULL, 'found' => NULL, 'evidence' => NULL];
    foreach ($header as $i => $cell) {
      $key = strtolower(trim($cell));
      if ($key === 'phase') {
        $idx['phase'] = $i;
      }
      elseif ($key === 'tier') {
        $idx['tier'] = $i;
      }
      elseif (in_array($key, ['found', 'what came back', 'answer'], TRUE)) {
        $idx['found'] = $i;
      }
      elseif (in_array($key, ['evidence', 'cite', 'reference'], TRUE)) {
        $idx['evidence'] = $i;
      }
    }
    $phases = [];
    $unanswered = [];
    $uncited = [];
    $evidence = [];
    $counted = 0;
    foreach (array_slice($rows, 1) as $n => $row) {
      $cells = self::cells($row);
      if ($cells === [] || preg_match('/^[\s\-:|]+$/', $row) === 1) {
        continue;
      }
      $counted++;
      $phase = $idx['phase'] !== NULL ? strtolower(trim($cells[$idx['phase']] ?? '')) : '';
      $tier = $idx['tier'] !== NULL ? strtolower(trim($cells[$idx['tier']] ?? '')) : '';
      $found = $idx['found'] !== NULL ? trim($cells[$idx['found']] ?? '') : '';
      if ($phase !== '' && in_array($tier, self::TIERS, TRUE)) {
        $phases[$phase] ??= [];
        if (!in_array($tier, $phases[$phase], TRUE)) {
          $phases[$phase][] = $tier;
        }
      }
      if (self::isPlaceholder($found)) {
        $unanswered[] = 'row ' . ($n + 1);
      }
      $cite = $idx['evidence'] !== NULL ? trim($cells[$idx['evidence']] ?? '') : '';
      if (self::isPlaceholder($cite)) {
        $uncited[] = 'row ' . ($n + 1);
      }
      else {
        // The phase rides with the citation. A `none:` row is an assertion of
        // absence, and the code phase's whole job is to end that absence — so
        // the gate has to know WHEN the claim was made to judge it fairly, and
        // this is the only place that knows. Without it, `none: rink` written
        // at plan failed at code because the run had built `rink`, which is
        // the run doing what it was asked (F-18).
        $evidence[] = ['row' => $n + 1, 'phase' => $phase, 'tier' => $tier, 'cite' => $cite];
      }
    }

    return [
      'rows' => $counted,
      'phases' => $phases,
      'unanswered' => $unanswered,
      'uncited' => $uncited,
      'evidence' => $evidence,
    ];
  }

  /**
   * The routes the spec declares for rendered_check to render.
   *
   * Reads the section under `## Routes`. A route is the first `/`-prefixed
   * token on a list item or table row — backticks stripped, so `- /camps —
   * the listing` is a path with a reason and `| /camps | listing |` is the
   * same in table form. A line reading `none` (with an optional reason)
   * declares that the ticket touches no route. Header and separator rows are
   * not routes; nor is prose.
   *
   * THIS SECTION READS ITS FENCES, and it is the only one that does (F-34).
   * `read()` blanks every fenced block before anything is parsed, so that a
   * Tooling plan showing a sample command is never read as a real
   * declaration — right for every section that mixes prose with examples.
   * `## Routes` does not mix: it is a list of paths and nothing else, so a
   * fence in it is never an example and there is nothing to protect against.
   *
   * The cost of blanking it was measured. The plan skill asks for the paths
   * "one per line", the natural markdown for that is a fenced block, and
   * **two of Part 3's three specs wrote one** — declaring nothing. `P3-T1`
   * fenced `/rinks` and had `/node/{nid}` harvested from a prose sentence
   * instead, so the gate rendered a 404 while the page the ticket existed to
   * build was never requested. `P3-T2` fenced seven paths and declared two by
   * accident, from prose lines that happened to begin with a backticked path.
   * Not one fenced spec got what it asked for.
   *
   * The SECTION BOUNDARY is still found in the blanked text, and that matters:
   * `read()` emits one line per input line, so the two texts align
   * line-for-line, and a `#` heading inside a fence cannot end the section
   * early the way it would if the raw text were scanned for boundaries.
   * Anchoring (F-32) is what keeps prose out: a route is a line that IS a
   * path, never a line that mentions one.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $spec
   *   The governing spec, project-relative.
   *
   * @return array{routes: list<string>, none: bool}|null
   *   The declared paths, deduplicated in order, and whether the section
   *   declared none. NULL when the spec has no such section. A section that
   *   is present but names neither a route nor `none` returns an empty list
   *   with `none` FALSE — the caller decides that this is undeclared.
   */
  public static function routes(string $projectRoot, string $spec): ?array {
    $path = rtrim($projectRoot, '/') . '/' . $spec;
    $text = self::read($path);
    if ($text === NULL) {
      return NULL;
    }
    $heading = preg_quote(self::ROUTES_HEADING, '/');
    if (preg_match('/^' . $heading . '\b[^\n]*\n(.*?)(?=^#{1,2} |\z)/msi', $text, $m, PREG_OFFSET_CAPTURE) !== 1) {
      return NULL;
    }
    // The same lines, unblanked. Counted rather than re-matched: the blanked
    // and raw texts have the same line count by construction (read() emits
    // one line per input line), so the section's line range transfers exactly
    // and the boundary stays fence-safe.
    $raw = @file_get_contents($path);
    $lines = $raw === FALSE
      ? (preg_split('/\R/', (string) $m[1][0]) ?: [])
      : array_slice(
        preg_split('/\R/', $raw) ?: [],
        substr_count(substr($text, 0, (int) $m[1][1]), "\n"),
        max(0, count(preg_split('/\R/', (string) $m[1][0]) ?: []) - 1),
      );
    $routes = [];
    $none = FALSE;
    foreach ($lines as $line) {
      $line = trim($line);
      // The fence's own delimiter lines, which are not routes. Everything
      // BETWEEN them is, because this section is nothing but paths — see the
      // docblock. Anchoring is what keeps prose out, not blanking.
      if (preg_match('/^(?:`{3,}|~{3,})/', $line) === 1) {
        continue;
      }
      if ($line === '' || preg_match('/^[\s\-:|]+$/', $line) === 1) {
        continue;
      }
      // Strip the list marker or the table's leading pipe so the first cell
      // is what gets read; a table's header row has no `/` and falls through.
      $item = (string) preg_replace('/^(?:[-*+]|\d+[.)]|\|)\s*/', '', $line);
      if (preg_match('/^`?none`?\b/i', $item) === 1) {
        $none = TRUE;
        continue;
      }
      // ANCHORED, because a route is a line that IS a path — not a line that
      // MENTIONS one (F-32). Unanchored, this harvested any path-shaped token
      // out of ordinary prose: T1's spec explained that rink nodes are served
      // by core's `/node/{nid}` route "which this change does not alter", and
      // the gate dutifully tried to render the literal string `/node/{nid}`
      // while `/rinks` — declared in a fence above, and therefore unseen —
      // went unrendered. That is F-15 exactly, recreated by F-15's own fix:
      // the gate rendering a route the ticket never named while the page the
      // ticket exists to build is never requested.
      //
      // Trailing prose after the path is still allowed, because "- /rinks —
      // the new listing" is the documented form and the em dash carries the
      // reason a reader needs.
      if (preg_match('~^`?(/[^\s`|]*)`?~', $item, $r) === 1) {
        $route = rtrim($r[1], '`');
        // A refusal the page must give an anonymous visitor rides after the
        // path in brackets: `- /admin/content/x (403) — editors only`
        // (F-120). Carried as `path@403`, as a declared row carries it.
        if (preg_match('~^\s*\((\d{3})\)~', substr($item, strlen($r[0])), $status) === 1
          && in_array((int) $status[1], RenderedRoutes::REFUSALS, TRUE)) {
          $route = RenderedRoutes::withStatus($route, (int) $status[1]);
        }
        if (!in_array($route, $routes, TRUE)) {
          $routes[] = $route;
        }
      }
    }

    return ['routes' => $routes, 'none' => $none];
  }

  /**
   * How the spec's acceptance criteria stand against the tests that prove them.
   *
   * Reads the first markdown table under the acceptance-criteria heading and
   * classifies every row by its "Verified By" cell: a reference to a test is
   * verified; `manual — <reason>` is verified by hand and reported as such,
   * never as passed; an empty cell (or a placeholder such as "—" or "TBD")
   * is unverified, and so is prose that points at no test — the cell is the
   * traceability LINK, and a sentence about what somebody did is not one. A
   * table with no such column counts every row as unverified and says so,
   * because a column nobody added is the commonest way for the link to go
   * missing.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $spec
   *   The governing spec, project-relative.
   *
   * @return array{total: int, verified: list<string>, manual: list<string>, unverified: list<string>, unnamed: list<string>, column_missing: bool}|null
   *   The classification — `unnamed` being the subset of `unverified` whose
   *   cell was filled in but named no test, so the refusal can say which of
   *   the two problems each row has. NULL when the spec has no
   *   acceptance-criteria table at all (a quasi-spec at medium/low, or a
   *   document that never had one) — there is nothing to hold in that case.
   */
  public static function criteriaVerification(string $projectRoot, string $spec): ?array {
    $text = self::read(rtrim($projectRoot, '/') . '/' . $spec);
    if ($text === NULL) {
      return NULL;
    }
    $heading = preg_quote(self::ACCEPTANCE_HEADING, '/');
    // The section runs from the heading to the next heading of the same or a
    // higher level; a deeper heading (###) inside it belongs to it.
    if (preg_match('/^' . $heading . '\b[^\n]*\n(.*?)(?=^#{1,2} |\z)/msi', $text, $m) !== 1) {
      return NULL;
    }
    $rows = [];
    foreach (preg_split('/\R/', $m[1]) ?: [] as $line) {
      $line = trim($line);
      if ($line !== '' && $line[0] === '|') {
        $rows[] = $line;
      }
    }
    if (count($rows) < 2) {
      return NULL;
    }
    $header = self::cells($rows[0]);
    $idIndex = 0;
    $verifiedIndex = NULL;
    foreach ($header as $i => $cell) {
      $key = strtolower(trim($cell));
      if (in_array($key, [strtolower(self::VERIFIED_COLUMN), 'verified', 'verified_by'], TRUE)) {
        $verifiedIndex = $i;
      }
      elseif (in_array($key, ['id', 'ac', 'criterion id'], TRUE)) {
        $idIndex = $i;
      }
    }
    $verified = [];
    $manual = [];
    $unverified = [];
    $unnamed = [];
    $position = 0;
    foreach (array_slice($rows, 1) as $row) {
      $cells = self::cells($row);
      if (self::isSeparator($cells)) {
        continue;
      }
      // A row with nothing in any cell is table padding, not a criterion.
      if (implode('', $cells) === '') {
        continue;
      }
      $position++;
      $id = trim(trim($cells[$idIndex] ?? ''), '*`_ ');
      if ($id === '') {
        // An unlabelled criterion is still a criterion. Dropping the row hid
        // it from the count, the report and the refusal at once — the one
        // shape of missing cell that makes the spec look BETTER than it is.
        // Numbered by its place among the body rows, so it can be found.
        $id = 'row ' . $position;
      }
      $value = $verifiedIndex === NULL ? '' : trim($cells[$verifiedIndex] ?? '');
      if (self::isPlaceholder($value)) {
        $unverified[] = $id;
      }
      elseif (preg_match('/^`?manual\b/i', $value) === 1) {
        $manual[] = $id;
      }
      elseif (preg_match(self::TEST_REFERENCE, $value) === 1) {
        $verified[] = $id;
      }
      else {
        // Filled in, and pointing at nothing anyone can open.
        $unverified[] = $id;
        $unnamed[] = $id;
      }
    }
    $total = count($verified) + count($manual) + count($unverified);
    if ($total === 0) {
      return NULL;
    }
    return [
      'total' => $total,
      'verified' => $verified,
      'manual' => $manual,
      'unverified' => $unverified,
      'unnamed' => $unnamed,
      'column_missing' => $verifiedIndex === NULL,
    ];
  }

  /**
   * The spec's text, with every fenced code block blanked out.
   *
   * Each reader here matches on markdown STRUCTURE — a heading at line start,
   * a row opening with a pipe — and a fenced block is full of both while
   * meaning neither. A spec that shows `## Tooling plan` as an example inside
   * triple backticks satisfied the contract on the strength of its own
   * teaching material, and a grounding table quoted as a template read as
   * grounding that happened.
   *
   * The block's lines are emptied rather than removed, so every later line
   * keeps its position and a section still runs exactly as far as it did —
   * only its fenced content stops looking like structure.
   *
   * @param string $path
   *   The spec, absolute.
   *
   * @return string|null
   *   The text, or NULL when the file cannot be read.
   */
  private static function read(string $path): ?string {
    $text = @file_get_contents($path);
    if ($text === FALSE) {
      return NULL;
    }
    $out = [];
    $fence = '';
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
      if ($fence === '') {
        // An opening fence: three or more backticks or tildes, indented by no
        // more than three spaces. Whatever follows them is the info string.
        if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m) === 1) {
          $fence = $m[1];
          $out[] = '';
          continue;
        }
        $out[] = $line;
        continue;
      }
      // Inside the block nothing is structure. It closes on the same
      // character, at least as long, alone on its line — and an unclosed
      // fence runs to the end of the document, as it does everywhere else.
      $out[] = '';
      if (preg_match('/^ {0,3}' . $fence[0] . '{' . strlen($fence) . ',}\s*$/', $line) === 1) {
        $fence = '';
      }
    }
    return implode("\n", $out);
  }

  /**
   * Whether a cell is an unfilled one, however it was spelled.
   *
   * @param string $value
   *   The cell, already trimmed.
   *
   * @return bool
   *   TRUE when the cell says nothing.
   */
  private static function isPlaceholder(string $value): bool {
    return $value === '' || in_array(strtolower($value), self::PLACEHOLDERS, TRUE);
  }

  /**
   * The cells of a markdown table row, outer pipes and padding removed.
   *
   * An escaped pipe (`\|`) inside a cell is data, not a boundary.
   *
   * @param string $row
   *   The row, starting with `|`.
   *
   * @return list<string>
   *   The trimmed cells.
   */
  private static function cells(string $row): array {
    $inner = trim($row);
    $inner = (string) preg_replace('/^\|/', '', $inner);
    $inner = (string) preg_replace('/\|$/', '', $inner);
    $inner = str_replace('\\|', "\x00", $inner);
    return array_map(
      static fn (string $cell): string => trim(str_replace("\x00", '|', $cell)),
      explode('|', $inner),
    );
  }

  /**
   * Whether a row is the header/body separator (`|---|:--:|`).
   *
   * GFM asks for one dash or more, not two: `|-|-|` is a valid separator and
   * was being read as the table's first criterion.
   *
   * @param list<string> $cells
   *   The row's cells.
   *
   * @return bool
   *   TRUE for a separator row.
   */
  private static function isSeparator(array $cells): bool {
    foreach ($cells as $cell) {
      if (preg_match('/^:?-+:?$/', $cell) !== 1) {
        return FALSE;
      }
    }
    return $cells !== [];
  }

  /**
   * A path as project-relative, for portable storage in run state.
   *
   * @param string $root
   *   The project root, no trailing slash.
   * @param string $path
   *   Absolute or already-relative.
   *
   * @return string
   *   Project-relative.
   */
  private static function relative(string $root, string $path): string {
    if (str_starts_with($path, $root . '/')) {
      return substr($path, strlen($root) + 1);
    }
    return ltrim($path, '/') === $path ? $path : ltrim($path, '/');
  }

}
