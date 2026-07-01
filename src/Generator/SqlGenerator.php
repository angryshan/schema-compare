<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Generator;

use TxAdmin\SchemaCompare\Contracts\SqlGeneratorInterface;
use TxAdmin\SchemaCompare\Exceptions\SchemaCompareException;
use TxAdmin\SchemaCompare\Generator\Ck\CkSqlGenerator;
use TxAdmin\SchemaCompare\Generator\Mysql\MysqlSqlGenerator;

/**
 * SQL 生成器门面
 *
 * 根据数据库类型委托给对应的生成策略
 */
class SqlGenerator implements SqlGeneratorInterface
{
    private SqlGeneratorInterface $delegate;

    public function __construct(string $dbType = 'mysql')
    {
        $this->delegate = self::create($dbType);
    }

    public static function create(string $dbType): SqlGeneratorInterface
    {
        switch ($dbType) {
            case 'mysql':
                return new MysqlSqlGenerator();
            case 'clickhouse':
                return new CkSqlGenerator();
            default:
                throw new SchemaCompareException("不支持的 SQL 生成器类型：{$dbType}");
        }
    }

    public function generateAll(array $diffResult): array
    {
        return $this->delegate->generateAll($diffResult);
    }

    public function generateColumnSql(array $columnDiff): array
    {
        return $this->delegate->generateColumnSql($columnDiff);
    }

    public function generateIndexSql(array $indexDiff): array
    {
        return $this->delegate->generateIndexSql($indexDiff);
    }

    public function generateTableSql(array $tableDiff): array
    {
        return $this->delegate->generateTableSql($tableDiff);
    }

    public function generatePreciseSql(array $diffResult, array $liveData): array
    {
        return $this->delegate->generatePreciseSql($diffResult, $liveData);
    }

    public function combineSql(array $sqlGroups, string $separator = "\n\n"): string
    {
        return $this->delegate->combineSql($sqlGroups, $separator);
    }

    public function getDelegate(): SqlGeneratorInterface
    {
        return $this->delegate;
    }
}
