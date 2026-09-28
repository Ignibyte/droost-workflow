<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;

/**
 * An executor that keeps the settings each gate arrived with (F-139).
 */
final class SettingsRecordingExecutor implements GateExecutorInterface {

  /**
   * The settings each gate arrived with, by name.
   *
   * @var array<string, \Droost\Workflow\Config\GateSettings>
   */
  private array $settings = [];

  /**
   * {@inheritdoc}
   */
  public function execute(GateSettings $gate, string $projectRoot): GateResult {
    $this->settings[$gate->name] = $gate;
    return new GateResult($gate->name, GateStatus::Passed, 0, 1, 'ok');
  }

  /**
   * A lever as the named gate received it, or NULL when it never ran.
   *
   * @param string $gate
   *   The gate name.
   * @param string $lever
   *   The lever.
   *
   * @return int|string|bool|null
   *   The value the gate saw.
   */
  public function option(string $gate, string $lever): int|string|bool|null {
    return isset($this->settings[$gate]) ? $this->settings[$gate]->option($lever) : NULL;
  }

}
