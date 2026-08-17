<?php

declare(strict_types=1);

namespace Droost\Workflow\Cli;

use Droost\Workflow\Config\ConfigError;
use Droost\Workflow\Config\Mode;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\Pack\PackError;
use Droost\Workflow\State\StateError;
use Droost\Workflow\WorkflowFacade;

/**
 * The standalone surface: five verbs, no Drupal, no booted site.
 *
 * Plain argv rather than a console framework. Five verbs for a machine
 * audience do not justify the dependency, and a typed error rendered to
 * stderr with a non-zero exit is the whole UX contract.
 *
 * This surface always uses NullSiteDriver, so every site-dependent gate
 * comes back "skipped, no site" with its reason attached. That is the point:
 * the CLI is not a degraded live run pretending otherwise, it is a run that
 * says exactly which checks it could not perform.
 */
final class ArgvDispatcher {

  /**
   * Everything went well.
   */
  public const EXIT_OK = 0;

  /**
   * The run itself failed — a gate blocked, or the pipeline stopped.
   */
  public const EXIT_RUN_FAILED = 1;

  /**
   * The invocation or the configuration was wrong.
   */
  public const EXIT_USAGE = 2;

  /**
   * Constructs an ArgvDispatcher.
   *
   * @param callable(string): void $out
   *   Writes a line to standard output.
   * @param callable(string): void $err
   *   Writes a line to standard error.
   * @param callable(): string $clock
   *   Returns an ISO-8601 timestamp.
   * @param callable(): string $ids
   *   Returns a fresh run identifier.
   */
  public function __construct(
    private readonly mixed $out,
    private readonly mixed $err,
    private readonly mixed $clock,
    private readonly mixed $ids,
  ) {}

  /**
   * Runs one invocation.
   *
   * @param list<string> $argv
   *   The arguments, without the script name.
   * @param string $projectRoot
   *   The repository to act on.
   *
   * @return int
   *   The process exit code.
   */
  public function dispatch(array $argv, string $projectRoot): int {
    $verb = $argv[0] ?? '';
    if ($verb === '' || $verb === 'help' || $verb === '--help') {
      $this->usage();
      return $verb === '' ? self::EXIT_USAGE : self::EXIT_OK;
    }

    try {
      return match ($verb) {
        'init' => $this->init($projectRoot),
        'status' => $this->status($projectRoot),
        'run' => $this->run($projectRoot),
        'answer' => $this->answer($projectRoot, $argv),
        'swap' => $this->swap($projectRoot, $argv),
        default => $this->unknown($verb),
      };
    }
    // Every failure this package raises is typed, and each one is already
    // phrased for a human — so the handler prints rather than re-explains.
    catch (ConfigError | StateError | PackError $e) {
      $this->fail($e->getMessage());
      return self::EXIT_USAGE;
    }
    catch (\InvalidArgumentException $e) {
      $this->fail($e->getMessage());
      return self::EXIT_USAGE;
    }
  }

  /**
   * Installs the pack.
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return int
   *   The exit code.
   */
  private function init(string $projectRoot): int {
    $report = $this->facade()->init($projectRoot);
    $this->say($report->summary());
    return self::EXIT_OK;
  }

  /**
   * Reports the levers and the run.
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return int
   *   The exit code.
   */
  private function status(string $projectRoot): int {
    $status = $this->facade()->status($projectRoot);
    $this->say($this->encode($status));
    return self::EXIT_OK;
  }

  /**
   * Advances the run by one phase.
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return int
   *   The exit code.
   */
  private function run(string $projectRoot): int {
    $outcome = $this->facade()->run($projectRoot);
    $this->say($this->encode($outcome->toArray()));

    // A paused run has not failed; it is waiting. Only a genuine failure
    // gets a non-zero exit, so a pair-mode pause does not break a script
    // that treats non-zero as broken. Retryable and terminal failures share
    // the exit code — the difference lives in the envelope's
    // retries.exhausted, where a caller can actually act on it.
    return $outcome->outcome === Outcome::Failed
      ? self::EXIT_RUN_FAILED
      : self::EXIT_OK;
  }

  /**
   * Answers a paused run.
   *
   * @param string $projectRoot
   *   The repository.
   * @param list<string> $argv
   *   The arguments.
   *
   * @return int
   *   The exit code.
   */
  private function answer(string $projectRoot, array $argv): int {
    $text = trim(implode(' ', array_slice($argv, 1)));
    if ($text === '') {
      $this->fail('answer needs the answer: droost-workflow answer "yes"');
      return self::EXIT_USAGE;
    }
    $this->facade()->answer($projectRoot, $text);
    $this->say('answered');
    return self::EXIT_OK;
  }

  /**
   * Swaps the run's mode.
   *
   * @param string $projectRoot
   *   The repository.
   * @param list<string> $argv
   *   The arguments.
   *
   * @return int
   *   The exit code.
   */
  private function swap(string $projectRoot, array $argv): int {
    $name = $argv[1] ?? '';
    $mode = Mode::tryFrom($name);
    if ($mode === NULL) {
      $this->fail(sprintf(
        'swap needs a mode (%s), got "%s"',
        implode(' or ', Mode::names()),
        $name,
      ));
      return self::EXIT_USAGE;
    }
    $this->facade()->swap($projectRoot, $mode);
    $this->say('swapped to ' . $mode->value);
    return self::EXIT_OK;
  }

  /**
   * Reports an unknown verb.
   *
   * @param string $verb
   *   What was asked for.
   *
   * @return int
   *   The exit code.
   */
  private function unknown(string $verb): int {
    $this->fail(sprintf('unknown command "%s"', $verb));
    $this->usage();
    return self::EXIT_USAGE;
  }

  /**
   * Prints how to use this.
   */
  private function usage(): void {
    $this->say(<<<'TXT'
    droost-workflow — the phased, gated pipeline, standalone.

      init             install the .claude pack and a default lever file
      status           what this repo resolves to, and where a run has got to
      run              start a run, or advance it by one phase
      answer <text>    answer a paused run's question
      swap automated   stop pausing at gates and finish unattended

    Every site-dependent gate reports "skipped, no site" here, with its
    reason. That is deliberate: this surface tells you what it could not
    check rather than quietly leaving it out.
    TXT);
  }

  /**
   * A facade wired for the siteless surface.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(): WorkflowFacade {
    return new WorkflowFacade(
      new ShellGateExecutor(
        static function (array $argv, string $cwd, int $timeout): array {
          return CliProcess::run($argv, $cwd, $timeout);
        },
        static fn (): int => (int) (hrtime(TRUE) / 1_000_000),
      ),
      new NullSiteDriver(),
      new RunStateOnlySink(),
      $this->clock,
      $this->ids,
    );
  }

  /**
   * Encodes a document for output.
   *
   * @param array<string, mixed> $document
   *   The document.
   *
   * @return string
   *   Pretty JSON, or a plain error line if it cannot be encoded.
   */
  private function encode(array $document): string {
    try {
      return json_encode(
        $document,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
      );
    }
    catch (\JsonException $e) {
      return '{"error":"could not render the report: '
        . addslashes($e->getMessage()) . '"}';
    }
  }

  /**
   * Writes a line to standard output.
   *
   * @param string $line
   *   The line.
   */
  private function say(string $line): void {
    ($this->out)($line);
  }

  /**
   * Writes a line to standard error.
   *
   * @param string $line
   *   The line.
   */
  private function fail(string $line): void {
    ($this->err)($line);
  }

}
