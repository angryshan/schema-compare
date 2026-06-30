<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Test;

use PHPUnit\Framework\TestCase;
use TxAdmin\SchemaCompare\Driver\CkSchemaDriver;
use TxAdmin\SchemaCompare\Exceptions\DriverNotFoundException;
use TxAdmin\SchemaCompare\SchemaCompareManager;
use TxAdmin\SchemaCompare\Strategy\Ck\CkColumnsStrategy;

class SchemaCompareManagerTest extends TestCase
{
    public function testRegisterAndGetDriver(): void
    {
        $adapter = new MockConnectionAdapter();
        $driver = new CkSchemaDriver($adapter, 'clickhouse', [new CkColumnsStrategy()]);

        $manager = new SchemaCompareManager();
        $manager->register($driver);

        $this->assertTrue($manager->hasDriver('clickhouse'));
        $this->assertSame(['clickhouse'], $manager->types());
        $this->assertSame($driver, $manager->driver('clickhouse'));
    }

    public function testDriverNotFound(): void
    {
        $this->expectException(DriverNotFoundException::class);

        (new SchemaCompareManager())->driver('missing');
    }

    public function testCompareFromArray(): void
    {
        $adapter = new MockConnectionAdapter();
        $adapter->setResponse(
            "SELECT `database`, `table`, `name`, `type`, `default_kind`, `default_expression`, `comment`, `compression_codec`, `is_in_partition_key`, `is_in_sorting_key`, `is_in_primary_key`, `is_in_sampling_key` FROM system.columns WHERE database = 'demo_db' ORDER BY `database`, `table`, `position`",
            [
                [
                    'database' => 'demo_db',
                    'table' => 'users',
                    'name' => 'id',
                    'type' => 'UInt64',
                    'default_kind' => '',
                    'default_expression' => '',
                    'comment' => '',
                    'compression_codec' => '',
                    'is_in_partition_key' => 0,
                    'is_in_sorting_key' => 1,
                    'is_in_primary_key' => 1,
                    'is_in_sampling_key' => 0,
                ],
            ]
        );

        $driver = new CkSchemaDriver($adapter, 'clickhouse', [new CkColumnsStrategy()]);
        $manager = new SchemaCompareManager();
        $manager->register($driver);

        $baseline = [
            'columns' => [
                [
                    'database' => 'demo_db',
                    'table' => 'users',
                    'name' => 'id',
                    'type' => 'UInt32',
                    'default_kind' => '',
                    'default_expression' => '',
                    'comment' => '',
                    'compression_codec' => '',
                    'is_in_partition_key' => 0,
                    'is_in_sorting_key' => 1,
                    'is_in_primary_key' => 1,
                    'is_in_sampling_key' => 0,
                ],
            ],
        ];

        $result = $manager->compareFromArray('clickhouse', $baseline, 'demo_db');

        $this->assertTrue($result['has_diff']);
        $this->assertTrue($result['details']['columns']['has_diff']);
    }
}
