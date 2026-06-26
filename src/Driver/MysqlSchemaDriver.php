<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Driver;

use TxAdmin\SchemaCompare\AbstractSchemaDriver;
use TxAdmin\SchemaCompare\Contracts\ConnectionAdapterInterface;
use TxAdmin\SchemaCompare\Contracts\SchemaStrategyInterface;

/**
 * MySQL 结构对比驱动（骨架）
 *
 * 待实现 MysqlColumnsStrategy / MysqlIndexesStrategy 后注入
 */
class MysqlSchemaDriver extends AbstractSchemaDriver
{
    /**
     * @param ConnectionAdapterInterface $adapter
     * @param string                     $type       默认 'mysql'
     * @param SchemaStrategyInterface[]  $strategies 为空时使用 defaultStrategies()
     */
    public function __construct(
        ConnectionAdapterInterface $adapter,
        string $type = 'mysql',
        array $strategies = []
    ) {
        parent::__construct($adapter, $type, $strategies);
    }

    protected function defaultStrategies(): array
    {
        // TODO: return [new MysqlColumnsStrategy(), new MysqlIndexesStrategy()];
        return [];
    }
}
