<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Test;

use PHPUnit\Framework\TestCase;
use TxAdmin\SchemaCompare\Generator\Mysql\MysqlSqlGenerator;
use TxAdmin\SchemaCompare\Generator\SqlGenerator;

class SqlGeneratorTest extends TestCase
{
    public function testGenerateTableSqlFromFlatChanged(): void
    {
        $generator = new MysqlSqlGenerator();
        $sqls = $generator->generateTableSql([
            'has_diff' => true,
            'summary' => ['only_in_baseline' => 0, 'only_in_live' => 0, 'field_changed' => 1],
            'changed' => [
                'users' => [
                    'engine' => ['baseline' => 'MyISAM', 'live' => 'InnoDB'],
                    'table_comment' => ['baseline' => 'old', 'live' => 'new'],
                ],
            ],
        ]);

        $this->assertCount(2, $sqls['alter']);
        $this->assertStringContainsString('ENGINE=InnoDB', $sqls['alter'][0]);
        $this->assertStringContainsString("COMMENT='new'", $sqls['alter'][1]);
    }

    public function testGenerateIndexSqlUsesDiffsByIndex(): void
    {
        $generator = new MysqlSqlGenerator();
        $sqls = $generator->generateIndexSql([
            'has_diff' => true,
            'summary' => ['only_in_baseline' => 1, 'only_in_live' => 0, 'field_changed' => 0],
            'diffs_by_index' => [
                'users.idx_email' => [
                    'only_in_baseline' => ['users.idx_email.1'],
                ],
            ],
        ]);

        $this->assertCount(1, $sqls['drop']);
        $this->assertStringContainsString('DROP INDEX `idx_email`', $sqls['drop'][0]);
    }

    public function testBuildCreateCompositeIndexUnique(): void
    {
        $generator = new MysqlSqlGenerator();
        $sql = $generator->buildCreateCompositeIndex('users', 'uk_email', [
            ['column_name' => 'email', 'seq_in_index' => 1, 'non_unique' => 0],
        ]);

        $this->assertStringStartsWith('CREATE UNIQUE INDEX', $sql);
    }

    public function testSqlGeneratorFacadeDelegatesToMysql(): void
    {
        $facade = new SqlGenerator('mysql');
        $delegate = $facade->getDelegate();

        $this->assertInstanceOf(MysqlSqlGenerator::class, $delegate);
    }
}
