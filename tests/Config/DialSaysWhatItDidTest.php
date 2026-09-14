<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Config;

use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Pack\PackMaterializer;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * Turning the dial says so when the dial is not what decided.
 *
 * `init` writes every lever out longhand under `preset: custom`, deliberately:
 * a file naming no preset resolves to `max`, so the explicit block is how a
 * fresh project gets a gentler set without silently opting out of anything.
 * Explicit beats preset, which is also right.
 *
 * What was missing is that nothing said so. A user who does what the file's
 * own comment describes — "Naming a level is how you choose a gentler set on
 * purpose" — and changes one line to `preset: max` gets:
 *
 *   enforcement soft, where max says hard
 *   mutation, playwright and coverage still off — the three gates that
 *   separate max from xhigh
 *   max_gate_retries 2, where max says 3
 *
 * while `status` reports `"preset": "max"` and `"deprecations": []`. Silent,
 * and every one of those differences is in the loosening direction.
 *
 * The file's own rationale is that "a visible loosening is the honest way to
 * allow it" — which requires it to be visible.
 */
final class DialSaysWhatItDidTest extends WorkflowTestCase {

  /**
   * The pack's own file, dial turned, reports what the dial did not reach.
   */
  public function testTurningTheDialOnInitsFileSaysWhatItDidNotReach(): void {
    $root = $this->makeRoot();
    (new PackMaterializer())->init($root);
    $file = $root . '/droost.workflow.yml';
    $written = (string) file_get_contents($file);
    $this->assertStringContainsString('preset: custom', $written, 'init writes the dial at custom');
    file_put_contents($file, str_replace('preset: custom', 'preset: max', $written));

    $config = WorkflowConfig::load($root);
    $this->assertSame('max', $config->preset, 'the file names max');
    $notice = implode(' ', $config->deprecations);

    foreach (['enforcement', 'mutation', 'playwright', 'coverage'] as $lever) {
      $this->assertStringContainsString(
        $lever,
        $notice,
        sprintf('%s is not what max says, and the reader is told', $lever),
      );
    }
    $this->assertStringContainsString(
      'max says hard, this file says soft',
      $notice,
      'both values, so nobody has to go and look either of them up',
    );
  }

  /**
   * And the dial really is overridden — the notice is not crying wolf.
   */
  public function testTheOverrideTheNoticeDescribesIsReal(): void {
    $spelledOut = $this->makeRoot();
    (new PackMaterializer())->init($spelledOut);
    $file = $spelledOut . '/droost.workflow.yml';
    file_put_contents(
      $file,
      str_replace('preset: custom', 'preset: max', (string) file_get_contents($file)),
    );
    $overridden = WorkflowConfig::load($spelledOut);

    $bare = $this->makeRoot();
    file_put_contents($bare . '/droost.workflow.yml', "preset: max\n");
    $dial = WorkflowConfig::load($bare);

    $this->assertSame('max', $overridden->preset);
    $this->assertSame('max', $dial->preset, 'the same preset, by name');
    $this->assertNotSame(
      $dial->enforcement,
      $overridden->enforcement,
      'and a different answer, which is the whole finding',
    );
    foreach (['mutation', 'playwright', 'coverage'] as $gate) {
      $this->assertTrue($dial->gates[$gate]->on, $gate . ' is what max means');
      $this->assertFalse(
        $overridden->gates[$gate]->on,
        $gate . ' stays off in the spelled-out file, whatever the dial says',
      );
    }
  }

  /**
   * A file whose preset really did decide says nothing.
   *
   * The counterweight, and the reason this is scoped to gates' `on` rather
   * than every lever: a notice that fires on ordinary tuning is a notice
   * nobody reads, and `custom` IS "the values spelled out below" — saying so
   * about every project init touches would be pure noise.
   */
  public function testNothingIsSaidWhenTheDialDecided(): void {
    $bare = $this->makeRoot();
    file_put_contents($bare . '/droost.workflow.yml', "preset: max\n");
    $this->assertSame([], WorkflowConfig::load($bare)->deprecations, 'a preset alone is silent');

    $tuned = $this->makeRoot();
    file_put_contents(
      $tuned . '/droost.workflow.yml',
      "preset: high\ngates:\n  phpstan: { level: 9 }\n  phpcs: { standard: PSR12 }\n",
    );
    $this->assertSame(
      [],
      WorkflowConfig::load($tuned)->deprecations,
      'tuning a gate the level already runs is the ordinary reason to write this file',
    );

    $initd = $this->makeRoot();
    (new PackMaterializer())->init($initd);
    $this->assertSame(
      [],
      WorkflowConfig::load($initd)->deprecations,
      'and custom means "spelled out below", so it is never news',
    );

    // Not by accident: `custom` names values of its own, and a file that
    // diverges from them is doing the one thing custom is FOR. "custom says
    // soft, this file says hard" would be a sentence about nothing.
    $divergent = $this->makeRoot();
    file_put_contents(
      $divergent . '/droost.workflow.yml',
      "preset: custom\nenforcement: hard\nseekers: { on: false }\n"
      . "gates:\n  wiki_fresh: { on: false }\n",
    );
    $config = WorkflowConfig::load($divergent);
    $this->assertSame(
      'hard',
      $config->enforcement->value,
      'the divergence is real — this fixture differs from custom\'s own base',
    );
    $this->assertFalse($config->gates['wiki_fresh']->on, 'and so is the gate');
    $this->assertSame([], $config->deprecations, 'and it is still not news');
  }

  /**
   * Loosening under a named level is reported whichever lever it is.
   */
  public function testLooseningUnderNamedLevelsIsReported(): void {
    $root = $this->makeRoot();
    file_put_contents(
      $root . '/droost.workflow.yml',
      "preset: high\nenforcement: off\nseekers: { on: false }\n",
    );

    $notice = implode(' ', WorkflowConfig::load($root)->deprecations);
    $this->assertStringContainsString('high says hard, this file says off', $notice);
    $this->assertStringContainsString('seekers.on', $notice, 'the adversarial review too');
  }

}
