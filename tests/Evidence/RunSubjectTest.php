<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\RunSubject;
use Droost\Workflow\Gate\BrowserSpecs;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * Code's subject leaves the browser specs to test, and keeps a Vitest test.
 *
 * F-402: a Vitest test is a unit test of the front end, measured
 * by the project's own gate at code. Read by name alone it was a browser
 * spec, so an edit to it moved no fingerprint code's gates are bound to.
 */
final class RunSubjectTest extends WorkflowTestCase {

  /**
   * The Vitest test is code's; the spec in the test directory is not.
   */
  public function testCodesSubjectKeepsVitestTests(): void {
    $root = $this->makeRoot();
    file_put_contents($root . '/playwright.config.ts', "export default defineConfig({ testDir: 'tests/e2e' });\n");
    $changed = [
      'web/modules/custom/x/js/src/results.test.jsx',
      'tests/e2e/search.spec.ts',
      'web/modules/custom/x/src/Thing.php',
    ];

    $this->assertSame(
      ['web/modules/custom/x/js/src/results.test.jsx', 'web/modules/custom/x/src/Thing.php'],
      RunSubject::measured($changed, RunSubject::CODE, BrowserSpecs::of($root)),
    );
    $this->assertSame($changed, RunSubject::measured($changed, RunSubject::TEST));

    // The fingerprint moves when the Vitest test does.
    mkdir($root . '/web/modules/custom/x/js/src', 0755, TRUE);
    file_put_contents($root . '/web/modules/custom/x/js/src/results.test.jsx', 'it(1)');
    $before = RunSubject::of($root, $changed, RunSubject::CODE);
    file_put_contents($root . '/web/modules/custom/x/js/src/results.test.jsx', 'it(2)');
    $this->assertNotSame($before, RunSubject::of($root, $changed, RunSubject::CODE));
  }

  /**
   * With no test directory known, every spec-named file is test's, as before.
   */
  public function testWithoutTestDirectoryTheNameDecides(): void {
    $root = $this->makeRoot();

    $this->assertSame(
      ['web/modules/custom/x/src/Thing.php'],
      RunSubject::measured(['web/modules/custom/x/js/src/results.test.jsx', 'web/modules/custom/x/src/Thing.php'], RunSubject::CODE),
    );
    $this->assertNotNull(RunSubject::of($root, ['web/modules/custom/x/js/src/results.test.jsx'], RunSubject::CODE));
  }

}
