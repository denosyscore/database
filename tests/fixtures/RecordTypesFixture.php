<?php

declare(strict_types=1);

namespace RecordTypesFixture;

use Denosys\Database\Pagination\Page;
use Denosys\Database\Pagination\Paginated;
use RecordFixtures\Author;
use RecordFixtures\Post;

use function PHPStan\Testing\assertType;

/** Checked by `composer analyse:types`: record queries keep their record type. */
function types(): void
{
    assertType('list<RecordFixtures\\Author>', Author::query()->get());
    assertType('RecordFixtures\\Author|null', Author::query()->first());
    assertType('RecordFixtures\\Author', Author::findOrFail(1));
    assertType('RecordFixtures\\Author|null', Author::find(1));
    assertType('list<RecordFixtures\\Post>', Author::findOrFail(1)->posts()->get());
    assertType('RecordFixtures\\Author|null', Post::findOrFail(1)->author()->get());
    assertType('Denosys\\Database\\Pagination\\Paginated<RecordFixtures\\Author>', Author::query()->paginate(Page::of(1, 10)));
    assertType('Denosys\\Database\\Pagination\\Paginated<string>', Author::query()->paginate(Page::of(1, 10))->map(static fn (Author $a): string => $a->name));
    assertType('list<object>', Author::query()->toBase()->get());
}
