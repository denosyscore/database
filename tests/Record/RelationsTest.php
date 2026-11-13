<?php

declare(strict_types=1);

use Denosys\Database\Connection\Connection;
use Denosys\Database\Connection\ConnectionManager;
use Denosys\Database\Exceptions\RelationNotLoaded;
use Denosys\Database\Record\RecordQuery;
use Denosys\Database\Record\Records;
use Denosys\Database\Record\RecordStore;
use PHPUnit\Framework\TestCase;
use RecordFixtures\Author;
use RecordFixtures\Comment;
use RecordFixtures\Post;
use RecordFixtures\Tag;

require_once __DIR__ . '/fixtures.php';

final class RelationsTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $manager = new ConnectionManager();
        $manager->addConnection('default', ['driver' => 'sqlite', 'database' => ':memory:']);
        $manager->setDefaultConnection('default');
        $this->db = $manager->connection();
        foreach ([
            'CREATE TABLE authors (id INTEGER PRIMARY KEY, name TEXT NOT NULL)',
            'CREATE TABLE posts (id INTEGER PRIMARY KEY, author_id INTEGER NOT NULL, title TEXT NOT NULL)',
            'CREATE TABLE comments (id INTEGER PRIMARY KEY, post_id INTEGER NOT NULL, body TEXT NOT NULL)',
            'CREATE TABLE profiles (id INTEGER PRIMARY KEY, author_id INTEGER NOT NULL, bio TEXT NOT NULL)',
            'CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT NOT NULL)',
            'CREATE TABLE post_tag (post_id INTEGER NOT NULL, tag_id INTEGER NOT NULL, position INTEGER NOT NULL)',
            "INSERT INTO authors (id, name) VALUES (1, 'ann'), (2, 'bob'), (3, 'cy'), (4, 'di')",
            "INSERT INTO posts (id, author_id, title) VALUES (1, 1, 'p1'), (2, 1, 'p2')",
            "INSERT INTO comments (id, post_id, body) VALUES (1, 1, 'c1'), (2, 1, 'c2'), (3, 2, 'c3')",
            "INSERT INTO profiles (id, author_id, bio) VALUES (1, 2, 'hi')",
            "INSERT INTO tags (id, name) VALUES (1, 'php'), (2, 'sql')",
            'INSERT INTO post_tag (post_id, tag_id, position) VALUES (1, 2, 1), (1, 1, 2)',
        ] as $sql) {
            $this->db->statement($sql);
        }
        Records::use(new RecordStore($manager));
    }

    protected function tearDown(): void
    {
        Records::reset();
    }

    private function queryCount(callable $run): int
    {
        $this->db->flushQueryLog();
        $this->db->enableQueryLog();
        $run();
        $this->db->disableQueryLog();

        return count($this->db->getQueryLog());
    }

    public function testEagerLoadingInitialisesEmptyParents(): void
    {
        $authors = [];
        $loading = $this->queryCount(function () use (&$authors): void {
            $authors = Author::query()->orderBy('id')->with(static fn (Author $a) => $a->posts())->get();
        });
        self::assertSame(2, $loading);

        $counts = [];
        $reading = $this->queryCount(function () use ($authors, &$counts): void {
            foreach ($authors as $author) {
                $counts[] = count($author->posts()->get());
            }
        });

        self::assertSame([2, 0, 0, 0], $counts);
        self::assertSame(0, $reading);
    }

    public function testReadingAnUnloadedRelationThrows(): void
    {
        $this->expectException(RelationNotLoaded::class);
        $this->expectExceptionMessageMatches('/Author/');

        Author::findOrFail(1)->posts()->get();
    }

    public function testConstrainedAndNestedEagerLoading(): void
    {
        $author = Author::query()->with(
            static fn (Author $a) => $a->posts()
                ->constrain(static fn (RecordQuery $q) => $q->orderByDesc('id'))
                ->with(static fn (Post $p) => $p->comments()),
        )->findOrFail(1);

        $posts = $author->posts()->get();
        self::assertSame(['p2', 'p1'], array_map(static fn (Post $p): string => $p->title, $posts));
        self::assertSame(['c3'], array_map(static fn (Comment $c): string => $c->body, $posts[0]->comments()->get()));
        self::assertCount(2, $posts[1]->comments()->get());
    }

    public function testBelongsToAndHasOne(): void
    {
        $posts = Post::query()->with(static fn (Post $p) => $p->author())->get();
        self::assertSame('ann', $posts[0]->author()->get()?->name);

        $authors = Author::query()->orderBy('id')->with(static fn (Author $a) => $a->profile())->get();
        self::assertNull($authors[0]->profile()->get());
        self::assertSame('hi', $authors[1]->profile()->get()?->bio);
    }

    public function testBelongsToManyKeepsPivotSeparate(): void
    {
        $post = Post::query()->with(
            static fn (Post $p) => $p->tags()->constrain(static fn (RecordQuery $q) => $q->orderBy('post_tag.position')),
        )->findOrFail(1);

        $tags = $post->tags()->get();
        self::assertSame(['sql', 'php'], array_map(static fn (Tag $t): string => $t->name, $tags));
        self::assertSame(['position' => 1], $post->tags()->pivot($tags[0]));
        self::assertSame([], Post::findOrFail(2)->tags()->query()->get());
        self::assertSame(2, $post->tags()->query()->count());
    }

    public function testRelationQueryAlwaysQueries(): void
    {
        $author = Author::query()->with(static fn (Author $a) => $a->posts())->findOrFail(1);

        self::assertSame(1, $this->queryCount(static fn () => $author->posts()->query()->get()));
        self::assertTrue($author->posts()->isLoaded());
        self::assertFalse(Author::findOrFail(1)->posts()->isLoaded());
    }

    public function testRefreshForgetsLoadedRelations(): void
    {
        $author = Author::query()->with(static fn (Author $a) => $a->posts())->findOrFail(1);

        $author->refresh();

        self::assertFalse($author->posts()->isLoaded());
    }

    public function testALimitInAnEagerLoadIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/limit/');

        Author::query()->with(static fn (Author $a) => $a->posts()->constrain(static fn (RecordQuery $q) => $q->limit(1)))->get();
    }

    public function testAPivotColumnNamedParentKeepsItsValue(): void
    {
        $this->db->statement('CREATE TABLE post_label (post_id INTEGER NOT NULL, tag_id INTEGER NOT NULL, parent INTEGER NOT NULL)');
        $this->db->statement('INSERT INTO post_label (post_id, tag_id, parent) VALUES (1, 1, 77)');
        $post = Post::query()->with(static fn (Post $p) => \Denosys\Database\Record\Relations\Relations::belongsToMany($p, Tag::class, 'post_label', 'post_id', 'tag_id', ['parent']))->findOrFail(1);

        $tags = \Denosys\Database\Record\Relations\Relations::belongsToMany($post, Tag::class, 'post_label', 'post_id', 'tag_id', ['parent'])->get();
        self::assertCount(1, $tags);
        self::assertSame(['parent' => 77], \Denosys\Database\Record\Relations\Relations::belongsToMany($post, Tag::class, 'post_label', 'post_id', 'tag_id', ['parent'])->pivot($tags[0]));
    }

    public function testNullAndEmptyKeysDoNotMatch(): void
    {
        self::assertSame(['x'], \Denosys\Database\Record\Relations\Matching::lookup(['' => ['x']], '', []));
        self::assertSame([], \Denosys\Database\Record\Relations\Matching::lookup(['' => ['x']], null, []));
    }
}
