<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

use Droost\Engine\Harness\AgentsHarnessInstaller;
use Droost\Engine\Harness\ClaudeHarnessInstaller;
use Droost\Engine\Harness\CodexHarnessInstaller;
use Droost\Engine\Harness\GeminiHarnessInstaller;
use Droost\Engine\Harness\HarnessInstallerInterface;
use Droost\Engine\Harness\InstallContext;
use Droost\Engine\Harness\InstallResult;
use Droost\Engine\Harness\OpencodeHarnessInstaller;
use Droost\Engine\Harness\QwenHarnessInstaller;
use Droost\Engine\Site\UnknownSite;
use Droost\Engine\Skills\SkillMdWriter;
use Droost\Engine\Skills\SkillProvider;

/**
 * Writes droost's block into each agent harness a project uses: `droost init`.
 *
 * The installers of droost-engine own each harness's files (AGENTS.md
 * first, then each harness's pointer to it). droost ships no MCP server, so
 * each is told there is none, which removes an entry an earlier droost
 * wrote. The block says what droost is now: knowledge by command, and the
 * workflow's run.
 */
final class HarnessSetup {

  /**
   * The AGENTS.md block's directive.
   */
  public const string DIRECTIVE = <<<'TXT'
  ## Use droost first

  droost knows this codebase first-hand: its code index, its structure and
  its wiki are built from this project's own files, core and contrib
  included. Before writing, changing, reviewing or planning any Drupal or
  PHP code here, ask it what THIS project contains rather than trusting
  prior knowledge: Drupal's APIs change between versions. droost reads files
  only, so it answers while the site is broken.

  - `droost code:wiki` — the wiki, and an inventory of every installed module
    and theme (`droost code:wiki inventory`, `droost code:wiki inventory/<name>`).
  - `droost code:tree [module|path]` — the code's structure: files, classes,
    methods, and each module's hooks, services and routes.
  - `droost search:arch "<words>"` — the documentation, as plain text.
  - `droost code:symbol`, `droost code:graph`, `droost search:code`,
    `droost code:docs`, `droost code:deprecations`, `droost brief`.

  `droost` with no argument lists the commands; `droost <command> help`
  explains one. Plan consults `code:wiki` and `code:tree` before it closes.
  When the code index is missing or stale, `droost code:index` rebuilds it.

  How you build is your call. The site's house rules say what shape the
  result must take, and the run's gates and seeker hold it to them.
  TXT;

  /**
   * Constructs a HarnessSetup.
   *
   * @param string $packageRoot
   *   This package's root, which holds the (empty) engine skills directory.
   */
  public function __construct(private readonly string $packageRoot) {}

  /**
   * Installs the block across the harnesses a selection names.
   *
   * @param string $root
   *   The project root.
   * @param string $selection
   *   Which: "auto" (the harnesses the project shows signs of), "all", or a
   *   comma-separated list of ids: agents, claude, codex, opencode, gemini,
   *   qwen.
   * @param string $coreVersion
   *   Drupal core's version, for the block's stamp; '' when unknown.
   *
   * @return \Droost\Engine\Harness\InstallResult
   *   What was written, removed and warned about.
   */
  public function install(string $root, string $selection, string $coreVersion): InstallResult {
    $installers = $this->installers($coreVersion);
    $chosen = $this->select($installers, $selection, $root);
    $header = $coreVersion === ''
      ? sprintf("# Droost — Drupal version unknown, PHP %s\n\n", PHP_VERSION)
      : sprintf("# Droost — Drupal %s, PHP %s\n\n", $coreVersion, PHP_VERSION);
    $context = new InstallContext($header . self::DIRECTIVE, NULL);
    $result = new InstallResult();
    foreach ($chosen as $installer) {
      $installer->install($root, $context, $result);
    }
    return $result;
  }

  /**
   * Every installer, AGENTS.md's first.
   *
   * @param string $coreVersion
   *   Core's version.
   *
   * @return array<string, \Droost\Engine\Harness\HarnessInstallerInterface>
   *   The installers by id.
   */
  private function installers(string $coreVersion): array {
    $site = new UnknownSite($coreVersion);
    $skillsDir = $this->packageRoot . '/harness/skills';
    $all = [
      new AgentsHarnessInstaller(),
      new ClaudeHarnessInstaller(new SkillProvider($skillsDir), new SkillMdWriter('droost-workflow', $site)),
      new CodexHarnessInstaller(),
      new OpencodeHarnessInstaller(),
      new GeminiHarnessInstaller(),
      new QwenHarnessInstaller(),
    ];
    $out = [];
    foreach ($all as $installer) {
      $out[$installer->getId()] = $installer;
    }
    return $out;
  }

  /**
   * The installers a selection names, AGENTS.md's always first.
   *
   * @param array<string, \Droost\Engine\Harness\HarnessInstallerInterface> $installers
   *   Every installer.
   * @param string $selection
   *   Which: auto, all, or ids.
   * @param string $root
   *   The project root, for detection.
   *
   * @return list<\Droost\Engine\Harness\HarnessInstallerInterface>
   *   The chosen.
   */
  private function select(array $installers, string $selection, string $root): array {
    $selection = trim($selection) === '' ? 'auto' : trim($selection);
    if ($selection === 'all') {
      $chosen = $installers;
    }
    elseif ($selection === 'auto') {
      $chosen = array_filter($installers, static fn (HarnessInstallerInterface $i): bool => $i->isDetected($root));
      if (count($chosen) <= 1) {
        // Nothing but AGENTS.md: Claude Code is the baseline.
        $chosen['claude'] = $installers['claude'];
      }
    }
    else {
      $chosen = [];
      foreach (explode(',', $selection) as $id) {
        $id = trim($id);
        if (!isset($installers[$id])) {
          throw new \InvalidArgumentException(sprintf('"%s" is not a harness droost writes; it knows %s.', $id, implode(', ', array_keys($installers))));
        }
        $chosen[$id] = $installers[$id];
      }
    }
    $chosen = ['agents' => $installers['agents']] + $chosen;
    return array_values($chosen);
  }

}
