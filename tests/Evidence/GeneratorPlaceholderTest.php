<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\DeclarationAudit;
use Droost\Workflow\Evidence\GeneratorPlaceholder;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * A test drush generated and nobody filled in is not the run's test (F-77).
 *
 * The router sends a unit test to `drush generate test:unit`. Its template's
 * only test asserts TRUE, and drush records nothing, so F-65's record of
 * what droost's own scaffold wrote could not see it.
 */
final class GeneratorPlaceholderTest extends WorkflowTestCase {

  /**
   * The unit template drush generates, rendered for a module called example.
   */
  private const UNIT = <<<'PHP'
<?php

declare(strict_types=1);

namespace Drupal\Tests\example\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Test description.
 */
#[Group('example')]
final class ExampleTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // @todo Mock required classes here.
  }

  /**
   * Tests something.
   */
  public function testSomething(): void {
    self::assertTrue(TRUE, 'This is TRUE!');
  }

}
PHP;

  /**
   * Each of the generator's four templates is recognised.
   */
  public function testEveryGeneratorTemplateReadsAsPlaceholder(): void {
    $this->assertTrue(GeneratorPlaceholder::isPlaceholderTest(self::UNIT), 'unit');
    $this->assertTrue(GeneratorPlaceholder::isPlaceholderTest(str_replace(
      "self::assertTrue(TRUE, 'This is TRUE!');",
      'self::assertTrue(TRUE);',
      self::UNIT,
    )), 'kernel');
    $this->assertTrue(GeneratorPlaceholder::isPlaceholderTest(str_replace(
      "self::assertTrue(TRUE, 'This is TRUE!');",
      '// Place your code here.',
      self::UNIT,
    )), 'webdriver');
    $this->assertTrue(GeneratorPlaceholder::isPlaceholderTest(str_replace(
      "self::assertTrue(TRUE, 'This is TRUE!');",
      "\$admin_user = \$this->drupalCreateUser(['administer site configuration']);\n"
      . "    \$this->drupalLogin(\$admin_user);\n"
      . "    \$this->drupalGet('/admin/config/system/site-information');\n"
      . "    \$this->assertSession()->elementExists('xpath', '//h1[text() = \"Basic site settings\"]');",
      self::UNIT,
    )), 'browser');
  }

  /**
   * A filled-in body, a renamed test or a second test makes it the run's.
   */
  public function testAnyRealTestMakesTheFileTheRuns(): void {
    $filled = str_replace(
      "self::assertTrue(TRUE, 'This is TRUE!');",
      "self::assertSame('/contact?recipient=7', ContactLink::to(7));",
      self::UNIT,
    );
    $this->assertFalse(GeneratorPlaceholder::isPlaceholderTest($filled), 'a filled-in body');

    $renamed = str_replace('testSomething', 'testLinksCarryTheRecipient', self::UNIT);
    $this->assertFalse(GeneratorPlaceholder::isPlaceholderTest($renamed), 'the placeholder body under a real name is still a test someone chose to keep');

    // Before the class's closing brace, the last one in the file.
    $at = (int) strrpos(self::UNIT, '}');
    $second = substr_replace(
      self::UNIT,
      "  public function testLinksCarryTheRecipient(): void {\n    self::assertSame(1, 1 + 0);\n  }\n\n}",
      $at,
      1,
    );
    $this->assertFalse(GeneratorPlaceholder::isPlaceholderTest($second), 'a second test beside the placeholder');

    $this->assertFalse(GeneratorPlaceholder::isPlaceholderTest('<?php final class NotATest {}'), 'no test method at all');
  }

  /**
   * The audit sets a placeholder aside, by name, and blocks where it must.
   */
  public function testThePlaceholderDoesNotMeetTheDemand(): void {
    $root = $this->makeRoot();
    $test = 'web/modules/custom/example/tests/src/Unit/ExampleTest.php';
    mkdir(dirname($root . '/' . $test), 0777, TRUE);
    file_put_contents($root . '/' . $test, self::UNIT);
    $changed = ['web/modules/custom/example/src/ContactLink.php', $test];
    $declared = ['web/modules/custom/example'];

    $placeholder = new DeclarationAudit($declared, [], $changed, NULL, ['phpcs', 'phpunit'], projectRoot: $root, testsInDiff: TRUE);
    $row = $this->checkAt($placeholder, 'test', 'tests_in_diff');
    $this->assertNotNull($row);
    $this->assertSame(CheckState::Blocked, $row->state, 'drush\'s placeholder is not the run\'s test');
    $this->assertStringContainsString('ExampleTest.php', $row->summary, 'the set-aside file is named');
    $this->assertStringContainsString('placeholder', $row->summary);

    file_put_contents($root . '/' . $test, str_replace(
      "self::assertTrue(TRUE, 'This is TRUE!');",
      "self::assertSame('/contact?recipient=7', ContactLink::to(7));",
      self::UNIT,
    ));
    $filled = new DeclarationAudit($declared, [], $changed, NULL, ['phpcs', 'phpunit'], projectRoot: $root, testsInDiff: TRUE);
    $this->assertSame(CheckState::Satisfied, $this->checkAt($filled, 'test', 'tests_in_diff')?->state, 'a filled-in test counts');
  }

  /**
   * The audit's row for one check at one phase.
   *
   * @param \Droost\Workflow\Evidence\DeclarationAudit $audit
   *   The audit.
   * @param string $phase
   *   The phase.
   * @param string $name
   *   The check's name.
   *
   * @return \Droost\Workflow\Evidence\CheckRecord|null
   *   The row, when there is one.
   */
  private function checkAt(DeclarationAudit $audit, string $phase, string $name): ?CheckRecord {
    foreach ($audit->checks($phase) as $check) {
      if ($check->name === $name) {
        return $check;
      }
    }
    return NULL;
  }

}
