<?php

declare(strict_types=1);

namespace RecordFixtures;

use DateTimeImmutable;
use Denosys\Database\Record\Attributes\CastWith;
use Denosys\Database\Record\Attributes\Column;
use Denosys\Database\Record\Attributes\Id;
use Denosys\Database\Record\Attributes\Table;
use Denosys\Database\Record\Cast;

final class BlogPost
{
    public ?int $id = null;
    public string $title;
    public string $firstName;
    #[Column('fname')]
    public string $nickName;
    public static int $count = 0;
    protected string $hiddenThing = '';

    public function __construct(private readonly string $secret = '')
    {
    }

    public function secret(): string
    {
        return $this->secret;
    }
}

final class Category
{
    public ?int $id = null;
}

final class Box
{
    public ?int $id = null;
}

#[Table('people', connection: 'other')]
final class Person
{
    #[Id]
    public ?string $code = null;
    public string $name;
}

final class UnionTyped
{
    public ?int $id = null;
    public int|string $value;
}

final class Untyped
{
    public ?int $id = null;
    /** @phpstan-ignore missingType.property */
    public $value;
}

final class NoId
{
    public string $name;
}

enum Status: string
{
    case Active = 'active';
    case Closed = 'closed';
}

enum Priority: int
{
    case Low = 1;
    case High = 2;
}

final class Money
{
    public function __construct(public readonly int $cents)
    {
    }
}

final class MoneyCast implements Cast
{
    public function fromDatabase(mixed $value): mixed
    {
        return new Money(is_numeric($value) ? (int) round(((float) $value) * 100) : 0);
    }

    public function toDatabase(mixed $value): mixed
    {
        return $value instanceof Money ? number_format($value->cents / 100, 2, '.', '') : $value;
    }
}

final class Account
{
    public ?int $id = null;
    public int $count;
    public float $rate;
    public bool $active;
    public string $amount;
    public ?DateTimeImmutable $openedAt = null;
    public Status $status;
    public Priority $priority;
    #[CastWith(MoneyCast::class)]
    public Money $balance;
}

final class Widget
{
    public ?int $id = null;
    public string $name;
    public ?string $notes = null;
    public string $status;
    public ?DateTimeImmutable $createdAt = null;
    public ?DateTimeImmutable $updatedAt = null;
}

final class Gadget
{
    public ?int $id = null;
    public string $name;
}

final class Part implements \Denosys\Database\Record\Record
{
    use \Denosys\Database\Record\Persists;

    public ?int $id = null;
    public string $name;
}

final class Author implements \Denosys\Database\Record\Record
{
    use \Denosys\Database\Record\Persists;

    public ?int $id = null;
    public string $name;

    /** @return \Denosys\Database\Record\Relations\HasMany<self, Post> */
    public function posts(): \Denosys\Database\Record\Relations\HasMany
    {
        return \Denosys\Database\Record\Relations\Relations::hasMany($this, Post::class);
    }

    /** @return \Denosys\Database\Record\Relations\HasOne<self, Profile> */
    public function profile(): \Denosys\Database\Record\Relations\HasOne
    {
        return \Denosys\Database\Record\Relations\Relations::hasOne($this, Profile::class);
    }
}

final class Post implements \Denosys\Database\Record\Record
{
    use \Denosys\Database\Record\Persists;

    public ?int $id = null;
    public int $authorId;
    public string $title;

    /** @return \Denosys\Database\Record\Relations\BelongsTo<self, Author> */
    public function author(): \Denosys\Database\Record\Relations\BelongsTo
    {
        return \Denosys\Database\Record\Relations\Relations::belongsTo($this, Author::class);
    }

    /** @return \Denosys\Database\Record\Relations\HasMany<self, Comment> */
    public function comments(): \Denosys\Database\Record\Relations\HasMany
    {
        return \Denosys\Database\Record\Relations\Relations::hasMany($this, Comment::class);
    }

    /** @return \Denosys\Database\Record\Relations\BelongsToMany<self, Tag> */
    public function tags(): \Denosys\Database\Record\Relations\BelongsToMany
    {
        return \Denosys\Database\Record\Relations\Relations::belongsToMany($this, Tag::class, 'post_tag', 'post_id', 'tag_id', ['position']);
    }
}

final class Comment implements \Denosys\Database\Record\Record
{
    use \Denosys\Database\Record\Persists;

    public ?int $id = null;
    public int $postId;
    public string $body;
}

final class Profile implements \Denosys\Database\Record\Record
{
    use \Denosys\Database\Record\Persists;

    public ?int $id = null;
    public int $authorId;
    public string $bio;
}

final class Tag implements \Denosys\Database\Record\Record
{
    use \Denosys\Database\Record\Persists;

    public ?int $id = null;
    public string $name;
}

final class Note
{
    public ?int $id = null;
    public string $body;
    public bool $active = true;
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;
}
