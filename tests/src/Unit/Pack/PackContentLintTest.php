<?php

declare(strict_types=1);

namespace Drupal\Tests\droost_workflow\Unit\Pack;

use Drupal\droost_workflow\Config\WorkflowConfig;
use Drupal\droost_workflow\Pack\PackManifest;
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
  #[DataProvider('skillFiles')]
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
   * The five phase skills.
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
    $body = $this->read('skills/workflow-document/SKILL.md');

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

    // And the positive half: the two commands must send an agent to the
    // engine's record, by name.
    $run = $this->read('commands/workflow/run.md');
    $this->assertStringContainsString('.droost-workflow/run.json', $run);
    $this->assertStringContainsString('resolved_gates', $run);
    $status = $this->read('commands/workflow/status.md');
    $this->assertStringContainsString('.droost-workflow/run.json', $status);
    $this->assertStringContainsString('phase_gates', $status);
  }

  /**
   * Installed paths are named as they exist after installation.
   */
  public function testPackReferencesInstalledPaths(): void {
    $body = $this->read('commands/workflow/run.md');

    if (str_contains($body, 'droost-usage.md')) {
      $this->assertStringContainsString(
        '.claude/partials/droost-usage.md',
        $body,
        'run.md points at the pack-relative path, not the installed one',
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
    return dirname(__DIR__, 4) . '/' . PackManifest::SOURCE_DIR;
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

}
