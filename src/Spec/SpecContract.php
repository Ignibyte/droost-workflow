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
