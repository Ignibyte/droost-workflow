<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Driver;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Driver\FreshProcessSiteDriver;
use Droost\Workflow\Gate\GateStatus;
use PHPUnit\Framework\TestCase;

/**
 * The rendered check through a fresh process.
 */
class FreshProcessSiteDriverTest extends TestCase {

  /**
   * A temporary project root with a fake drush binary.
   */
  private string $root;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->root = sys_get_temp_dir() . '/droost-fresh-' . uniqid();
    mkdir($this->root . '/vendor/bin', 0755, TRUE);
    file_put_contents($this->root . '/vendor/bin/drush', "#!/bin/sh\n");
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    @unlink($this->root . '/vendor/bin/drush');
    @rmdir($this->root . '/vendor/bin');
    @rmdir($this->root . '/vendor');
    @rmdir($this->root);
    parent::tearDown();
  }

  /**
   * A probe that answers "passed" is a pass; the invocation names the routes.
   */
  public function testPassingProbePasses(): void {
    $seen = [];
    $answer = json_encode([
      'gate' => 'rendered_check',
      'status' => 'passed',
      'summary' => '2 route(s) rendered',
      'findings' => [],
    ]);
    $driver = new FreshProcessSiteDriver(
      static function (array $argv, string $cwd, int $timeout) use (&$seen, $answer): array {
        $seen = ['argv' => $argv, 'cwd' => $cwd, 'timeout' => $timeout];
        return [0, "Some drush notice\n" . $answer . "\n", ''];
      },
      static fn (): int => 0,
    );

    $result = $driver->run(new GateSettings('rendered_check', TRUE, ['routes' => '/, /user/login']), $this->root);

    $this->assertSame(GateStatus::Passed, $result->status);
    $this->assertSame('2 route(s) rendered', $result->summary);
    $this->assertSame([$this->root . '/vendor/bin/drush', 'droost:workflow:render-probe', '/,/user/login'], $seen['argv']);
    $this->assertSame($this->root, $seen['cwd']);
    $this->assertSame(FreshProcessSiteDriver::DEFAULT_TIMEOUT, $seen['timeout']);
    $this->assertStringContainsString('render-probe /,/user/login', (string) $result->invocation);
  }

  /**
   * A probe that answers "failed" carries its findings, origin included.
   */
  public function testFailingProbeCarriesTheFindings(): void {
    $finding = [
      'route' => '/',
      'status' => NULL,
      'problem' => 'threw Error: Call to a member function access() on null at BlockAccessControlHandler.php:71',
      'trace' => ['Drupal\block\BlockAccessControlHandler->checkAccess()'],
    ];
    $answer = json_encode([
      'status' => 'failed',
      'summary' => '1 of 1 route(s) did not render',
      'findings' => [$finding],
    ]);
    $driver = new FreshProcessSiteDriver(
      static fn (array $argv, string $cwd, int $timeout): array => [1, $answer . "\n", ''],
      static fn (): int => 0,
    );

    $result = $driver->run(new GateSettings('rendered_check', TRUE), $this->root);

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertSame('1 of 1 route(s) did not render', $result->summary);
    $this->assertSame([$finding], $result->findings);
  }

  /**
   * No JSON answer — a fatal, a boot failure, a timeout — is a failed gate.
   */
  public function testNoAnswerFailsWithTheProcessWords(): void {
    $stderr = "PHP Fatal error: Allowed memory size exhausted\n";
    $driver = new FreshProcessSiteDriver(
      static fn (array $argv, string $cwd, int $timeout): array => [255, '', $stderr],
      static fn (): int => 0,
    );

    $result = $driver->run(new GateSettings('rendered_check', TRUE), $this->root);

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertSame('the render probe did not answer (exit 255)', $result->summary);
    $problem = $result->findings[0]['problem'] ?? NULL;
    $this->assertIsString($problem);
    $this->assertStringContainsString('Allowed memory size', $problem);
  }

  /**
   * No drush, or a shell that cannot find it, is tool-missing — never a pass.
   */
  public function testMissingDrushIsToolMissing(): void {
    $driver = new FreshProcessSiteDriver(
      static fn (array $argv, string $cwd, int $timeout): array => [127, '', 'not found'],
      static fn (): int => 0,
    );
    $this->assertSame(GateStatus::ErrorToolMissing, $driver->run(new GateSettings('rendered_check', TRUE), $this->root)->status, 'exit 127 from the shell');

    unlink($this->root . '/vendor/bin/drush');
    $ran = FALSE;
    $driver = new FreshProcessSiteDriver(
      static function (array $argv, string $cwd, int $timeout) use (&$ran): array {
        $ran = TRUE;
        return [0, '', ''];
      },
      static fn (): int => 0,
    );
    $this->assertSame(GateStatus::ErrorToolMissing, $driver->run(new GateSettings('rendered_check', TRUE), $this->root)->status, 'no binary on disk');
    $this->assertFalse($ran, 'nothing is spawned when the binary is absent');
  }

  /**
   * Only rendered_check is this driver's; anything else is refused loudly.
   */
  public function testOnlyRenderedCheck(): void {
    $driver = new FreshProcessSiteDriver(static fn (array $argv, string $cwd, int $timeout): array => [0, '', ''], static fn (): int => 0);
    $this->assertSame(['rendered_check'], $driver->supports());
    $this->assertSame(GateStatus::ErrorToolMissing, $driver->run(new GateSettings('config_clean', TRUE), $this->root)->status);
  }

}
