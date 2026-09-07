<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Config;

use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The front-end trio is scoped like the PHP pair unless told otherwise.
 *
 * Found preparing D70 round 2: a level from `xhigh` up turns eslint, stylelint
 * and prettier on, and the executor scopes them ONLY by their own `paths` —
 * with none, they run unscoped over the repository root. The PHP pair's
 * `paths` already says where the project's own code lives, so an unscoped
 * trio takes them; a trio given its own paths keeps them; a trio the level
 * leaves off is not touched.
 */
class TrioScopeTest extends WorkflowTestCase {

  /**
   * An unscoped trio at max inherits the pair's paths.
   */
  public function testUnscopedTrioTakesThePairsPaths(): void {
    $gates = WorkflowConfig::fromArray([
      'preset' => 'max',
      'gates' => ['phpcs' => ['paths' => 'web/modules/custom,web/themes/custom']],
    ], 'test')->resolvedGates();

    foreach (['eslint', 'stylelint', 'prettier'] as $name) {
      $this->assertTrue($gates[$name]['on'] ?? NULL, "$name is on at max");
      $this->assertSame('web/modules/custom,web/themes/custom', $gates[$name]['paths'] ?? NULL, "$name is scoped like the pair");
    }
  }

  /**
   * Explicit trio paths win over the inherited ones, per gate.
   */
  public function testExplicitTrioPathsAreKept(): void {
    $gates = WorkflowConfig::fromArray([
      'preset' => 'max',
      'gates' => [
        'phpcs' => ['paths' => 'web/modules/custom'],
        'stylelint' => ['paths' => 'web/themes/custom/css'],
      ],
    ], 'test')->resolvedGates();

    $this->assertSame('web/themes/custom/css', $gates['stylelint']['paths'] ?? NULL, 'its own paths, untouched');
    $this->assertSame('web/modules/custom', $gates['eslint']['paths'] ?? NULL, 'the unscoped sibling still inherits');
    $this->assertSame('web/modules/custom', $gates['prettier']['paths'] ?? NULL);
  }

  /**
   * Nothing to inherit, or a trio the level leaves off: no change.
   */
  public function testNoInheritanceWithoutPairPathsOrWhenOff(): void {
    $bare = WorkflowConfig::fromArray(['preset' => 'max'], 'test')->resolvedGates();
    $this->assertArrayNotHasKey('paths', $bare['eslint'], 'no pair paths — nothing to take');

    $high = WorkflowConfig::fromArray([
      'preset' => 'high',
      'gates' => ['phpcs' => ['paths' => 'web/modules/custom']],
    ], 'test')->resolvedGates();
    $this->assertFalse($high['eslint']['on'] ?? NULL, 'high leaves the trio off');
    $this->assertArrayNotHasKey('paths', $high['eslint'], 'an off gate is not rescoped');
  }

}
