<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Adapters;

use think\facade\Db;
use TxAdmin\SchemaCompare\Contracts\ConnectionAdapterInterface;

/**
 * ThinkPHP 6 适配器
 *
 * 用法：
 *   $adapter = new ThinkPHPAdapter('clickhouse');        // CK 连接
 *   $adapter = new ThinkPHPAdapter('mysql');             // MySQL 连接
 *   $adapter = new ThinkPHPAdapter();                    // 使用 'default' 连接（默认值）
 */
class ThinkPHPAdapter implements ConnectionAdapterInterface
{
    private string $poolName;

    public function __construct(string $poolName = 'default')
    {
        $this->poolName = $poolName;
    }

    public function query(string $sql): array
    {
        return Db::connect($this->poolName)->query($sql);
    }
}
