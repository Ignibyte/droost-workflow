<?php

declare(strict_types=1);

namespace Droost\Workflow\Spec;

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
   * The section holding the acceptance-criteria table.
   */
  public const ACCEPTANCE_HEADING = '## Acceptance criteria';

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
    $stateDir = RunStateStore::resolveStateDir($root);
    $dir = $root . '/' . $stateDir;
    $candidates = glob($dir . '/spec-*.md') ?: [];
    if (count($candidates) === 1) {
      return self::relative($root, $candidates[0]);
    }
    throw SpecError::unresolvable($stateDir, count($candidates));
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
  public static function requireSection(
    string $projectRoot,
    string $spec,
    string $heading,
    string $why,
  ): void {
    $path = rtrim($projectRoot, '/') . '/' . $spec;
    $text = @file_get_contents($path);
    if ($text === FALSE) {
      throw SpecError::missing($spec);
    }
    // Heading match at line start, tolerant of trailing words on the same
    // line ("## Tooling plan (all surfaces exhausted)") but never of a
    // deeper heading level standing in for the required one.
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
    $text = @file_get_contents($root . '/' . $spec);
    if ($text !== FALSE
      && preg_match('/^' . preg_quote(self::REALIZED_HEADING, '/') . '\b/mi', $text) === 1) {
      return TRUE;
    }
    $companion = preg_replace('~/spec-([^/]+)\.md$~', '/realized-$1.md', '/' . $spec);
    return is_string($companion) && is_file($root . $companion);
  }

  /**
   * How the spec's acceptance criteria stand against the tests that prove them.
   *
   * Reads the first markdown table under the acceptance-criteria heading and
   * classifies every row by its "Verified By" cell: a test reference is
   * verified; `manual — <reason>` is verified by hand and reported as such,
   * never as passed; an empty cell (or a placeholder such as "—" or "TBD")
   * is unverified. A table with no such column counts every row as
   * unverified and says so, because a column nobody added is the commonest
   * way for the link to go missing.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $spec
   *   The governing spec, project-relative.
   *
   * @return array{total: int, verified: list<string>, manual: list<string>, unverified: list<string>, column_missing: bool}|null
   *   The classification, or NULL when the spec has no acceptance-criteria
   *   table at all (a quasi-spec at medium/low, or a document that never had
   *   one) — there is nothing to hold in that case.
   */
  public static function criteriaVerification(string $projectRoot, string $spec): ?array {
    $text = @file_get_contents(rtrim($projectRoot, '/') . '/' . $spec);
    if ($text === FALSE) {
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
    foreach (array_slice($rows, 1) as $row) {
      $cells = self::cells($row);
      if (self::isSeparator($cells)) {
        continue;
      }
      $id = trim(trim($cells[$idIndex] ?? ''), '*`_ ');
      if ($id === '') {
        continue;
      }
      $value = $verifiedIndex === NULL ? '' : trim($cells[$verifiedIndex] ?? '');
      if ($value === '' || in_array(strtolower($value), ['—', '–', '-', 'tbd', 'todo', 'n/a', 'pending'], TRUE)) {
        $unverified[] = $id;
      }
      elseif (preg_match('/^`?manual\b/i', $value) === 1) {
        $manual[] = $id;
      }
      else {
        $verified[] = $id;
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
      'column_missing' => $verifiedIndex === NULL,
    ];
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
   * @param list<string> $cells
   *   The row's cells.
   *
   * @return bool
   *   TRUE for a separator row.
   */
  private static function isSeparator(array $cells): bool {
    foreach ($cells as $cell) {
      if (preg_match('/^:?-{2,}:?$/', $cell) !== 1) {
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
