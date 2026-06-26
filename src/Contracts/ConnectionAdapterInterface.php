<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Contracts;

/**
 * DB 连接适配器接口
 *
 * 包只依赖此接口，不依赖任何框架
 */
interface ConnectionAdapterInterface
{
    /**
     * 执行 SQL 并返回行数组
     *
     * @param  string $sql
     * @return array<int, array<string, mixed>>
     */
    public function query(string $sql): array;
}
