<?php

declare(strict_types=1);

use Denosys\Database\Migration\Migration;
use Denosys\Database\Schema\Blueprint;
use Denosys\Database\Schema\SchemaBuilder;

return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $schema->create('widgets', static function (Blueprint $table): void {
            $table->id();
        });
    }

    public function down(SchemaBuilder $schema): void
    {
        $schema->dropIfExists('widgets');
    }
};
