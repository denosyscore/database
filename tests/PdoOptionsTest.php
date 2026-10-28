<?php

declare(strict_types=1);

use Denosys\Database\Connection\ConnectionFactory;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class PdoOptionsTest extends TestCase
{
    public function testDefaultOptionsPreserveNumericKeysAndCallerOverrides(): void
    {
        $options = $this->factory()->defaultOptions([
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
                PDO::ATTR_TIMEOUT => 5,
            ],
        ]);

        self::assertSame(PDO::ERRMODE_SILENT, $options[PDO::ATTR_ERRMODE] ?? null);
        self::assertSame(PDO::FETCH_OBJ, $options[PDO::ATTR_DEFAULT_FETCH_MODE] ?? null);
        self::assertSame(false, $options[PDO::ATTR_EMULATE_PREPARES] ?? null);
        self::assertSame(5, $options[PDO::ATTR_TIMEOUT] ?? null);
    }

    #[RequiresPhpExtension('pdo_mysql')]
    public function testMysqlOptionsPreserveInitCommandAndSslKeys(): void
    {
        $initCommand = $this->mysqlAttribute('INIT_COMMAND');
        $sslCa = $this->mysqlAttribute('SSL_CA');
        $options = $this->factory()->mysqlOptions([
            'options' => [
                PDO::ATTR_TIMEOUT => 5,
                $initCommand => 'custom command',
                $sslCa => '/tmp/custom-ca.pem',
            ],
            'ssl' => ['ca' => '/tmp/ca.pem', 'verify' => false],
        ], 'utf8mb4', 'utf8mb4_unicode_ci');

        self::assertSame(5, $options[PDO::ATTR_TIMEOUT] ?? null);
        self::assertSame(
            "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'",
            $options[$initCommand] ?? null,
        );
        self::assertSame('/tmp/ca.pem', $options[$sslCa] ?? null);
        self::assertSame(false, $options[$this->mysqlAttribute('SSL_VERIFY_SERVER_CERT')] ?? null);
    }

    private function factory(): PdoOptionsTestFactory
    {
        return new PdoOptionsTestFactory();
    }

    private function mysqlAttribute(string $name): int
    {
        if (class_exists(\Pdo\Mysql::class)) {
            return constant(\Pdo\Mysql::class . '::ATTR_' . $name);
        }

        return constant(PDO::class . '::MYSQL_ATTR_' . $name);
    }
}

final class PdoOptionsTestFactory extends ConnectionFactory
{
    /** @param array<string, mixed> $config
     *  @return array<int, mixed>
     */
    public function defaultOptions(array $config): array
    {
        return $this->getDefaultOptions($config);
    }

    /** @param array<string, mixed> $config
     *  @return array<int, mixed>
     */
    public function mysqlOptions(array $config, string $charset, string $collation): array
    {
        return $this->getMySqlOptions($config, $charset, $collation);
    }
}
