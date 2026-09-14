<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Config;

use Droost\Workflow\Config\PhpcsStandard;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * One answer to "is Drupal's standard the right one here", for every asker.
 *
 * `init` decided this alone, from site-level markers only, so a Drupal
 * contrib-module checkout — no coder in its own vendor/, no docroot — was
 * written `standard: "PSR12"` and a valid two-space Drupal class failed with
 * seven errors as an AGENT fault. And the preset bases knew no rule at all,
 * so deleting the lever line handed a plain package the level's `Drupal` and
 * an rc=16. The rule lives here now, and both ask it.
 */
final class PhpcsStandardTest extends WorkflowTestCase {

  /**
   * A plain PHP package is not Drupal, and is held to what phpcs ships.
   */
  public function testThePlainPackageIsHeldToWhatPhpcsShips(): void {
    $root = $this->makeRoot();
    mkdir($root . '/src', 0755, TRUE);
    file_put_contents($root . '/src/Money.php', "<?php\n");
    file_put_contents($root . '/composer.json', '{"name":"acme/money","type":"library"}');

    $this->assertFalse(PhpcsStandard::drupalApplies($root));
    $this->assertSame('PSR12', PhpcsStandard::forProject($root, 'Drupal,DrupalPractice'), 'the level asked for Drupal');
    $this->assertSame('PSR12', PhpcsStandard::forProject($root, 'PSR12'), 'and a standard that is not Drupal is left alone');
    $this->assertSame('PSR2', PhpcsStandard::forProject($root, 'PSR2'));
  }

  /**
   * A module, theme or profile checkout IS Drupal, coder or no coder.
   */
  public function testTheInfoFileAtTheRootSaysDrupal(): void {
    foreach (['module', 'theme', 'profile'] as $type) {
      $root = $this->makeRoot();
      file_put_contents($root . '/acme.info.yml', "name: Acme\ntype: $type\ncore_version_requirement: ^10 || ^11\n");
      $this->assertTrue(PhpcsStandard::drupalApplies($root), "a $type checkout is Drupal");
      $this->assertSame(
        'Drupal,DrupalPractice',
        PhpcsStandard::forProject($root, 'Drupal,DrupalPractice'),
        'and keeps the standard — coder missing is an honest environment fault, a wrong standard is not',
      );
    }
  }

  /**
   * So does a composer type that names one.
   */
  public function testTheComposerTypeSaysDrupal(): void {
    foreach (['drupal-module', 'drupal-theme', 'drupal-profile', 'drupal-custom-module'] as $type) {
      $root = $this->makeRoot();
      file_put_contents($root . '/composer.json', json_encode(['name' => 'acme/x', 'type' => $type]));
      $this->assertTrue(PhpcsStandard::drupalApplies($root), $type);
    }
    $library = $this->makeRoot();
    file_put_contents($library . '/composer.json', '{"name":"acme/x","type":"drupal-library"}');
    $this->assertFalse(PhpcsStandard::drupalApplies($library), 'a JS library shipped for Drupal is not Drupal PHP');
  }

  /**
   * A site under a docroot named ANYTHING is Drupal.
   *
   * `web` and `docroot` are conventions, not the rule — `html` and `public`
   * are in the wild, and `ShellGateExecutor` already analyses `<docroot>/
   * modules/custom` on any of them. This detector agreed only on `web`/
   * `docroot`, so an `html/` site got PSR-12 substituted onto real Drupal.
   */
  public function testTheDocrootSaysDrupalWhateverItIsNamed(): void {
    foreach (['web', 'docroot', 'html', 'public', '.'] as $docroot) {
      $root = $this->makeRoot();
      mkdir($root . '/' . $docroot . '/core/lib', 0755, TRUE);
      file_put_contents($root . '/' . $docroot . '/core/lib/Drupal.php', "<?php\n");
      $this->assertTrue(PhpcsStandard::drupalApplies($root), 'docroot at ' . $docroot);
      $this->assertSame(
        'Drupal,DrupalPractice',
        PhpcsStandard::forProject($root, 'Drupal,DrupalPractice'),
        'so the level\'s Drupal standard stands on it, not PSR-12',
      );
    }

    // Not fooled by a dependency that happens to ship Drupal core's marker.
    $plain = $this->makeRoot();
    mkdir($plain . '/vendor/x/core/lib', 0755, TRUE);
    file_put_contents($plain . '/vendor/x/core/lib/Drupal.php', "<?php\n");
    file_put_contents($plain . '/composer.json', '{"name":"acme/x","type":"library"}');
    $this->assertFalse(PhpcsStandard::drupalApplies($plain), 'a marker under vendor/ is not this project being Drupal');
  }

  /**
   * Coder installed means phpcs can run the standard, whatever this is.
   */
  public function testCoderInstalledSaysDrupal(): void {
    $root = $this->makeRoot();
    mkdir($root . '/vendor/drupal/coder/coder_sniffer/Drupal', 0755, TRUE);
    file_put_contents($root . '/vendor/drupal/coder/coder_sniffer/Drupal/ruleset.xml', '<ruleset/>');

    $this->assertTrue(PhpcsStandard::drupalApplies($root));
  }

  /**
   * Naming Drupal is per entry of the comma list.
   */
  public function testNamesDrupalReadsTheWholeList(): void {
    $this->assertTrue(PhpcsStandard::namesDrupal('Drupal,DrupalPractice'));
    $this->assertTrue(PhpcsStandard::namesDrupal('PSR12, Drupal'));
    $this->assertTrue(PhpcsStandard::namesDrupal('Drupal'));
    $this->assertFalse(PhpcsStandard::namesDrupal('PSR12'));
    $this->assertFalse(PhpcsStandard::namesDrupal('DrupalFoo'));
  }

}
