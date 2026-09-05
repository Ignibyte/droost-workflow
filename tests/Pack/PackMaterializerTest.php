<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\Pack\PackError;
use Droost\Workflow\Pack\PackManifest;
use Droost\Workflow\Pack\PackMaterializer;

/**
 * Installing the pack into a consuming repository.
 */
class PackMaterializerTest extends WorkflowTestCase {

  /**
   * REQ-002: init writes the pack, plants sentinels, and leaves config alone.
   */
  public function testInitWritesPackSentinelsAndConfig(): void {
    $root = $this->makeRoot();

    $report = (new PackMaterializer())->init($root);

    foreach (PackManifest::FILES as $destination) {
      $this->assertFileExists($root . '/' . $destination);
      $this->assertContains($destination, $report->written);
    }
    foreach (PackManifest::ownedDirectories() as $dir) {
      $this->assertFileExists(
        $root . '/' . $dir . '/' . PackManifest::SENTINEL,
        $dir . ' has no sentinel, so a re-init would refuse it',
      );
    }
    $this->assertFileExists($root . '/' . PackManifest::CONFIG_FILE);
    $this->assertSame([], $report->kept);
  }

  /**
   * REQ-002: an existing lever file is never overwritten.
   *
   * Version-controlled intent somebody wrote. Re-running init must not
   * quietly reset their gates.
   */
  public function testInitKeepsAnExistingLeverFile(): void {
    $root = $this->makeRoot();
    $mine = "preset: fast\n";
    file_put_contents($root . '/' . PackManifest::CONFIG_FILE, $mine);

    $report = (new PackMaterializer())->init($root);

    $this->assertSame(
      $mine,
      file_get_contents($root . '/' . PackManifest::CONFIG_FILE),
    );
    $this->assertContains(PackManifest::CONFIG_FILE, $report->kept);
    $this->assertNotContains(PackManifest::CONFIG_FILE, $report->written);
  }

  /**
   * REQ-003: re-running refreshes what we own, and is idempotent.
   */
  public function testReInitKeepsUserEditedFilesAndReportsDrift(): void {
    $root = $this->makeRoot();
    $materializer = new PackMaterializer();
    $materializer->init($root);

    // Edit a shipped pack file after the first init.
    $skill = $root . '/.claude/skills/workflow-plan/SKILL.md';
    file_put_contents($skill, "my tuned version\n");

    $report = $materializer->init($root);

    // The edit is KEPT, not clobbered — the drift-aware materializer holds a
    // file the user changed since droost shipped it...
    $this->assertSame("my tuned version\n", file_get_contents($skill));
    // ...and surfaces it as drift rather than silently discarding it.
    $this->assertContains(
      '.claude/skills/workflow-plan/SKILL.md',
      $report->drifted,
    );
  }

  /**
   * REQ-003: --take-upstream discards a drifted file for the shipped version.
   *
   * The escape hatch from the keep-my-edits default: an operator who WANTS
   * droost's version back names the file (or 'all'), and init overwrites the
   * local edit instead of preserving it as drift.
   */
  public function testTakeUpstreamDiscardsNamedDrift(): void {
    $root = $this->makeRoot();
    $materializer = new PackMaterializer();
    $materializer->init($root);

    $relative = '.claude/skills/workflow-plan/SKILL.md';
    $skill = $root . '/' . $relative;
    $pristine = file_get_contents($skill);
    file_put_contents($skill, "my tuned version\n");

    // Name exactly this file: its drift is discarded, the shipped version
    // returns, and it is reported written (not drifted).
    $report = $materializer->init($root, [$relative]);

    $this->assertSame($pristine, file_get_contents($skill));
    $this->assertContains($relative, $report->written);
    $this->assertNotContains($relative, $report->drifted);
  }

  /**
   * REQ-003: --take-upstream=all discards every drifted file at once.
   */
  public function testTakeUpstreamAllDiscardsEveryDrift(): void {
    $root = $this->makeRoot();
    $materializer = new PackMaterializer();
    $materializer->init($root);

    $relative = '.claude/skills/workflow-plan/SKILL.md';
    $skill = $root . '/' . $relative;
    $pristine = file_get_contents($skill);
    file_put_contents($skill, "my tuned version\n");

    $report = $materializer->init($root, ['all']);

    $this->assertSame($pristine, file_get_contents($skill));
    $this->assertSame([], $report->drifted);
  }

  /**
   * An unedited pack file is refreshed on re-init (the lock still matches).
   */
  public function testReInitRefreshesUnmodifiedFiles(): void {
    $root = $this->makeRoot();
    $materializer = new PackMaterializer();
    $materializer->init($root);

    $skill = $root . '/.claude/skills/workflow-plan/SKILL.md';
    $pristine = file_get_contents($skill);

    $report = $materializer->init($root);

    // Untouched since droost wrote it, so it is rewritten, not kept as drift.
    $this->assertSame($pristine, file_get_contents($skill));
    $this->assertNotContains(
      '.claude/skills/workflow-plan/SKILL.md',
      $report->drifted,
    );
    $this->assertContains(
      '.claude/skills/workflow-plan/SKILL.md',
      $report->written,
    );
  }

  /**
   * REQ-003: a directory we did not create is refused, not overwritten.
   */
  public function testRefusesDirectoryWithoutOurSentinel(): void {
    $root = $this->makeRoot();
    $theirs = $root . '/.claude/skills/workflow-plan';
    mkdir($theirs, 0755, TRUE);
    file_put_contents($theirs . '/SKILL.md', "somebody else's work\n");

    try {
      (new PackMaterializer())->init($root);
      $this->fail('Expected a PackError.');
    }
    catch (PackError $e) {
      $this->assertStringContainsString('workflow-plan', $e->getMessage());
      $this->assertStringContainsString('refusing to overwrite', $e->getMessage());
    }

    $this->assertSame(
      "somebody else's work\n",
      file_get_contents($theirs . '/SKILL.md'),
    );
  }

  /**
   * A refusal happens before anything is written, not halfway through.
   *
   * A half-installed pack is worse than a refused one: the agent would find
   * some skills present and conclude the install worked.
   */
  public function testRefusalLeavesNothingBehind(): void {
    $root = $this->makeRoot();
    // The LAST owned directory, so a naive implementation would already have
    // written the earlier files before reaching it.
    $dirs = PackManifest::ownedDirectories();
    $last = $root . '/' . $dirs[count($dirs) - 1];
    mkdir($last, 0755, TRUE);

    try {
      (new PackMaterializer())->init($root);
      $this->fail('Expected a PackError.');
    }
    catch (PackError $e) {
      $this->assertStringContainsString('refusing', $e->getMessage());
    }

    $this->assertFileDoesNotExist(
      $root . '/.claude/skills/workflow-plan/SKILL.md',
    );
    $this->assertFileDoesNotExist($root . '/' . PackManifest::CONFIG_FILE);
  }

  /**
   * REQ-004: nothing on the install path touches Drupal.
   */
  public function testMaterializerHasNoDrupalImports(): void {
    $offenders = [];
    foreach (glob(dirname(__DIR__, 2) . '/src/Pack/*.php') ?: [] as $file) {
      $source = file_get_contents($file);
      foreach (explode("\n", $source === FALSE ? '' : $source) as $line) {
        if (str_starts_with($line, 'use Drupal\\')) {
          $offenders[] = basename($file) . ': ' . trim($line);
        }
      }
    }

    $this->assertSame([], $offenders);
  }

  /**
   * A project root that cannot be used is refused before anything is written.
   */
  public function testBadProjectRootIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    (new PackMaterializer())->init($this->makeRoot() . '/nope');
  }

  /**
   * A package missing its own pack files says so rather than half-installing.
   */
  public function testMissingPackSourceIsReported(): void {
    $emptyPackage = $this->makeRoot();
    $this->expectException(PackError::class);
    $this->expectExceptionMessage('missing from this package');
    (new PackMaterializer($emptyPackage))->init($this->makeRoot());
  }

  /**
   * Ownership is decided by the sentinel and nothing else.
   */
  public function testOwnsTracksTheSentinel(): void {
    $root = $this->makeRoot();
    $dir = $root . '/somewhere';
    mkdir($dir, 0755, TRUE);
    $materializer = new PackMaterializer();

    $this->assertFalse($materializer->owns($dir));
    file_put_contents($dir . '/' . PackManifest::SENTINEL, "x\n");
    $this->assertTrue($materializer->owns($dir));
  }

}
