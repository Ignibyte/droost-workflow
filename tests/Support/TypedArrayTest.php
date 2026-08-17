<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Support;

use Droost\Workflow\Support\DataError;
use Droost\Workflow\Support\TypedArray;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The typed boundary every decoded document passes through.
 */
class TypedArrayTest extends TestCase {

  /**
   * Each accessor returns the value when the type is right.
   */
  public function testAccessorsReturnCorrectlyTypedValues(): void {
    $node = TypedArray::authored([
      'name' => 'phpcs',
      'on' => TRUE,
      'level' => 6,
      'words' => ['a', 'b'],
      'nested' => ['deep' => 'value'],
    ]);

    $this->assertSame('phpcs', $node->string('name'));
    $this->assertTrue($node->bool('on'));
    $this->assertSame(6, $node->int('level'));
    $this->assertSame(['a', 'b'], $node->stringList('words'));
    $this->assertSame('value', $node->child('nested')->string('deep'));
    $this->assertSame(6, $node->intOrString('level'));
    $this->assertSame('phpcs', $node->intOrString('name'));
  }

  /**
   * An absent key is reported as absent and yields the default.
   */
  public function testAbsentKeysFallBackToDefaults(): void {
    $node = TypedArray::authored([]);

    $this->assertFalse($node->has('nope'));
    $this->assertNull($node->optionalString('nope'));
    $this->assertSame('fallback', $node->optionalString('nope', 'fallback'));
    $this->assertTrue($node->optionalBool('nope', TRUE));
    $this->assertSame(7, $node->optionalInt('nope', 7));
    $this->assertNull($node->optionalChild('nope'));
    $this->assertSame(['x'], $node->optionalStringList('nope', ['x']));
  }

  /**
   * A required key that is absent names itself.
   */
  public function testMissingRequiredKeyNamesItself(): void {
    $this->expectException(DataError::class);
    $this->expectExceptionMessage('level is required but missing');
    TypedArray::authored([])->int('level');
  }

  /**
   * In an authored document a blank value is a value, and a wrong one.
   *
   * The regression guard for the security finding: collapsing null into
   * "absent" here made every blank lever in a hand-written config file get
   * discarded without a word.
   */
  public function testAuthoredDocumentRejectsExplicitNull(): void {
    $node = TypedArray::authored(['mode' => NULL]);

    $this->assertTrue($node->has('mode'));
    $this->expectException(DataError::class);
    $this->expectExceptionMessage('mode must be a string, got null');
    $node->optionalString('mode', 'automated');
  }

  /**
   * In a serialized document a null is this package's own "unset".
   *
   * The regression guard for the state finding: treating null as present here
   * made every state file unreadable by the code that wrote it.
   */
  public function testSerializedDocumentTreatsExplicitNullAsUnset(): void {
    $node = TypedArray::serialized([
      'mode_override' => NULL,
      'awaiting' => NULL,
    ]);

    $this->assertFalse($node->has('mode_override'));
    $this->assertNull($node->optionalString('mode_override'));
    $this->assertNull($node->optionalChild('awaiting'));
    $this->assertSame('x', $node->optionalString('mode_override', 'x'));
  }

  /**
   * The document's null rule is inherited by nested levels.
   */
  public function testNullRuleIsInheritedByChildren(): void {
    $authored = TypedArray::authored(['gates' => ['on' => NULL]]);
    $serialized = TypedArray::serialized(['gates' => ['on' => NULL]]);

    $this->assertTrue($authored->child('gates')->has('on'));
    $this->assertFalse($serialized->child('gates')->has('on'));
  }

  /**
   * A wrong type names the dotted path, not just the key.
   */
  public function testNestedErrorNamesTheDottedPath(): void {
    $node = TypedArray::authored([
      'gates' => ['phpstan' => ['level' => ['nope']]],
    ]);

    $this->expectException(DataError::class);
    $this->expectExceptionMessage('gates.phpstan.level must be an integer');
    $node->child('gates')->child('phpstan')->int('level');
  }

  /**
   * A wrong scalar is quoted back so the reader can see it.
   */
  public function testWrongTypeShowsTheOffendingValue(): void {
    $this->expectException(DataError::class);
    $this->expectExceptionMessage('on must be true or false, got the string "yes"');
    TypedArray::authored(['on' => 'yes'])->bool('on');
  }

  /**
   * Each accessor rejects the types it is not for.
   *
   * @param string $accessor
   *   The method to call.
   * @param mixed $value
   *   The value to store under "k".
   */
  #[DataProvider('wrongTypeCases')]
  public function testAccessorsRejectWrongTypes(
    string $accessor,
    mixed $value,
  ): void {
    $node = TypedArray::authored(['k' => $value]);
    $this->expectException(DataError::class);
    // Dispatched explicitly rather than by variable method name: a dynamic
    // call would be untypeable at level max, and the only way to keep it
    // would be a suppression, which this project does not allow.
    match ($accessor) {
      'string' => $node->string('k'),
      'bool' => $node->bool('k'),
      'int' => $node->int('k'),
      'stringList' => $node->stringList('k'),
      'child' => $node->child('k'),
      'intOrString' => $node->intOrString('k'),
      default => $this->fail('Unknown accessor: ' . $accessor),
    };
  }

  /**
   * Accessor and value pairs that must be refused.
   *
   * @return list<array{string, mixed}>
   *   Accessor name and an unacceptable value.
   */
  public static function wrongTypeCases(): array {
    return [
      ['string', 1],
      ['string', TRUE],
      ['bool', 'true'],
      ['bool', 1],
      ['int', '6'],
      ['int', 1.5],
      ['stringList', 'a'],
      ['stringList', ['k' => 'v']],
      ['stringList', [1, 2]],
      ['child', 'scalar'],
      ['intOrString', TRUE],
      ['intOrString', []],
    ];
  }

  /**
   * A range check names the bound it broke.
   */
  public function testIntInRangeReportsTheBound(): void {
    $node = TypedArray::authored(['min' => 101]);
    $this->expectException(DataError::class);
    $this->expectExceptionMessage('min must be between 0 and 100, got 101');
    $node->intInRange('min', 0, 100);
  }

  /**
   * A project root must be a real directory that is not the filesystem root.
   *
   * @param string $root
   *   The candidate root.
   */
  #[DataProvider('badProjectRoots')]
  public function testBadProjectRootsAreRefused(string $root): void {
    $this->expectException(\InvalidArgumentException::class);
    TypedArray::requireProjectRoot($root);
  }

  /**
   * Roots that must never be accepted.
   *
   * @return list<array{string}>
   *   The candidates.
   */
  public static function badProjectRoots(): array {
    return [[''], ['   '], ['/'], ['//'], ['/no/such/path/anywhere']];
  }

  /**
   * A good root comes back without its trailing slash.
   */
  public function testGoodProjectRootIsNormalised(): void {
    $this->assertSame(
      rtrim(sys_get_temp_dir(), '/'),
      TypedArray::requireProjectRoot(sys_get_temp_dir() . '/'),
    );
  }

}
