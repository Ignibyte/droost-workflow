<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Gate\ParityReference;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\WorkflowFacade;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The parity reference as one digest a run is held to (F-144).
 */
#[CoversClass(ParityReference::class)]
final class ParityReferenceTest extends WorkflowTestCase {

  /**
   * Every change to the reference moves the digest, and nothing else does.
   */
  public function testTheDigestFollowsEveryFileAndNothingElse(): void {
    $root = $this->makeRoot();
    $this->assertSame(ParityReference::ABSENT, ParityReference::digest($root, 'droost/parity'), 'no directory');
    mkdir($root . '/droost/parity', 0755, TRUE);
    $this->assertSame(ParityReference::ABSENT, ParityReference::digest($root, 'droost/parity'), 'an empty one');

    file_put_contents($root . '/droost/parity/manifest.json', '{"routes": ["/"]}');
    file_put_contents($root . '/droost/parity/home.json', '{"texts": []}');
    $first = ParityReference::digest($root, 'droost/parity');
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
    $this->assertSame($first, ParityReference::digest($root, 'droost/parity'), 'read twice, the same');
    $this->assertSame($first, ParityReference::digest($root, $root . '/droost/parity'), 'absolute or relative, the same');
    touch($root . '/droost/parity/home.json', time() + 60);
    $this->assertSame($first, ParityReference::digest($root, 'droost/parity'), 'a file touched is not a file changed');

    file_put_contents($root . '/droost/parity/home.json', '{"texts": [1]}');
    $edited = ParityReference::digest($root, 'droost/parity');
    $this->assertNotSame($first, $edited, 'an edit');
    file_put_contents($root . '/droost/parity/camps.json', '{}');
    $added = ParityReference::digest($root, 'droost/parity');
    $this->assertNotSame($edited, $added, 'a file added');
    rename($root . '/droost/parity/camps.json', $root . '/droost/parity/rinks.json');
    $this->assertNotSame($added, ParityReference::digest($root, 'droost/parity'), 'a file renamed');
    unlink($root . '/droost/parity/rinks.json');
    $this->assertSame($edited, ParityReference::digest($root, 'droost/parity'), 'and removed again, as before');
  }

  /**
   * The lever names the directory; none names the default.
   */
  public function testTheLeverNamesTheDirectory(): void {
    $this->assertSame('droost/parity', ParityReference::directory(NULL));
    $this->assertSame('droost/parity', ParityReference::directory(''));
    $this->assertSame('design/refs', ParityReference::directory('design/refs'));
  }

  /**
   * A run freezes the digest with the parity gate's levers, and only then.
   */
  public function testRunFreezesTheDigestWithTheGate(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: agentic\nseekers:\n  on: false\ngates:\n  parity: { on: true, reference: design/refs }\n");
    mkdir($root . '/design/refs', 0755, TRUE);
    file_put_contents($root . '/design/refs/manifest.json', '{"routes": ["/"]}');
    $this->facade()->run($root);

    $this->assertSame(
      ParityReference::digest($root, 'design/refs'),
      $this->frozenParity($root)['reference_digest'] ?? NULL,
      'the reference the lever names, as the run began',
    );

    $off = $this->makeRootWithConfig("preset: custom\nmode: agentic\nseekers:\n  on: false\ngates:\n  parity: { on: false }\n");
    $this->facade()->run($off);
    $this->assertArrayNotHasKey('reference_digest', $this->frozenParity($off), 'a gate that is off holds no reference');
  }

  /**
   * The parity gate's levers as run.json froze them.
   *
   * @param string $root
   *   The project.
   *
   * @return array<array-key, mixed>
   *   The levers.
   */
  private function frozenParity(string $root): array {
    $document = json_decode((string) file_get_contents($root . '/droost/droost-workflow/run.json'), TRUE);
    $this->assertIsArray($document);
    $gates = $document['resolved_gates'] ?? NULL;
    $this->assertIsArray($gates);
    $parity = $gates['parity'] ?? NULL;
    $this->assertIsArray($parity);
    return $parity;
  }

  /**
   * A facade whose every gate passes.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(): WorkflowFacade {
    return new WorkflowFacade(
      new class() implements GateExecutorInterface {

        /**
         * {@inheritdoc}
         */
        public function execute(GateSettings $gate, string $projectRoot): GateResult {
          return new GateResult($gate->name, GateStatus::Passed, 0, 1, 'ok');
        }

      },
      new NullSiteDriver(),
      new RunStateOnlySink(),
      static fn (): string => '2026-09-29T12:00:00+00:00',
      static fn (): string => 'run-parity',
    );
  }

}
