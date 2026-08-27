<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Config;

use Droost\Workflow\Config\Mode;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The mode vocabulary, and the old spellings it still answers to.
 *
 * The rename from automated/pair to agentic/interactive happened after the
 * installer had already written the old names into every site's lever file and
 * into the run record of every run those sites started. These tests exist so
 * that stays true: the aliases are load-bearing, not a courtesy.
 */
class ModeTest extends WorkflowTestCase {

  /**
   * The canonical names resolve to themselves.
   */
  public function testCanonicalNamesResolve(): void {
    $this->assertSame(Mode::Agentic, Mode::resolve('agentic'));
    $this->assertSame(Mode::Interactive, Mode::resolve('interactive'));
  }

  /**
   * The superseded names resolve to what they became.
   *
   * `pair` maps to Interactive rather than being retired: its hold points
   * were right and only its question was wrong, so the alias is an upgrade
   * at the same moments rather than a redirect to a different lever.
   */
  public function testLegacyNamesResolve(): void {
    $this->assertSame(Mode::Agentic, Mode::resolve('automated'));
    $this->assertSame(Mode::Interactive, Mode::resolve('pair'));
  }

  /**
   * A name that is neither canonical nor an alias resolves to nothing.
   */
  public function testUnknownNameResolvesToNull(): void {
    $this->assertNull(Mode::resolve('solo'));
    $this->assertNull(Mode::resolve(''));
  }

  /**
   * Legacy names are identifiable, so a surface can say so.
   */
  public function testLegacyNamesAreIdentifiable(): void {
    $this->assertTrue(Mode::isLegacyName('automated'));
    $this->assertTrue(Mode::isLegacyName('pair'));
    $this->assertFalse(Mode::isLegacyName('agentic'));
    $this->assertFalse(Mode::isLegacyName('solo'));
  }

  /**
   * The advice list and the tolerated list are deliberately different.
   *
   * An error message that listed everything parseable would tell a reader to
   * write a name we are trying to retire.
   */
  public function testNamesAdviseWhileAcceptedTolerates(): void {
    $this->assertSame(['agentic', 'interactive'], Mode::names());
    $this->assertSame(
      ['agentic', 'interactive', 'automated', 'pair'],
      Mode::accepted(),
    );
  }

  /**
   * Exactly one mode holds the run to converse.
   */
  public function testOnlyInteractiveHolds(): void {
    $this->assertTrue(Mode::Interactive->holdsForConversation());
    $this->assertFalse(Mode::Agentic->holdsForConversation());
  }

}
