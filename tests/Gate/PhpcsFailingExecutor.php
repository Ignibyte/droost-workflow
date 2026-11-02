<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;

/**
 * An executor whose phpcs fails, saying what the test tells it to (F-399).
 */
final class PhpcsFailingExecutor implements GateExecutorInterface {

  /**
   * Executions per gate name.
   *
   * @var array<string, int>
   */
  public array $executions = [];

  /**
   * Builds the executor.
   *
   * @param string $summary
   *   What phpcs says when it fails; a test changes it to model a fix made
   *   outside the tree.
   */
  public function __construct(public string $summary) {}

  /**
   * {@inheritdoc}
   */
  public function execute(GateSettings $gate, string $projectRoot): GateResult {
    $this->executions[$gate->name] = ($this->executions[$gate->name] ?? 0) + 1;
    return $gate->name === 'phpcs'
      ? new GateResult('phpcs', GateStatus::Failed, 1, 1, $this->summary)
      : new GateResult($gate->name, GateStatus::Passed, 0, 1, 'ok');
  }

}
