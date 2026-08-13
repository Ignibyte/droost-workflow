<?php

declare(strict_types=1);

namespace Drupal\droost_workflow\Drush\Commands;

use Drupal\droost_workflow\Config\Mode;
use Drupal\droost_workflow\Driver\BootedSiteDriver;
use Drupal\droost_workflow\Gate\ShellGateExecutor;
use Drupal\droost_workflow\Cli\CliProcess;
use Drupal\droost_workflow\Mode\Outcome;
use Drupal\droost_workflow\Mode\RunStateOnlySink;
use Drupal\droost_workflow\WorkflowFacade;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The live-site surface.
 *
 * The same facade the standalone bin calls, with one thing swapped: a driver
 * that can actually render a page. Everything these commands do beyond
 * parsing arguments and printing JSON happens in the facade, because parity
 * between the surfaces has to be structural — there is no second
 * implementation here that could drift from the CLI's.
 *
 * Registered by attribute autodiscovery. droost registers zero Drush commands
 * in YAML across nine command classes, and this follows that.
 */
final class WorkflowCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Constructs a WorkflowCommands.
   *
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $httpKernel
   *   The site's kernel, for the rendered check.
   */
  public function __construct(
    #[Autowire(service: 'http_kernel')]
    private readonly HttpKernelInterface $httpKernel,
  ) {
    parent::__construct();
  }

  /**
   * Reports what this repo resolves to and where a run has got to.
   *
   * @param array<string, mixed> $options
   *   The command options.
   *
   * @return int
   *   The exit code.
   */
  #[CLI\Command(name: 'droost:workflow:status', aliases: ['dwfst'])]
  #[CLI\Option(name: 'project', description: 'The repository to act on (defaults to the working directory).')]
  #[CLI\Usage(
    name: 'drush droost:workflow:status',
    description: 'Show the resolved levers and the current run.',
  )]
  public function status(array $options = ['project' => NULL]): int {
    $root = $this->projectRoot($options);
    $this->output()->writeln(
      $this->encode($this->facade()->status($root)),
    );
    return self::EXIT_SUCCESS;
  }

  /**
   * Starts a run, or advances the one in progress by one phase.
   *
   * @param array<string, mixed> $options
   *   The command options.
   *
   * @return int
   *   The exit code.
   */
  #[CLI\Command(name: 'droost:workflow:run', aliases: ['dwfr'])]
  #[CLI\Option(name: 'project', description: 'The repository to act on (defaults to the working directory).')]
  #[CLI\Usage(
    name: 'drush droost:workflow:run',
    description: 'Advance the workflow by one phase, against this site.',
  )]
  public function run(array $options = ['project' => NULL]): int {
    $outcome = $this->facade()->run($this->projectRoot($options));
    $this->output()->writeln($this->encode($outcome->toArray()));
    // A pause is not a failure. Only a blocked run exits non-zero, and
    // whether that failure is retryable or terminal lives in the envelope's
    // retries.exhausted, not the exit code.
    return $outcome->outcome === Outcome::Failed
      ? self::EXIT_FAILURE
      : self::EXIT_SUCCESS;
  }

  /**
   * Answers a paused run's question.
   *
   * @param string $answer
   *   What to answer.
   * @param array<string, mixed> $options
   *   The command options.
   *
   * @return int
   *   The exit code.
   */
  #[CLI\Command(name: 'droost:workflow:answer', aliases: ['dwfa'])]
  #[CLI\Argument(name: 'answer', description: 'The answer.')]
  #[CLI\Option(name: 'project', description: 'The repository to act on (defaults to the working directory).')]
  #[CLI\Usage(
    name: 'drush droost:workflow:answer "yes, continue"',
    description: 'Answer the question a paired run is waiting on.',
  )]
  public function answer(
    string $answer,
    array $options = ['project' => NULL],
  ): int {
    $this->facade()->answer($this->projectRoot($options), $answer);
    $this->output()->writeln('answered');
    return self::EXIT_SUCCESS;
  }

  /**
   * Swaps a paired run to automated so it finishes unattended.
   *
   * @param string $mode
   *   The mode to switch to.
   * @param array<string, mixed> $options
   *   The command options.
   *
   * @return int
   *   The exit code.
   */
  #[CLI\Command(name: 'droost:workflow:swap', aliases: ['dwfsw'])]
  #[CLI\Argument(name: 'mode', description: 'The mode to switch to.')]
  #[CLI\Option(name: 'project', description: 'The repository to act on (defaults to the working directory).')]
  #[CLI\Usage(
    name: 'drush droost:workflow:swap automated',
    description: 'Stop pausing at gates and finish the run unattended.',
  )]
  public function swap(
    string $mode,
    array $options = ['project' => NULL],
  ): int {
    $to = Mode::tryFrom($mode);
    if ($to === NULL) {
      $this->output()->writeln(sprintf(
        'Unknown mode "%s" — expected %s.',
        $mode,
        implode(' or ', Mode::names()),
      ));
      return self::EXIT_FAILURE;
    }
    $this->facade()->swap($this->projectRoot($options), $to);
    $this->output()->writeln('swapped to ' . $to->value);
    return self::EXIT_SUCCESS;
  }

  /**
   * A facade wired for the live surface.
   *
   * @return \Drupal\droost_workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(): WorkflowFacade {
    $clock = static fn (): int => (int) (hrtime(TRUE) / 1_000_000);
    return new WorkflowFacade(
      new ShellGateExecutor(CliProcess::run(...), $clock),
      new BootedSiteDriver($this->httpKernel, $clock),
      new RunStateOnlySink(),
      static fn (): string => date('c'),
      static fn (): string => 'run-' . bin2hex(random_bytes(6)),
    );
  }

  /**
   * The repository these commands act on.
   *
   * @param array<string, mixed> $options
   *   The command options.
   *
   * @return string
   *   The project root.
   */
  private function projectRoot(array $options): string {
    $root = $options['project'] ?? NULL;
    if (is_string($root) && $root !== '') {
      return $root;
    }
    $cwd = getcwd();
    return $cwd === FALSE ? '.' : $cwd;
  }

  /**
   * Encodes a document for output.
   *
   * @param array<string, mixed> $document
   *   The document.
   *
   * @return string
   *   Pretty JSON.
   */
  private function encode(array $document): string {
    try {
      return json_encode(
        $document,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
      );
    }
    catch (\JsonException $e) {
      return '{"error":"' . addslashes($e->getMessage()) . '"}';
    }
  }

}
