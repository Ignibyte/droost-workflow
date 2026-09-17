<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Evidence\EvaluationReport;
use Droost\Workflow\Evidence\WorkType;
use Droost\Workflow\Pack\PackManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Lints the pack itself — the artefact users actually get.
 *
 * Linting the materializer would prove only that copying works. The pack is
 * the product, it is prose, and nothing else in the toolchain reads prose:
 * phpcs does not lint Markdown and no test asserts a paragraph. So what CAN
 * be checked mechanically is checked here.
 *
 * What these tests deliberately do NOT claim: that the prose produces correct
 * agent behaviour. Shape is not truth. That is the fact-check critic's job at
 * inspect, and ultimately the live run at TICKET-133.
 */
class PackContentLintTest extends TestCase {

  /**
   * Files the operating system leaves behind, which pack/ never ships.
   *
   * @var list<string>
   */
  private const OS_ARTIFACTS = ['.DS_Store', 'Thumbs.db', 'desktop.ini'];

  /**
   * REQ-001: the pack and the manifest agree, in both directions.
   *
   * Both directions matters. The manifest is the sole enumerator, so a file
   * present but unlisted silently never ships — druplit's pack array carries
   * the same warning for the same reason.
   */
  public function testPackMatchesTheManifestBothWays(): void {
    foreach (PackManifest::FILES as $source => $unused) {
      $this->assertFileExists(
        $this->packDir() . '/' . $source,
        $source . ' is in the manifest but missing from pack/',
      );
    }

    $onDisk = [];
    $dir = new \RecursiveDirectoryIterator($this->packDir());
    foreach (new \RecursiveIteratorIterator($dir) as $file) {
      if (!$file instanceof \SplFileInfo || !$file->isFile()) {
        continue;
      }
      $relative = substr($file->getPathname(), strlen($this->packDir()) + 1);
      // Finder droppings are not pack content and can never be: this walks
      // the filesystem, so without this skip anyone who merely OPENS pack/ in
      // Finder fails the suite. Named explicitly rather than skipping all
      // dotfiles, because a dotfile the manifest forgot is exactly the defect
      // this test exists to catch.
      if (in_array($file->getFilename(), self::OS_ARTIFACTS, TRUE)) {
        continue;
      }
      if ($relative !== PackManifest::CONFIG_FILE) {
        $onDisk[] = $relative;
      }
    }

    sort($onDisk);
    $listed = array_keys(PackManifest::FILES);
    sort($listed);
    $this->assertSame($listed, $onDisk, 'pack/ and the manifest disagree');
  }

  /**
   * REQ-005: every skill states its gates and how it degrades without a site.
   *
   * @param string $source
   *   The pack-relative path of a skill file.
   */
  #[DataProvider('phaseSkillFiles')]
  public function testEverySkillHasTheFourSections(string $source): void {
    $body = $this->read($source);

    foreach (['## Entry gate', '## Work', '## Exit gate', '## Without a site'] as $heading) {
      $this->assertStringContainsString(
        $heading,
        $body,
        $source . ' is missing the "' . $heading . '" section',
      );
    }
  }

  /**
   * Every skill carries the frontmatter Claude Code reads.
   *
   * @param string $source
   *   The pack-relative path of a skill file.
   */
  #[DataProvider('skillFiles')]
  public function testEverySkillHasFrontmatter(string $source): void {
    $body = $this->read($source);

    $this->assertStringStartsWith("---\n", $body);
    $this->assertMatchesRegularExpression('/^name: \S+/m', $body);
    $this->assertMatchesRegularExpression('/^description: \S+/m', $body);
  }

  /**
   * Every skill in the pack: the phase skills and the entry verbs.
   *
   * @return array<string, array{string}>
   *   Case name to pack-relative path.
   */
  public static function skillFiles(): array {
    $cases = [];
    foreach (array_keys(PackManifest::FILES) as $source) {
      if (str_starts_with($source, 'skills/')) {
        $cases[$source] = [$source];
      }
    }
    return $cases;
  }

  /**
   * The phase skills only — the ones that gate work and degrade without a site.
   *
   * The entry verbs (start, continue, status) are procedures, not phases:
   * they have no entry gate or exit gate of their own, and "without a site"
   * is a fact about the phases they drive.
   *
   * @return array<string, array{string}>
   *   Case name to pack-relative path.
   */
  public static function phaseSkillFiles(): array {
    $cases = [];
    foreach (['plan', 'code', 'test', 'complete'] as $phase) {
      $source = 'skills/workflow-' . $phase . '/SKILL.md';
      $cases[$source] = [$source];
    }
    return $cases;
  }

  /**
   * REQ-006: the terminal gate insists on the report.
   */
  public function testCompleteSkillRequiresTheReport(): void {
    $body = $this->read('skills/workflow-complete/SKILL.md');

    $this->assertStringContainsString('gate report', $body);
    $this->assertStringContainsString('skipped', $body);
    // The four outcomes must be distinguished, not summarised.
    $this->assertStringContainsString('tool missing', $body);
  }

  /**
   * No pack file names a droost tool that does not exist.
   *
   * The one lint here that catches a FALSE claim rather than a missing
   * section. An early draft of this design cited "droost worker-docs", which
   * is not a tool; nothing but this test would have caught it before an agent
   * tried to call it.
   */
  public function testNoPackFileNamesAnUnknownTool(): void {
    $unknown = [];

    foreach ($this->allPackFiles() as $relative) {
      $body = $this->read($relative);
      $found = preg_match_all('/\bdroost_[a-z_]+/', $body, $matches);
      if ($found === FALSE || $found === 0) {
        continue;
      }
      foreach (array_unique($matches[0]) as $identifier) {
        // Tools and modules both match droost_*; both vocabularies are real
        // and both are checked, so naming either wrongly still fails.
        if (!in_array($identifier, PackManifest::citableIdentifiers(), TRUE)) {
          $unknown[] = $relative . ': ' . $identifier;
        }
      }
    }

    $this->assertSame([], $unknown, 'Pack files name non-existent tools');
  }

  /**
   * No pack file claims droost_verify checks the rendered result.
   *
   * Its legs are phpcs, phpstan, phpunit and deprecations. It does not render
   * anything, and a skill that says otherwise teaches an agent to report a
   * check that never happened.
   */
  public function testNoPackFileClaimsVerifyRenders(): void {
    foreach ($this->allPackFiles() as $relative) {
      $body = strtolower($this->read($relative));
      if (!str_contains($body, 'droost_verify')) {
        continue;
      }
      foreach (['droost_verify renders', 'droost_verify checks the rendered'] as $claim) {
        $this->assertStringNotContainsString($claim, $body, $relative);
      }
    }
    // And the partial states the real leg list.
    $partial = $this->read('partials/droost-usage.md');
    foreach (['phpcs', 'phpstan', 'phpunit', 'deprecations'] as $leg) {
      $this->assertStringContainsString($leg, $partial);
    }
  }

  /**
   * Wherever the pack explains droost_verify, it explains the opt-ins.
   *
   * The tool defaults to phpcs and phpstan; deprecations must be named and
   * phpunit additionally needs confirm: true. An earlier draft called all
   * four "the whole list", which would have had an agent report tests green
   * that were never executed.
   *
   * @param string $source
   *   The pack-relative path of a file that explains the tool.
   */
  #[DataProvider('verifyExplainers')]
  public function testVerifyOptInsAreStated(string $source): void {
    $body = $this->read($source);

    $this->assertStringContainsString('confirm', $body, $source
      . ' explains droost_verify without mentioning the phpunit confirm flag');
    $this->assertStringContainsString('opt-in', $body, $source
      . ' explains droost_verify without saying deprecations is opt-in');
  }

  /**
   * The files that describe droost_verify in detail.
   *
   * @return array<string, array{string}>
   *   Case name to pack-relative path.
   */
  public static function verifyExplainers(): array {
    return [
      'the partial' => ['partials/droost-usage.md'],
      'the test skill' => ['skills/workflow-test/SKILL.md'],
    ];
  }

  /**
   * No pack file tells an agent a droost tool works without a site.
   *
   * All 70 droost tools are mcp_server plugins backed by the container, so
   * there is no site-independent subset — not even the scaffolding tools,
   * whose generators are Drupal-free but which are only reachable through a
   * booted site. An earlier draft put droost_scaffold in a "works anywhere"
   * column, which would send a CLI-surface agent to call a tool that cannot
   * answer.
   */
  public function testNoPackFileClaimsToolsWorkWithoutSite(): void {
    // Phrased as claims about TOOL AVAILABILITY. A blunter list caught
    // "this phase needs no site tools" in the complete skill, which is the
    // opposite claim and a true one — that phase calls nothing, it presents
    // what the run already recorded.
    $forbidden = [
      'works anywhere',
      'need nothing running',
      'tools work without a site',
      'tools need no site',
      'available without a site',
      'still available with no site',
    ];

    foreach ($this->allPackFiles() as $relative) {
      $body = strtolower($this->read($relative));
      foreach ($forbidden as $phrase) {
        $this->assertStringNotContainsString($phrase, $body, sprintf(
          '%s implies a droost tool works with no site (%s)',
          $relative,
          $phrase,
        ));
      }
    }
  }

  /**
   * The pack never presents the wiki tools as somewhere it can write.
   *
   * WikiPages and WikiFactsheet both declare readOnly: TRUE, and no MCP tool
   * writes the wiki at all — the only path is `drush droost:wiki:generate`.
   * An earlier draft called them "where durable knowledge belongs", which
   * invites an agent to attempt a write, fail to find one, and record the
   * wiki as updated.
   */
  public function testWikiToolsArePresentedAsReadOnly(): void {
    $body = $this->read('skills/workflow-complete/SKILL.md');

    $this->assertStringContainsString('read-only', strtolower($body));
    $this->assertStringContainsString('droost:wiki:generate', $body);
    $this->assertStringNotContainsString(
      'where durable knowledge belongs',
      $body,
    );
  }

  /**
   * The pack describes the real engine, not the pre-engine era.
   *
   * The inversion of a lint that once pointed the other way: before
   * TICKET-131/132 shipped run state and pair mode, the pack correctly said
   * "nothing writes run.json / count your own attempts / pair is not
   * built" — and then the engine landed and the prose was never un-said,
   * leaving the canonical pack contradicting the shipped behaviour. These
   * phrases are now forbidden pack-wide, so the claim cannot regress
   * without this failing.
   */
  public function testPackDescribesTheRealEngine(): void {
    $stale = [
      'nothing writes',
      'has no producer',
      'does not exist yet',
      'count your own attempts',
      'no resume',
      'transport is not built',
      'nothing counts them for you',
    ];

    foreach ($this->allPackFiles() as $relative) {
      $body = strtolower($this->read($relative));
      foreach ($stale as $phrase) {
        $this->assertStringNotContainsString($phrase, $body, sprintf(
          '%s still carries pre-engine prose ("%s")',
          $relative,
          $phrase,
        ));
      }
    }

    // And the positive half: the entry and status commands must send an
    // agent to the engine's record, by name.
    $entry = $this->read('commands/droost/workflow/continue.md');
    $this->assertStringContainsString('droost/droost-workflow/run.json', $entry);
    $this->assertStringContainsString('resolved_gates', $entry);
    $status = $this->read('commands/droost/workflow/status.md');
    $this->assertStringContainsString('droost/droost-workflow/run.json', $status);
    $this->assertStringContainsString('phase_gates', $status);
    // Start opens a run: it guards on run.json (refuse to clobber) and hands
    // off to continue. Its whole reason to exist is that declare-browser
    // records against a run the first `run` creates — so it must not tell an
    // agent to declare the browser BEFORE that first invocation.
    $start = $this->read('commands/droost/workflow/start.md');
    $this->assertStringContainsString('droost/droost-workflow/run.json', $start);
    $this->assertStringContainsString('/droost:workflow:continue', $start);
    $this->assertDoesNotMatchRegularExpression(
      '~before the first `?run`?, .{0,40}declare~i',
      $start,
      'start must not resurrect the impossible declare-before-run order',
    );
  }

  /**
   * The retired command names do not linger anywhere in the pack.
   *
   * 0.4 renamed the entry to /droost:workflow:continue and DELETED the
   * /workflow:run pointer (a pointer file is a second source of truth). A
   * pack file still steering an agent at the old names would send it to
   * "unknown command" — except the entry command's own one-line note saying
   * what it used to be called, which is how muscle memory finds the rename.
   */
  public function testRetiredCommandNamesDoNotLinger(): void {
    foreach ($this->allPackFiles() as $relative) {
      $body = $this->read($relative);
      if ($relative === 'commands/droost/workflow/continue.md') {
        continue;
      }
      // Token-bounded: "/droost-work" must not swallow the legitimate
      // "vendor/bin/droost-workflow", and "/workflow:status" must not
      // swallow "droost:workflow:status".
      $this->assertDoesNotMatchRegularExpression(
        '~/droost-work(?![a-z-])~',
        $body,
        $relative,
      );
      $this->assertDoesNotMatchRegularExpression(
        '~(?<![a-z:])/workflow:(run|status)~',
        $body,
        $relative,
      );
    }
  }

  /**
   * Installed paths are named as they exist after installation.
   */
  public function testPackReferencesInstalledPaths(): void {
    $body = $this->read('commands/droost/workflow/continue.md');

    if (str_contains($body, 'droost-usage.md')) {
      $this->assertStringContainsString(
        '.claude/partials/droost-usage.md',
        $body,
        'continue.md points at the pack-relative path, not the installed one',
      );
    }
  }

  /**
   * The shipped lever file parses, and resolves to what it claims.
   *
   * It must name its preset explicitly: an unnamed preset resolves to
   * `factory`, so a default file that omitted it would hand every new repo
   * the strict set while its own comments described the gentle one.
   */
  public function testShippedLeverFileResolvesToCustom(): void {
    $root = sys_get_temp_dir() . '/dwf-pack-lint-' . bin2hex(random_bytes(6));
    mkdir($root, 0755, TRUE);
    copy(
      $this->packDir() . '/' . PackManifest::CONFIG_FILE,
      $root . '/' . PackManifest::CONFIG_FILE,
    );

    try {
      $config = WorkflowConfig::load($root);
      $this->assertSame('custom', $config->preset);
      $this->assertSame(
        WorkflowConfig::fromArray(['preset' => 'custom'], 'x')->resolvedGates(),
        $config->resolvedGates(),
        'The shipped lever file no longer matches the custom preset',
      );
      $this->assertSame(2, $config->maxGateRetries);
    }
    finally {
      unlink($root . '/' . PackManifest::CONFIG_FILE);
      rmdir($root);
    }
  }

  /**
   * Every pack file, including the lever file.
   *
   * @return list<string>
   *   Pack-relative paths.
   */
  private function allPackFiles(): array {
    return [...array_keys(PackManifest::FILES), PackManifest::CONFIG_FILE];
  }

  /**
   * The pack source directory.
   *
   * @return string
   *   The absolute path.
   */
  private function packDir(): string {
    return dirname(__DIR__, 2) . '/' . PackManifest::SOURCE_DIR;
  }

  /**
   * Reads a pack file.
   *
   * @param string $source
   *   The pack-relative path.
   *
   * @return string
   *   The contents.
   */
  private function read(string $source): string {
    $body = file_get_contents($this->packDir() . '/' . $source);
    $this->assertIsString($body, $source . ' is unreadable');
    return $body;
  }

  /**
   * The plan brief names every work type, and invents none.
   *
   * A mechanism the briefs do not mention is a trap: `--type` blocks a phase
   * when the gates its kind of work rests on measured nothing, and until this
   * was checked NO brief named the flag or a single one of its values. An agent
   * could not have passed a valid type without guessing it.
   *
   * Pinned in both directions, because both failures are silent. A type the
   * brief omits is one no agent will use; a type the brief invents throws on
   * the command line and teaches the agent to stop passing the flag at all.
   */

  /**
   * The plan brief names every tool the gate counts as a knowledge call.
   *
   * `grounding_check`'s ledger half fails a run whose tool-call ledger holds no
   * KNOWLEDGE_TOOLS call. The brief's "ask the site" list named six of the ten
   * and omitted the four it complained, three paragraphs up, were never called
   * — `droost_symbol`, `droost_graph`, `droost_module_patterns`,
   * `droost_deprecations` — plus `droost_search`. An agent following the list
   * to the letter could satisfy every citation and still fail the gate it was
   * never told existed. The constant is the contract; the brief has to match.
   */
  public function testThePlanBriefNamesEveryKnowledgeTool(): void {
    $brief = (string) file_get_contents(dirname(__DIR__, 2) . '/pack/skills/workflow-plan/SKILL.md');

    foreach (EvaluationReport::KNOWLEDGE_TOOLS as $tool) {
      $this->assertStringContainsString(
        '`' . $tool . '`',
        $brief,
        sprintf('the plan brief names the "%s" knowledge tool the gate counts', $tool),
      );
    }
  }

  /**
   * The plan brief names every work type the store knows, and invents none.
   */
  public function testThePlanBriefNamesEveryWorkTypeAndInventsNone(): void {
    $brief = (string) file_get_contents(dirname(__DIR__, 2) . '/pack/skills/workflow-plan/SKILL.md');

    foreach (WorkType::names() as $type) {
      $this->assertStringContainsString(
        '`' . $type . '`',
        $brief,
        sprintf('the plan brief names the "%s" work type', $type),
      );
    }

    preg_match_all('/\| `([a-z_]+)` \|/', $brief, $matches);
    foreach (array_unique($matches[1]) as $claimed) {
      $this->assertNotNull(
        WorkType::tryFrom($claimed),
        sprintf('the plan brief names "%s" as a work type and no such type exists', $claimed),
      );
    }
  }

  /**
   * The lever file names every tree the wall actually walls (F-8).
   *
   * The guard matches `(modules|themes|profiles)/custom/` and the lever file
   * documented two of the three, so an operator reading it would have believed
   * an install profile — PHP that builds the entire site — could be written
   * with no run, while the module beside it could not. The code was right and
   * the text was wrong, which is the harder kind to notice: nothing fails.
   *
   * Pinned against the guard's own pattern rather than a hardcoded list, so a
   * fourth tree cannot be added to one and forgotten in the other.
   */
  public function testTheLeverFileNamesEveryTreeRequireRunWalls(): void {
    $guard = (string) file_get_contents($this->packDir() . '/hooks/droost-workflow-guard.php');
    $this->assertSame(
      1,
      preg_match('#\(\^\|/\)\(([a-z|]+)\)/custom/#', $guard, $m),
      'the wall pattern moved; this test needs to follow it',
    );
    $trees = explode('|', $m[1]);
    $this->assertContains('profiles', $trees, 'profiles/custom is the one that was missing');

    $levers = (string) file_get_contents($this->packDir() . '/' . PackManifest::CONFIG_FILE);
    $section = strstr($levers, '# require_run:');
    $this->assertIsString($section, 'the lever file must document require_run');
    $section = substr($section, 0, (int) strpos($section, "\nrequire_run:"));
    foreach ($trees as $tree) {
      $this->assertStringContainsString(
        $tree . '/custom',
        $section,
        $tree . '/custom is walled but the lever file does not say so',
      );
    }
  }

}
