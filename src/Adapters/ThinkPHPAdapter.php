<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Adapters;

use think\facade\Db;
use TxAdmin\SchemaCompare\Contracts\ConnectionAdapterInterface;
use TxAdmin\SchemaCompare\Exceptions\SchemaCompareException;

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
        $result = Db::connect($this->poolName)->query($sql);

        // 防御：查询失败时框架可能返回 false 或非数组
        if (!is_array($result)) {
            throw new SchemaCompareException(
                "SQL 查询返回非数组结果 (pool: {$this->poolName})，请检查连接或语法: " . substr($sql, 0, 100)
            );
        }

        return $result;
    }
}
