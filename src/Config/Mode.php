<?php

declare(strict_types=1);

namespace Droost\Workflow\Config;

/**
 * How much the human is in the loop.
 */
enum Mode: string {

  // Run plan through complete unattended. The software factory.
  case Automated = 'automated';

  // Pause at every phase gate and ask before advancing. Control.
  case Pair = 'pair';

  /**
   * The mode names a config file may use.
   *
   * @return list<string>
   *   The backing values, in declaration order.
   */
  public static function names(): array {
    return array_map(static fn (self $m): string => $m->value, self::cases());
  }

}
