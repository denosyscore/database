<?php

declare(strict_types=1);

namespace Denosys\Database\Tests\Fixtures;

use Denosys\Database\Model;

final class ModelMetadataFixture extends Model
{
    /** @var list<string> */
    protected array $fillable = ['name', 'email'];

    /** @var list<string> */
    protected array $hidden = ['password'];
}
