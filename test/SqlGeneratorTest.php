<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Test;

use PHPUnit\Framework\TestCase;
use TxAdmin\SchemaCompare\Generator\Mysql\MysqlSqlGenerator;
use TxAdmin\SchemaCompare\Generator\SqlGenerator;

/**
 * SQL 生成器测试
 *
 * 方向约定：导出的 SQL 在线上库执行，让线上对齐基准。
 *   - only_in_baseline（基准有、线上无）-> ADD / CREATE
 *   - only_in_live    （线上有、基准无）-> DROP
 *   - field_changed                     -> MODIFY 到基准值
 */
class SqlGeneratorTest extends TestCase
{
    // ----------------------------------------------------------------
    // 非精确路径 generateAll / generateColumnSql / generateTableSql
    // ----------------------------------------------------------------

    /**
     * 表属性变更：ALTER 到基准值（非线上值）
     */
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
        // 目标 = 基准值
        $this->assertStringContainsString('ENGINE=MyISAM', $sqls['alter'][0]);
        $this->assertStringContainsString('原: InnoDB', $sqls['alter'][0]);
        $this->assertStringContainsString("COMMENT='old'", $sqls['alter'][1]);
        $this->assertStringContainsString('原: new', $sqls['alter'][1]);
    }

    /**
     * 表 only_in_baseline -> CREATE（占位）；only_in_live -> DROP
     */
    public function testGenerateTableSqlFlatOnlyInDirection(): void
    {
        $generator = new MysqlSqlGenerator();
        $sqls = $generator->generateTableSql([
            'has_diff' => true,
            'summary' => ['only_in_baseline' => 1, 'only_in_live' => 1, 'field_changed' => 0],
            'only_in_baseline' => ['extra_table'],
            'only_in_live' => ['orphan_table'],
        ]);

        // 基准有、线上无 -> CREATE
        $this->assertCount(1, $sqls['create']);
        $this->assertStringContainsString('CREATE TABLE', $sqls['create'][0]);
        $this->assertStringContainsString('extra_table', $sqls['create'][0]);

        // 线上有、基准无 -> DROP
        $this->assertCount(1, $sqls['drop']);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `orphan_table`', $sqls['drop'][0]);
    }

    /**
     * 字段方向：only_in_baseline -> ADD；only_in_live -> DROP
     */
    public function testGenerateColumnSqlDirection(): void
    {
        $generator = new MysqlSqlGenerator();
        $sqls = $generator->generateColumnSql([
            'has_diff' => true,
            'summary' => ['only_in_baseline' => 1, 'only_in_live' => 1, 'field_changed' => 0],
            'diffs_by_table' => [
                'users' => [
                    'only_in_baseline' => ['users.new_col'],
                    'only_in_live' => ['users.legacy_col'],
                    'field_changed' => [],
                ],
            ],
        ]);

        // 基准有、线上无 -> ADD（占位）
        $this->assertCount(1, $sqls['add']);
        $this->assertStringContainsString('ADD COLUMN `new_col`', $sqls['add'][0]);

        // 线上有、基准无 -> DROP
        $this->assertCount(1, $sqls['drop']);
        $this->assertStringContainsString('DROP COLUMN `legacy_col`', $sqls['drop'][0]);
    }

    /**
     * 整表缺失 - missing_side=live（线上缺表，基准有）-> CREATE TABLE
     */
    public function testGenerateColumnSqlTableMissingLive(): void
    {
        $generator = new MysqlSqlGenerator();
        $sqls = $generator->generateColumnSql([
            'has_diff' => true,
            'summary' => ['only_in_baseline' => 2, 'only_in_live' => 0, 'field_changed' => 0],
            'diffs_by_table' => [
                'users' => [
                    'table_missing' => true,
                    'missing_side' => 'live',
                    'only_in_baseline' => [],
                    'only_in_live' => [],
                    'field_changed' => [],
                ],
            ],
        ]);

        $this->assertCount(1, $sqls['add']);
        $this->assertStringContainsString('CREATE TABLE', $sqls['add'][0]);
        $this->assertStringContainsString('users', $sqls['add'][0]);
        $this->assertEmpty($sqls['drop']);
    }

    /**
     * 整表缺失 - missing_side=baseline（基准缺表，线上有）-> DROP TABLE
     */
    public function testGenerateColumnSqlTableMissingBaseline(): void
    {
        $generator = new MysqlSqlGenerator();
        $sqls = $generator->generateColumnSql([
            'has_diff' => true,
            'summary' => ['only_in_baseline' => 0, 'only_in_live' => 2, 'field_changed' => 0],
            'diffs_by_table' => [
                'orphan' => [
                    'table_missing' => true,
                    'missing_side' => 'baseline',
                    'only_in_baseline' => [],
                    'only_in_live' => [],
                    'field_changed' => [],
                ],
            ],
        ]);

        $this->assertCount(1, $sqls['drop']);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `orphan`', $sqls['drop'][0]);
        $this->assertEmpty($sqls['add']);
    }

    /**
     * 字段变更 MODIFY 到基准值（comment 例子：基准 123，线上 321 -> 改为 123）
     */
    public function testGenerateColumnSqlModifyToBaseline(): void
    {
        $generator = new MysqlSqlGenerator();
        $sqls = $generator->generateColumnSql([
            'has_diff' => true,
            'summary' => ['only_in_baseline' => 0, 'only_in_live' => 0, 'field_changed' => 1],
            'diffs_by_table' => [
                'users' => [
                    'only_in_baseline' => [],
                    'only_in_live' => [],
                    'field_changed' => [
                        'users.name' => [
                            'comment' => ['baseline' => '123', 'live' => '321'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $sqls['modify']);
        $this->assertStringContainsString("COMMENT '123'", $sqls['modify'][0]);
        $this->assertStringContainsString('原: 321', $sqls['modify'][0]);
    }

    // ----------------------------------------------------------------
    // 索引非精确路径
    // ----------------------------------------------------------------

    /**
     * only_in_baseline -> CREATE INDEX（占位）
     */
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

        // 基准有、线上无 -> CREATE
        $this->assertCount(1, $sqls['create']);
        $this->assertStringContainsString('CREATE INDEX `idx_email`', $sqls['create'][0]);
    }

    /**
     * only_in_live -> DROP INDEX
     */
    public function testGenerateIndexSqlOnlyInLiveDrops(): void
    {
        $generator = new MysqlSqlGenerator();
        $sqls = $generator->generateIndexSql([
            'has_diff' => true,
            'summary' => ['only_in_baseline' => 0, 'only_in_live' => 1, 'field_changed' => 0],
            'diffs_by_index' => [
                'users.idx_orphan' => [
                    'only_in_live' => ['users.idx_orphan.1'],
                ],
            ],
        ]);

        $this->assertCount(1, $sqls['drop']);
        $this->assertStringContainsString('DROP INDEX `idx_orphan`', $sqls['drop'][0]);
    }

    public function testBuildCreateCompositeIndexUnique(): void
    {
        $generator = new MysqlSqlGenerator();
        $sql = $generator->buildCreateCompositeIndex('users', 'uk_email', [
            ['column_name' => 'email', 'seq_in_index' => 1, 'non_unique' => 0],
        ]);

        $this->assertStringStartsWith('CREATE UNIQUE INDEX', $sql);
        // 回归断言：列名必须用反引号包裹（防 `"` 误写为 `"` 的 bug 复发）
        $this->assertStringContainsString('(`email`)', $sql);
    }

    // ----------------------------------------------------------------
    // 精确路径 generatePreciseSql
    // ----------------------------------------------------------------

    /**
     * 精确路径：only_in_baseline -> ADD COLUMN 用基准行定义
     */
    public function testPreciseColumnSqlAddFromBaseline(): void
    {
        $generator = new MysqlSqlGenerator();

        $diffResult = [
            'has_diff' => true,
            'details' => [
                'columns' => [
                    'has_diff' => true,
                    'summary' => ['only_in_baseline' => 1, 'only_in_live' => 0, 'field_changed' => 0],
                    'diffs_by_table' => [
                        'users' => [
                            'only_in_baseline' => ['users.status'],
                            'only_in_live' => [],
                            'field_changed' => [],
                        ],
                    ],
                ],
            ],
        ];

        $baselineData = [
            'columns' => [
                ['table' => 'users', 'name' => 'status', 'type' => 'tinyint(1)', 'is_nullable' => 'NO', 'column_default' => '0', 'comment' => '状态'],
            ],
        ];

        $sqls = $generator->generatePreciseSql($diffResult, [], $baselineData);

        $this->assertCount(1, $sqls['columns']['add']);
        $sql = $sqls['columns']['add'][0];
        $this->assertStringContainsString('ADD COLUMN `status`', $sql);
        $this->assertStringContainsString('tinyint(1)', $sql);
        $this->assertStringContainsString('NOT NULL', $sql);
        $this->assertStringContainsString("COMMENT '状态'", $sql);
    }

    /**
     * 精确路径：only_in_live -> DROP COLUMN
     */
    public function testPreciseColumnSqlDropLive(): void
    {
        $generator = new MysqlSqlGenerator();

        $diffResult = [
            'has_diff' => true,
            'details' => [
                'columns' => [
                    'has_diff' => true,
                    'summary' => ['only_in_baseline' => 0, 'only_in_live' => 1, 'field_changed' => 0],
                    'diffs_by_table' => [
                        'users' => [
                            'only_in_baseline' => [],
                            'only_in_live' => ['users.legacy'],
                            'field_changed' => [],
                        ],
                    ],
                ],
            ],
        ];

        $sqls = $generator->generatePreciseSql($diffResult, [], []);

        $this->assertCount(1, $sqls['columns']['drop']);
        $this->assertStringContainsString('DROP COLUMN `legacy`', $sqls['columns']['drop'][0]);
    }

    /**
     * 精确路径：field_changed -> MODIFY 到基准定义
     */
    public function testPreciseColumnSqlModifyToBaseline(): void
    {
        $generator = new MysqlSqlGenerator();

        $diffResult = [
            'has_diff' => true,
            'details' => [
                'columns' => [
                    'has_diff' => true,
                    'summary' => ['only_in_baseline' => 0, 'only_in_live' => 0, 'field_changed' => 1],
                    'diffs_by_table' => [
                        'users' => [
                            'only_in_baseline' => [],
                            'only_in_live' => [],
                            'field_changed' => [
                                'users.name' => [
                                    'comment' => ['baseline' => '123', 'live' => '321'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $baselineData = [
            'columns' => [
                ['table' => 'users', 'name' => 'name', 'type' => 'varchar(50)', 'is_nullable' => 'YES', 'column_default' => null, 'comment' => '123'],
            ],
        ];

        $sqls = $generator->generatePreciseSql($diffResult, [], $baselineData);

        $this->assertCount(1, $sqls['columns']['modify']);
        $sql = $sqls['columns']['modify'][0];
        // 完整 MODIFY COLUMN 使用基准定义
        $this->assertStringContainsString('MODIFY COLUMN `name`', $sql);
        $this->assertStringContainsString('varchar(50)', $sql);
        $this->assertStringContainsString("COMMENT '123'", $sql);
    }

    /**
     * 精确路径：table_missing 处理（线上缺表 -> CREATE TABLE）
     */
    public function testPreciseColumnSqlTableMissingLive(): void
    {
        $generator = new MysqlSqlGenerator();

        $diffResult = [
            'has_diff' => true,
            'details' => [
                'columns' => [
                    'has_diff' => true,
                    'summary' => ['only_in_baseline' => 3, 'only_in_live' => 0, 'field_changed' => 0],
                    'diffs_by_table' => [
                        'new_table' => [
                            'table_missing' => true,
                            'missing_side' => 'live',
                            'only_in_baseline' => [],
                            'only_in_live' => [],
                            'field_changed' => [],
                        ],
                    ],
                ],
            ],
        ];

        $sqls = $generator->generatePreciseSql($diffResult, [], []);

        $this->assertCount(1, $sqls['columns']['add']);
        $this->assertStringContainsString('CREATE TABLE', $sqls['columns']['add'][0]);
        $this->assertStringContainsString('new_table', $sqls['columns']['add'][0]);
        // 不应生成逐字段语句
        $this->assertEmpty($sqls['columns']['drop']);
        $this->assertEmpty($sqls['columns']['modify']);
    }

    /**
     * 精确路径：table_missing 处理（基准缺表 -> DROP TABLE）
     */
    public function testPreciseColumnSqlTableMissingBaseline(): void
    {
        $generator = new MysqlSqlGenerator();

        $diffResult = [
            'has_diff' => true,
            'details' => [
                'columns' => [
                    'has_diff' => true,
                    'summary' => ['only_in_baseline' => 0, 'only_in_live' => 3, 'field_changed' => 0],
                    'diffs_by_table' => [
                        'orphan' => [
                            'table_missing' => true,
                            'missing_side' => 'baseline',
                            'only_in_baseline' => [],
                            'only_in_live' => [],
                            'field_changed' => [],
                        ],
                    ],
                ],
            ],
        ];

        $sqls = $generator->generatePreciseSql($diffResult, [], []);

        $this->assertCount(1, $sqls['columns']['drop']);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `orphan`', $sqls['columns']['drop'][0]);
        $this->assertEmpty($sqls['columns']['add']);
    }

    /**
     * 精确路径：tables 维度生成 ALTER（表属性变更）
     */
    public function testPreciseSqlTablesDimension(): void
    {
        $generator = new MysqlSqlGenerator();

        $diffResult = [
            'has_diff' => true,
            'details' => [
                'tables' => [
                    'has_diff' => true,
                    'summary' => ['only_in_baseline' => 0, 'only_in_live' => 0, 'field_changed' => 1],
                    'changed' => [
                        'users' => [
                            'engine' => ['baseline' => 'InnoDB', 'live' => 'MyISAM'],
                        ],
                    ],
                ],
            ],
        ];

        $sqls = $generator->generatePreciseSql($diffResult, [], []);

        $this->assertArrayHasKey('tables', $sqls);
        $this->assertCount(1, $sqls['tables']['alter']);
        $this->assertStringContainsString('ENGINE=InnoDB', $sqls['tables']['alter'][0]);
    }

    /**
     * 精确路径：tables 维度跳过整表缺失（由 columns 维度处理）
     */
    public function testPreciseSqlTablesSkipsMissingTables(): void
    {
        $generator = new MysqlSqlGenerator();

        $diffResult = [
            'has_diff' => true,
            'details' => [
                'columns' => [
                    'has_diff' => true,
                    'summary' => ['only_in_baseline' => 1, 'only_in_live' => 0, 'field_changed' => 0],
                    'diffs_by_table' => [
                        'new_table' => [
                            'table_missing' => true,
                            'missing_side' => 'live',
                            'only_in_baseline' => [],
                            'only_in_live' => [],
                            'field_changed' => [],
                        ],
                    ],
                ],
                'tables' => [
                    'has_diff' => true,
                    'summary' => ['only_in_baseline' => 1, 'only_in_live' => 0, 'field_changed' => 0],
                    'only_in_baseline' => ['new_table'],
                ],
            ],
        ];

        $sqls = $generator->generatePreciseSql($diffResult, [], []);

        // columns 维度生成 CREATE TABLE
        $this->assertCount(1, $sqls['columns']['add']);
        $this->assertStringContainsString('CREATE TABLE', $sqls['columns']['add'][0]);

        // tables 维度不应重复生成 CREATE TABLE
        $this->assertEmpty($sqls['tables']['create']);
        $this->assertEmpty($sqls['tables']['drop']);
    }

    /**
     * 精确路径：索引 only_in_baseline -> CREATE INDEX 用基准定义
     */
    public function testPreciseIndexSqlCreateFromBaseline(): void
    {
        $generator = new MysqlSqlGenerator();

        $diffResult = [
            'has_diff' => true,
            'details' => [
                'indexes' => [
                    'has_diff' => true,
                    'summary' => ['only_in_baseline' => 1, 'only_in_live' => 0, 'field_changed' => 0],
                    'diffs_by_index' => [
                        'users.idx_email' => [
                            'only_in_baseline' => ['users.idx_email.1'],
                        ],
                    ],
                ],
            ],
        ];

        $baselineData = [
            'indexes' => [
                ['table' => 'users', 'index_name' => 'idx_email', 'seq_in_index' => 1, 'column_name' => 'email', 'non_unique' => 0],
            ],
        ];

        $sqls = $generator->generatePreciseSql($diffResult, [], $baselineData);

        $this->assertCount(1, $sqls['indexes']['create']);
        $this->assertStringContainsString('CREATE UNIQUE INDEX `idx_email`', $sqls['indexes']['create'][0]);
        $this->assertStringContainsString('`email`', $sqls['indexes']['create'][0]);
    }

    /**
     * 精确路径：索引 only_in_live -> DROP INDEX
     */
    public function testPreciseIndexSqlDropLive(): void
    {
        $generator = new MysqlSqlGenerator();

        $diffResult = [
            'has_diff' => true,
            'details' => [
                'indexes' => [
                    'has_diff' => true,
                    'summary' => ['only_in_baseline' => 0, 'only_in_live' => 1, 'field_changed' => 0],
                    'diffs_by_index' => [
                        'users.idx_orphan' => [
                            'only_in_live' => ['users.idx_orphan.1'],
                        ],
                    ],
                ],
            ],
        ];

        $sqls = $generator->generatePreciseSql($diffResult, [], []);

        $this->assertCount(1, $sqls['indexes']['drop']);
        $this->assertStringContainsString('DROP INDEX `idx_orphan`', $sqls['indexes']['drop'][0]);
    }

    /**
     * 精确路径：整表缺失时索引级 SQL 被跳过（表级 DDL 由 columns 维度生成）
     */
    public function testPreciseIndexSqlSkipsMissingTables(): void
    {
        $generator = new MysqlSqlGenerator();

        $diffResult = [
            'has_diff' => true,
            'details' => [
                'columns' => [
                    'has_diff' => true,
                    'summary' => ['only_in_baseline' => 2, 'only_in_live' => 0, 'field_changed' => 0],
                    'diffs_by_table' => [
                        'new_table' => [
                            'table_missing' => true,
                            'missing_side' => 'live',
                            'only_in_baseline' => [],
                            'only_in_live' => [],
                            'field_changed' => [],
                        ],
                    ],
                ],
                'indexes' => [
                    'has_diff' => true,
                    'summary' => ['only_in_baseline' => 1, 'only_in_live' => 0, 'field_changed' => 0],
                    'diffs_by_index' => [
                        'new_table.idx_pk' => [
                            'only_in_baseline' => ['new_table.idx_pk.1'],
                        ],
                    ],
                ],
            ],
        ];

        $baselineData = [
            'indexes' => [
                ['table' => 'new_table', 'index_name' => 'idx_pk', 'seq_in_index' => 1, 'column_name' => 'id', 'non_unique' => 0],
            ],
        ];

        $sqls = $generator->generatePreciseSql($diffResult, [], $baselineData);

        // columns 维度生成 CREATE TABLE
        $this->assertCount(1, $sqls['columns']['add']);
        $this->assertStringContainsString('CREATE TABLE', $sqls['columns']['add'][0]);

        // indexes 维度跳过（整表缺失）
        $this->assertEmpty($sqls['indexes']['create']);
        $this->assertEmpty($sqls['indexes']['drop']);
    }

    /**
     * CK 精确路径：整表缺失时 indexes 维度跳过（不重复输出 warn）
     */
    public function testCkPreciseIndexSqlSkipsMissingTables(): void
    {
        $generator = new \TxAdmin\SchemaCompare\Generator\Ck\CkSqlGenerator();

        $diffResult = [
            'has_diff' => true,
            'details' => [
                'columns' => [
                    'has_diff' => true,
                    'summary' => ['only_in_baseline' => 2, 'only_in_live' => 0, 'field_changed' => 0],
                    'diffs_by_table' => [
                        'missing_tbl' => [
                            'table_missing' => true,
                            'missing_side' => 'live',
                            'only_in_baseline' => [],
                            'only_in_live' => [],
                            'field_changed' => [],
                        ],
                    ],
                ],
                'indexes' => [
                    'has_diff' => true,
                    'summary' => ['only_in_baseline' => 1, 'only_in_live' => 0, 'field_changed' => 0],
                    'only_in_baseline' => ['missing_tbl'],
                    'only_in_live' => [],
                    'changed' => [],
                ],
            ],
        ];

        $sqls = $generator->generatePreciseSql($diffResult, [], []);

        // columns 维度生成 CREATE TABLE 占位
        $this->assertCount(1, $sqls['columns']['add']);
        $this->assertStringContainsString('CREATE TABLE', $sqls['columns']['add'][0]);

        // indexes 维度不应为 missing_tbl 输出 warn（表级 DDL 由 columns 维度处理）
        $warnText = implode(' ', $sqls['indexes']['warn'] ?? []);
        $this->assertStringNotContainsString('missing_tbl', $warnText);
    }

    // ----------------------------------------------------------------
    // 门面
    // ----------------------------------------------------------------

    public function testSqlGeneratorFacadeDelegatesToMysql(): void
    {
        $facade = new SqlGenerator('mysql');
        $delegate = $facade->getDelegate();

        $this->assertInstanceOf(MysqlSqlGenerator::class, $delegate);
    }
}
