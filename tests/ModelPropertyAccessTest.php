<?php

declare(strict_types=1);

use Denosys\Database\Connection\ConnectionManager;
use Denosys\Database\Model;
use Denosys\Database\Relations\HasMany;
use PHPUnit\Framework\TestCase;

final class ModelPropertyAccessTest extends TestCase
{
    private \Denosys\Database\Connection\Connection $connection;

    protected function setUp(): void
    {
        $manager = new ConnectionManager();
        $manager->addConnection('default', ['driver' => 'sqlite', 'database' => ':memory:']);
        Model::setConnectionManager($manager);
        $this->connection = $manager->connection();
        $this->connection->statement('CREATE TABLE property_widgets (id INTEGER PRIMARY KEY, name TEXT)');
        $this->connection->statement('CREATE TABLE property_parts (id INTEGER PRIMARY KEY, property_widget_id INTEGER)');
        $this->connection->insert('INSERT INTO property_widgets (id, name) VALUES (1, ?)', ['widget']);
        $this->connection->insert('INSERT INTO property_parts (id, property_widget_id) VALUES (1, 1)');
    }

    public function testReadingAPropertyNamedAfterAMethodDoesNotCallTheMethod(): void
    {
        $widget = PropertyWidget::find(1);
        self::assertNotNull($widget);

        self::assertNull($widget->delete);
        self::assertNull($widget->save);

        self::assertCount(1, $this->connection->select('SELECT * FROM property_widgets'));
    }

    public function testReadingAPropertyDoesNotCallUntypedMethods(): void
    {
        $widget = PropertyWidget::find(1);
        self::assertNotNull($widget);

        self::assertNull($widget->markTouched);
        self::assertFalse($widget->touched);
    }

    public function testTypedRelationMethodsStillLoadAsProperties(): void
    {
        $widget = PropertyWidget::find(1);
        self::assertNotNull($widget);

        self::assertCount(1, $widget->parts);
    }
}

final class PropertyWidget extends Model
{
    protected string $table = 'property_widgets';
    protected bool $timestamps = false;
    public bool $touched = false;

    /** @return HasMany */
    public function parts(): HasMany
    {
        return $this->hasMany(PropertyPart::class, 'property_widget_id');
    }

    public function markTouched()
    {
        $this->touched = true;

        return $this->hasMany(PropertyPart::class, 'property_widget_id');
    }
}

final class PropertyPart extends Model
{
    protected string $table = 'property_parts';
    protected bool $timestamps = false;
}
