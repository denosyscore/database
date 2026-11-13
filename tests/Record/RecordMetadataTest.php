<?php

declare(strict_types=1);

use Denosys\Database\Exceptions\InvalidRecord;
use Denosys\Database\Record\RecordMetadata;
use PHPUnit\Framework\TestCase;
use RecordFixtures\BlogPost;
use RecordFixtures\Box;
use RecordFixtures\Category;
use RecordFixtures\NoId;
use RecordFixtures\Person;
use RecordFixtures\UnionTyped;
use RecordFixtures\Untyped;

require_once __DIR__ . '/fixtures.php';

final class RecordMetadataTest extends TestCase
{
    public function testTableNameDefaultsToSnakePlural(): void
    {
        self::assertSame('blog_posts', RecordMetadata::of(BlogPost::class)->table);
        self::assertSame('categories', RecordMetadata::of(Category::class)->table);
        self::assertSame('boxes', RecordMetadata::of(Box::class)->table);
        self::assertNull(RecordMetadata::of(BlogPost::class)->connection);
    }

    public function testTableAttributeOverridesName(): void
    {
        $meta = RecordMetadata::of(Person::class);

        self::assertSame('people', $meta->table);
        self::assertSame('other', $meta->connection);
    }

    public function testColumnsAreSnakeCaseOfProperties(): void
    {
        $meta = RecordMetadata::of(BlogPost::class);

        self::assertSame('first_name', $meta->column('firstName'));
        self::assertSame('fname', $meta->column('nickName'));
        self::assertSame('title', $meta->properties['title']->column);
        self::assertSame('string', $meta->properties['title']->type);
        self::assertFalse($meta->properties['title']->nullable);
        self::assertFalse($meta->properties['title']->hasDefault);
        self::assertTrue($meta->properties['id']->nullable);
        self::assertTrue($meta->properties['id']->hasDefault);
    }

    public function testIdIsTheIdAttributeOrThePropertyNamedId(): void
    {
        self::assertSame('id', RecordMetadata::of(BlogPost::class)->id->name);
        self::assertSame('code', RecordMetadata::of(Person::class)->id->name);
    }

    public function testStaticAndNonPublicPropertiesAreNotColumns(): void
    {
        self::assertSame(['id', 'title', 'firstName', 'nickName'], array_keys(RecordMetadata::of(BlogPost::class)->properties));
    }

    public function testUnionTypedPropertyIsRefused(): void
    {
        $this->expectException(InvalidRecord::class);
        $this->expectExceptionMessageMatches('/UnionTyped::\$value/');

        RecordMetadata::of(UnionTyped::class);
    }

    public function testUntypedPropertyIsRefused(): void
    {
        $this->expectException(InvalidRecord::class);
        $this->expectExceptionMessageMatches('/Untyped::\$value/');

        RecordMetadata::of(Untyped::class);
    }

    public function testRecordWithoutAnIdIsRefused(): void
    {
        $this->expectException(InvalidRecord::class);
        $this->expectExceptionMessageMatches('/NoId/');

        RecordMetadata::of(NoId::class);
    }

    public function testUnknownPropertyHasNoColumn(): void
    {
        $this->expectException(InvalidRecord::class);

        RecordMetadata::of(BlogPost::class)->column('missing');
    }
}
