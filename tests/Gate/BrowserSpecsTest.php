<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Gate\BrowserSpecs;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * A browser spec is a spec's name inside the project's Playwright test dir.
 *
 * F-402: a Vitest test, `js/src/*.test.jsx` in a custom module, was read
 * as a Playwright spec by its name alone.
 */
final class BrowserSpecsTest extends WorkflowTestCase {

  /**
   * The Vitest file from the report.
   */
  private const VITEST = 'docroot/modules/custom/search_ui/js/src/firstResults.test.jsx';

  /**
   * The config's testDir decides; the Vitest test is not a spec.
   */
  public function testTheConfigsTestDirHoldsTheSpecs(): void {
    $root = $this->withConfig("export default defineConfig({\n  testDir: 'tests/e2e',\n  testMatch: '**/*.spec.ts',\n});\n");

    $specs = $this->specs($root);

    $this->assertSame(['tests/e2e'], $specs->dirs);
    $this->assertTrue($specs->isSpec('tests/e2e/search.spec.ts'));
    $this->assertTrue($specs->isSpec('./tests/e2e/deep/search.spec.ts'));
    $this->assertFalse($specs->isSpec(self::VITEST));
    $this->assertFalse($specs->isSpec('tests/e2e-old/search.spec.ts'), 'a sibling with the same prefix is not inside');
    $this->assertFalse($specs->isSpec('tests/e2e/helpers.ts'), 'inside, but not a spec\'s name');
    $this->assertStringContainsString('under tests/e2e (playwright.config.ts testDir)', $specs->describe());
  }

  /**
   * Dot-slash, double quotes, path.join(__dirname, …) and every project's.
   */
  public function testShapesTestDirIsWrittenIn(): void {
    $this->assertSame(['tests/e2e'], $this->specs($this->withConfig("module.exports = { testDir: \"./tests/e2e/\" };\n", 'playwright.config.js'))->dirs);
    $this->assertSame(['tests/browser'], $this->specs($this->withConfig("const config = { testDir: path.join(__dirname, 'tests', 'browser') };\n"))->dirs);
    $this->assertSame(
      ['tests/a', 'tests/b'],
      $this->specs($this->withConfig("export default {\n  projects: [\n    { name: 'a', testDir: './tests/a' },\n    { name: 'b', testDir: './tests/b' },\n  ],\n};\n"))->dirs,
    );
  }

  /**
   * Where the directory is the project, or unknown, the name decides.
   *
   * As before F-402: a project whose specs already live in its test directory
   * loses nothing, and one droost cannot read never lets a declined spec by.
   */
  public function testTheNameDecidesWhereTheDirectoryCannotNarrowIt(): void {
    $cases = [
      'no config' => NULL,
      'no testDir' => "export default defineConfig({ retries: 1 });\n",
      'the project root' => "export default defineConfig({ testDir: '.', testMatch: ['tests/e2e/**/*.spec.ts'] });\n",
      'a variable' => "export default defineConfig({ testDir: process.env.SPECS });\n",
      'one of two unreadable' => "export default { projects: [{ testDir: 'tests/a' }, { testDir: dir }] };\n",
      'outside the project' => "export default defineConfig({ testDir: '../elsewhere' });\n",
    ];
    foreach ($cases as $case => $config) {
      $root = $config === NULL ? $this->makeRoot() : $this->withConfig($config);
      $specs = $this->specs($root);
      $this->assertSame([], $specs->dirs, $case);
      $this->assertTrue($specs->isSpec(self::VITEST), $case);
      $this->assertTrue($specs->isSpec('tests/e2e/x.spec.ts'), $case);
      $this->assertFalse($specs->isSpec('src/thing.ts'), $case);
    }
  }

  /**
   * A commented-out testDir names nothing.
   */
  public function testCommentIsNotConfig(): void {
    $root = $this->withConfig("/* testDir: 'old', */\nexport default defineConfig({\n  // testDir: './legacy',\n  baseURL: 'https://site.ddev.site', testDir: 'tests/e2e',\n});\n");

    $this->assertSame(['tests/e2e'], $this->specs($root)->dirs);
  }

  /**
   * The lever overrides the config, from the lever file or the gate.
   */
  public function testTheLeverOverridesTheConfig(): void {
    $root = $this->makeRootWithConfig("preset: custom\ngates:\n  playwright: { on: true, test_dir: 'tests/e2e, docroot/modules/custom/x/tests/Playwright' }\n");
    file_put_contents($root . '/playwright.config.ts', "export default defineConfig({ testDir: '.' });\n");

    $this->assertSame('tests/e2e,docroot/modules/custom/x/tests/Playwright', WorkflowConfig::load($root)->gate('playwright')->option('test_dir'));
    $read = BrowserSpecs::of($root);
    $this->assertSame(['tests/e2e', 'docroot/modules/custom/x/tests/Playwright'], $read->dirs);
    $this->assertSame('gates.playwright.test_dir', $read->from);
    $this->assertTrue($read->isSpec('docroot/modules/custom/x/tests/Playwright/a.spec.ts'));
    $this->assertFalse($read->isSpec('docroot/modules/custom/x/js/src/a.test.jsx'));

    $handed = BrowserSpecs::of($root, new GateSettings('playwright', TRUE, ['test_dir' => 'e2e']));
    $this->assertSame(['e2e'], $handed->dirs, 'the gate\'s own levers, as the runner resolved them');
  }

  /**
   * A test_dir that leaves the project is refused at load, like `paths`.
   */
  public function testTraversingLeverIsRefused(): void {
    $root = $this->makeRootWithConfig("preset: custom\ngates:\n  playwright: { on: true, test_dir: '../e2e' }\n");

    $this->expectExceptionMessage('test_dir must be a comma-separated list of repo-relative paths');
    WorkflowConfig::load($root);
  }

  /**
   * A project with one Playwright config.
   *
   * @param string $config
   *   Its source.
   * @param string $name
   *   Its file name.
   *
   * @return string
   *   The project root.
   */
  private function withConfig(string $config, string $name = 'playwright.config.ts'): string {
    $root = $this->makeRoot();
    file_put_contents($root . '/' . $name, $config);
    return $root;
  }

  /**
   * The project's specs with no lever set.
   *
   * @param string $root
   *   The project.
   *
   * @return \Droost\Workflow\Gate\BrowserSpecs
   *   The definition.
   */
  private function specs(string $root): BrowserSpecs {
    return BrowserSpecs::of($root, new GateSettings('playwright', TRUE, []));
  }

}
