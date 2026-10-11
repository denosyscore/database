<?php

declare(strict_types=1);

namespace Denosys\Database\Tests\Fixtures;

use Denosys\Database\Model;
use Denosys\Database\ModelBuilder;

final class ModelQueryTypesFixture extends Model
{
    protected string $table = 'records';
}

/** @return ModelBuilder */
function modelQuery(ModelQueryTypesFixture $model): ModelBuilder
{
    return $model->newQuery();
}

/** @return ModelBuilder */
function staticQuery(): ModelBuilder
{
    return ModelQueryTypesFixture::query();
}

/** @return ModelBuilder */
function staticModelQuery(): ModelBuilder
{
    return ModelQueryTypesFixture::whereRaw('status = ?', ['pending']);
}
