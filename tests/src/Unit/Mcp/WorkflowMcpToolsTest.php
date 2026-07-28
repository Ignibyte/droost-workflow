<?php

declare(strict_types=1);

namespace Drupal\Tests\droost_workflow\Unit\Mcp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The MCP submodule's testable surface (TICKET-136).
 *
 * What this file can and cannot reach, stated plainly because the gap is the
 * point. The two Tool plugins extend droost base classes and carry an
 * `#[Tool]` attribute from the `mcp_server` contrib module, and their
 * `execute()` signature names `Mcp\Server\ClientGateway` from the `mcp/sdk`
 * library. None of those three packages is installable here — `drupal/droost`
 * 1.0.x is unpublished, and the other two exist only inside a real site's tree
 * — so the plugin CLASSES cannot be autoloaded, instantiated or reflected in
 * this package's suite. Their behavioural tests (discovery, a real status call)
 * run in the site context and are recorded at validate.
 *
 * What IS reachable, and therefore tested here: the trait's pure logic, which
 * is where the project-root decision lives, the shipped info.yml, and the
 * plugin SOURCE read as text — enough to pin the structural guarantees (the
 * transport gate, the Fiber shield, the argument dispatch) that no assertion
 * elsewhere would catch if someone quietly removed them.
 */
class WorkflowMcpToolsTest extends TestCase {

  /**
   * The submodule directory.
   */
  private const MODULE = __DIR__ . '/../../../../modules/droost_workflow_mcp';

  /**
   * REQ-001: the submodule declares the three dependencies its classes need.
   *
   * The whole reason this is a SUBMODULE: a plain consumer of the package must
   * never be made to pull the alpha `mcp_server`. If these dependencies moved
   * to the base module's info.yml, every install would.
   */
  public function testInfoYmlDeclaresTheThreeDependencies(): void {
    $info = Yaml::parseFile(self::MODULE . '/droost_workflow_mcp.info.yml');
    $this->assertIsArray($info);
    $this->assertArrayHasKey('dependencies', $info);
    $deps = $info['dependencies'];
    $this->assertIsArray($deps);
    $this->assertSame(
      ['droost:droost', 'mcp_server:mcp_server', 'droost_workflow:droost_workflow'],
      $deps,
      'the submodule owns the mcp_server dependency so the base module never does',
    );
    $this->assertSame('module', $info['type'] ?? NULL);
  }

  /**
   * REQ-001: the BASE module still declares nothing about MCP.
   *
   * The guarantee is only worth something if it is checked from the other side:
   * an edit that "helpfully" added the dependency upstream would silently make
   * every consumer of this package depend on an alpha module.
   */
  public function testTheBaseModuleStaysFreeOfMcp(): void {
    $base = (string) file_get_contents(__DIR__ . '/../../../../droost_workflow.info.yml');
    $this->assertNotSame('', $base);
    $this->assertStringNotContainsString('mcp_server', $base);
    $composer = (string) file_get_contents(__DIR__ . '/../../../../composer.json');
    $this->assertNotSame('', $composer);
    $this->assertStringNotContainsString('mcp_server', $composer);
    $this->assertStringNotContainsString('mcp/sdk', $composer);
  }

  /**
   * REQ-004: the project root is the `project` argument, else the site's own.
   *
   * @param string $named
   *   The `project` argument as the tool coerced it.
   * @param string $default
   *   The site's own root.
   * @param string $expected
   *   The root that must be acted on.
   */
  #[DataProvider('rootCases')]
  public function testResolveRootPrefersTheArgumentThenTheDefault(string $named, string $default, string $expected): void {
    $this->assertSame($expected, FacadeTraitHost::probeResolveRoot($named, $default));
  }

  /**
   * Cases for the root resolution.
   *
   * @return array<string, array{string, string, string}>
   *   Named cases: the argument, the default, the expectation.
   */
  public static function rootCases(): array {
    return [
      'absent falls back to the site root' => ['', '/srv/site', '/srv/site'],
      'named wins' => ['/repos/thing', '/srv/site', '/repos/thing'],
      'empty-string named is treated as absent' => ['', '/srv/site', '/srv/site'],
    ];
  }

  /**
   * REQ-004: an unusable root is REFUSED by name, and never thrown.
   *
   * A thrown exception inside a tool body becomes a JSON-RPC protocol error,
   * which tells the calling agent nothing it can act on; the envelope names the
   * path it could not use, so the agent can correct the argument.
   */
  public function testAnUnusableRootIsNamedInTheRefusal(): void {
    $this->assertTrue(FacadeTraitHost::probeRootIsUsable(sys_get_temp_dir()), 'a real directory is usable');
    $missing = sys_get_temp_dir() . '/droost-workflow-absent-' . bin2hex(random_bytes(4));
    $this->assertFalse(FacadeTraitHost::probeRootIsUsable($missing));
    $message = FacadeTraitHost::probeUnusableRootMessage($missing);
    $this->assertStringContainsString($missing, $message, 'the refusal names the path');
    $this->assertStringContainsString('project', $message, 'and names the argument to fix');
  }

  /**
   * An ordinary file is not a directory: `is_dir`, never `file_exists`.
   *
   * The project root is where `droost.workflow.yml` and `.droost-workflow/`
   * live, so a path that happens to be a FILE is as unusable as an absent one —
   * and `file_exists` would have accepted it.
   */
  public function testAnOrdinaryFileIsRejectedAsProjectRoot(): void {
    $file = tempnam(sys_get_temp_dir(), 'dwf');
    $this->assertIsString($file);
    $this->assertFalse(FacadeTraitHost::probeRootIsUsable($file));
    unlink($file);
  }

  /**
   * REQ-003/005: the structural guarantees of the run tool, read from source.
   *
   * These cannot be asserted by executing the plugin here (its parent classes
   * are not autoloadable), and each is the kind of line a refactor removes
   * without any test noticing:
   *
   * - the transport gate is the FIRST thing `execute()` does, so an
   *   HTTP-exposed server can never drive a run;
   * - the body is wrapped in `shielded()`, without which a rendered check
   *   suspends the fiber and the SDK silently drops the response;
   * - `answer` and `swap` are arguments of this one tool, each reaching its own
   *   facade method, and an unknown mode is refused rather than defaulted.
   */
  public function testTheRunToolKeepsItsStructuralGuarantees(): void {
    $src = (string) file_get_contents(self::MODULE . '/src/Plugin/Tool/WorkflowRun.php');
    $this->assertNotSame('', $src);
    $this->assertStringContainsString('extends DestructiveToolBase', $src);
    $this->assertStringContainsString('$blocked = $this->requireCliTransport();', $src);
    $this->assertStringContainsString('self::shielded(', $src);
    foreach (['$facade->answer(', '$facade->swap(', '$facade->run('] as $call) {
      $this->assertStringContainsString($call, $src, "the run tool reaches {$call}");
    }
    $this->assertStringContainsString('Mode::tryFrom($swap)', $src, 'an unknown mode is refused, not coerced');
    // The gate must precede the work: a shield that ran before the transport
    // check would execute a run for an HTTP caller and only then refuse.
    $shield = strpos($src, 'self::shielded(');
    $gate = strpos($src, 'requireCliTransport');
    $this->assertIsInt($shield);
    $this->assertIsInt($gate);
    $this->assertLessThan($shield, $gate, 'the transport gate precedes the shielded body');
  }

  /**
   * REQ-002: the status tool is read-only, unshielded and ungated.
   *
   * It loads two files and returns them: shielding a non-rendering read would
   * be cargo-culted ceremony, and gating it on the transport would make the
   * levers unreadable over HTTP for no safety gain.
   */
  public function testTheStatusToolIsReadOnlyAndUnshielded(): void {
    $src = (string) file_get_contents(self::MODULE . '/src/Plugin/Tool/WorkflowStatus.php');
    $this->assertNotSame('', $src);
    $this->assertStringContainsString('extends DroostToolBase', $src);
    $this->assertStringContainsString('readOnly: TRUE', $src);
    $this->assertStringContainsString('$this->facade()->status($root)', $src);
    $this->assertStringNotContainsString('shielded', $src, 'a read that renders nothing needs no Fiber shield');
    $this->assertStringNotContainsString('requireCliTransport', $src, 'a read is not transport-gated');
  }

}
