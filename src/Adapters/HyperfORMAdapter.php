<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Adapters;

use Hyperf\DbConnection\Db;
use TxAdmin\SchemaCompare\Contracts\ConnectionAdapterInterface;
use TxAdmin\SchemaCompare\Exceptions\SchemaCompareException;

/**
 * Hyperf MySQL 适配器
 *
 * 使用 Hyperf\DbConnection\Db 组件（hyperf/db-connection）
 *
 * 用法：
 *   $adapter = new HyperfMysqlAdapter('default');
 */
class HyperfORMAdapter implements ConnectionAdapterInterface
{
    private string $poolName;

    public function __construct(string $poolName = 'default')
    {
        $this->poolName = $poolName;
    }

    public function query(string $sql): array
    {
        $result = Db::connection($this->poolName)->select($sql);

        // 防御：查询失败时框架可能返回 false 或非数组
        if (!is_array($result)) {
            throw new SchemaCompareException(
                "SQL 查询返回非数组结果 (pool: {$this->poolName})，请检查连接或语法: " . substr($sql, 0, 100)
            );
        }

        // 将 stdClass 对象数组转换为关联数组
        return json_decode(json_encode($result), true);
    }
}
