<?php

declare(strict_types=1);

use Denosys\Database\Exceptions\InvalidRecord;
use Denosys\Database\Record\RecordMetadata;
use Denosys\Database\Record\ValueCaster;
use PHPUnit\Framework\TestCase;
use RecordFixtures\Account;
use RecordFixtures\Money;
use RecordFixtures\Status;

require_once __DIR__ . '/fixtures.php';

final class CastsTest extends TestCase
{
    private ValueCaster $caster;
    private RecordMetadata $meta;

    protected function setUp(): void
    {
        $this->caster = new ValueCaster('Y-m-d H:i:s');
        $this->meta = RecordMetadata::of(Account::class);
    }

    private function read(string $property, mixed $value): mixed
    {
        return $this->caster->fromDatabase($this->meta, $this->meta->properties[$property], $value);
    }

    private function write(string $property, mixed $value): mixed
    {
        return $this->caster->toDatabase($this->meta->properties[$property], $value);
    }

    public function testScalarsAreConvertedFromTheirColumnValues(): void
    {
        self::assertSame(7, $this->read('count', '7'));
        self::assertSame(1.5, $this->read('rate', '1.5'));
        self::assertFalse($this->read('active', 0));
        self::assertTrue($this->read('active', '1'));
        self::assertSame(1, $this->write('active', true));
        self::assertSame(0, $this->write('active', false));
    }

    public function testDatesAreReadAndWrittenInUtc(): void
    {
        $date = $this->read('openedAt', '2026-01-02 03:04:05');

        self::assertInstanceOf(DateTimeImmutable::class, $date);
        self::assertSame('2026-01-02T03:04:05+00:00', $date->format(DATE_ATOM));
        self::assertSame('2026-01-02 01:04:05', $this->write('openedAt', new DateTimeImmutable('2026-01-02 03:04:05', new DateTimeZone('+02:00'))));
        self::assertNull($this->read('openedAt', null));
        self::assertNull($this->write('openedAt', null));
    }

    public function testBackedEnumsUseTheirValues(): void
    {
        self::assertSame(Status::Closed, $this->read('status', 'closed'));
        self::assertSame('active', $this->write('status', Status::Active));
    }

    public function testIntegerEnumsReadNumericText(): void
    {
        self::assertSame(\RecordFixtures\Priority::High, $this->read('priority', '2'));
        self::assertSame(1, $this->write('priority', \RecordFixtures\Priority::Low));
    }

    public function testAnUnknownEnumValueIsRefused(): void
    {
        $this->expectException(InvalidRecord::class);
        $this->expectExceptionMessageMatches('/Account::\$status/');

        $this->read('status', 'gone');
    }

    public function testCastWithConvertsBothWays(): void
    {
        $money = $this->read('balance', '10.25');

        self::assertInstanceOf(Money::class, $money);
        self::assertSame(1025, $money->cents);
        self::assertSame('3.10', $this->write('balance', new Money(310)));
    }

    public function testNullIntoANonNullablePropertyIsRefused(): void
    {
        $this->expectException(InvalidRecord::class);
        $this->expectExceptionMessageMatches('/Account::\$count.*NULL/');

        $this->read('count', null);
    }

    public function testStringPropertyKeepsDecimalText(): void
    {
        self::assertSame('10.50', $this->read('amount', '10.50'));
        self::assertSame('10.50', $this->write('amount', '10.50'));
    }

    public function testIntegersOutsideTheRangeAreRefused(): void
    {
        foreach (['9223372036854775808', '-9223372036854775809'] as $value) {
            try {
                $this->read('count', $value);
                self::fail("{$value} was read");
            } catch (InvalidRecord) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(PHP_INT_MAX, $this->read('count', (string) PHP_INT_MAX));
        self::assertSame(PHP_INT_MIN, $this->read('count', (string) PHP_INT_MIN));
    }

    public function testAnImpossibleDateIsRefused(): void
    {
        $this->expectException(InvalidRecord::class);

        $this->read('openedAt', '2000-02-30 00:00:00');
    }
}
