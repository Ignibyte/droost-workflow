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

    // NOT playwright any more. The shipped lever file spells it out as ON
    // with required, matching every preset, so it is no longer an override
    // and naming it here would be the notice crying about a value the dial
    // agrees with.
    foreach (['enforcement', 'mutation', 'coverage'] as $lever) {
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
    foreach (['mutation', 'coverage'] as $gate) {
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
    // On a Drupal project, so the level's phpcs standard applies and the
    // only thing that could speak is the dial. (On a plain PHP package the
    // level's Drupal standard is substituted, and THAT is announced — a
    // different notice, tested in StandardTheProjectCanRunTest.)
    $bare = $this->makeRoot();
    mkdir($bare . '/web/core/lib', 0755, TRUE);
    file_put_contents($bare . '/web/core/lib/Drupal.php', "<?php\n");
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
    mkdir($divergent . '/web/core/lib', 0755, TRUE);
    file_put_contents($divergent . '/web/core/lib/Drupal.php', "<?php\n");
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

  /**
   * A gate OPTION set looser than the preset is named; tighter is not (F-11).
   *
   * `phpstan: { level: 1 }` under `preset: max` is the same loosening as
   * switching phpstan off, one line lower down. A live site advertised max,
   * ran phpstan at level 1, and the notice named six levers and not that one.
   */
  public function testLoosenedGateOptionIsNamedAndTightenedOneIsNot(): void {
    $root = $this->makeRoot();
    file_put_contents($root . '/droost.workflow.yml', "preset: max\ngates:\n  phpstan: { level: 1 }\n  phpcs: { standard: Drupal }\n  coverage: { min: 10 }\n");
    $notice = implode(' ', WorkflowConfig::load($root)->deprecations);

    $this->assertStringContainsString('gates.phpstan.level (max says max, this file says 1)', $notice);
    $this->assertStringContainsString('gates.phpcs.standard (max says Drupal,DrupalPractice, this file drops DrupalPractice)', $notice);
    $this->assertStringContainsString('gates.coverage.min (max says 80, this file says 10)', $notice);

    $tighter = $this->makeRoot();
    file_put_contents($tighter . '/droost.workflow.yml', "preset: low\ngates:\n  phpstan: { level: 9, paths: web/modules/custom }\n  phpcs: { standard: 'Drupal,DrupalPractice,Custom' }\n");
    $this->assertSame([], WorkflowConfig::load($tighter)->deprecations, 'a tighter level, an added standard and a path set are tuning, not loosening');

    $swapped = $this->makeRoot();
    file_put_contents($swapped . '/droost.workflow.yml', "preset: max\ngates:\n  phpcs: { standard: PSR12 }\n");
    $this->assertSame([], WorkflowConfig::load($swapped)->deprecations, 'a different standard is a different choice, not less of the same one');
  }

}
