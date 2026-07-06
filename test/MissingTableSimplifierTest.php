<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Test;

use PHPUnit\Framework\TestCase;
use TxAdmin\SchemaCompare\Processor\MissingTableSimplifier;

/**
 * MissingTableSimplifier 单元测试
 */
class MissingTableSimplifierTest extends TestCase
{
    private MissingTableSimplifier $simplifier;

    protected function setUp(): void
    {
        $this->simplifier = new MissingTableSimplifier();
    }

    /**
     * 测试无差异时不处理
     */
    public function testNoDiffReturnsOriginal(): void
    {
        $diffResult = [
            'has_diff' => false,
            'details' => [],
        ];

        $result = $this->simplifier->process($diffResult);

        $this->assertFalse($result['has_diff']);
        $this->assertEmpty($result['details']);
    }

    /**
     * 测试整表只在基准（线上缺表）
     */
    public function testTableOnlyInBaseline(): void
    {
        $diffResult = [
            'has_diff' => true,
            'details' => [
                'columns' => [
                    'diffs_by_table' => [
                        'users' => [
                            'only_in_baseline' => ['users.id', 'users.name'],
                            'only_in_live' => [],
                            'field_changed' => [],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->simplifier->process($diffResult);

        $this->assertTrue($result['details']['columns']['diffs_by_table']['users']['table_missing']);
        $this->assertSame('live', $result['details']['columns']['diffs_by_table']['users']['missing_side']);
        $this->assertEmpty($result['details']['columns']['diffs_by_table']['users']['only_in_baseline']);
    }

    /**
     * 测试整表只在线上（基准缺表）
     */
    public function testTableOnlyInLive(): void
    {
        $diffResult = [
            'has_diff' => true,
            'details' => [
                'columns' => [
                    'diffs_by_table' => [
                        'orders' => [
                            'only_in_baseline' => [],
                            'only_in_live' => ['orders.id', 'orders.total'],
                            'field_changed' => [],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->simplifier->process($diffResult);

        $this->assertTrue($result['details']['columns']['diffs_by_table']['orders']['table_missing']);
        $this->assertSame('baseline', $result['details']['columns']['diffs_by_table']['orders']['missing_side']);
        $this->assertEmpty($result['details']['columns']['diffs_by_table']['orders']['only_in_live']);
    }

    /**
     * 测试两侧各有字段（非整表缺失，不简化）
     */
    public function testMixedFieldsNotSimplified(): void
    {
        $diffResult = [
            'has_diff' => true,
            'details' => [
                'columns' => [
                    'diffs_by_table' => [
                        'users' => [
                            'only_in_baseline' => ['users.old_field'],
                            'only_in_live' => ['users.new_field'],
                            'field_changed' => [],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->simplifier->process($diffResult);

        $this->assertFalse(isset($result['details']['columns']['diffs_by_table']['users']['table_missing']));
        $this->assertCount(1, $result['details']['columns']['diffs_by_table']['users']['only_in_baseline']);
        $this->assertCount(1, $result['details']['columns']['diffs_by_table']['users']['only_in_live']);
    }

    /**
     * 测试有字段变更（非整表缺失，不简化）
     */
    public function testFieldChangedNotSimplified(): void
    {
        $diffResult = [
            'has_diff' => true,
            'details' => [
                'columns' => [
                    'diffs_by_table' => [
                        'users' => [
                            'only_in_baseline' => ['users.id'],
                            'only_in_live' => [],
                            'field_changed' => ['users.name' => ['baseline' => 'varchar(50)', 'live' => 'varchar(100)']],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->simplifier->process($diffResult);

        $this->assertFalse(isset($result['details']['columns']['diffs_by_table']['users']['table_missing']));
    }

    /**
     * 测试多维度同步简化
     */
    public function testMultiDimensionSimplification(): void
    {
        $diffResult = [
            'has_diff' => true,
            'details' => [
                'columns' => [
                    'diffs_by_table' => [
                        'logs' => [
                            'only_in_baseline' => ['logs.id', 'logs.msg'],
                            'only_in_live' => [],
                            'field_changed' => [],
                        ],
                    ],
                ],
                'indexes' => [
                    'diffs_by_table' => [
                        'logs' => [
                            'only_in_baseline' => ['logs.PRIMARY'],
                            'only_in_live' => [],
                            'field_changed' => [],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->simplifier->process($diffResult);

        $this->assertTrue($result['details']['columns']['diffs_by_table']['logs']['table_missing']);
        $this->assertTrue($result['details']['indexes']['diffs_by_table']['logs']['table_missing']);
    }

    /**
     * 测试混合场景（部分表简化，部分不简化）
     */
    public function testMixedTables(): void
    {
        $diffResult = [
            'has_diff' => true,
            'details' => [
                'columns' => [
                    'diffs_by_table' => [
                        'users' => [  // 整表缺失，应简化
                            'only_in_baseline' => ['users.id'],
                            'only_in_live' => [],
                            'field_changed' => [],
                        ],
                        'orders' => [  // 有字段变更，不应简化
                            'only_in_baseline' => [],
                            'only_in_live' => [],
                            'field_changed' => ['orders.total' => ['baseline' => 'int', 'live' => 'decimal']],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->simplifier->process($diffResult);

        // users 被简化
        $this->assertTrue($result['details']['columns']['diffs_by_table']['users']['table_missing']);

        // orders 未被简化
        $this->assertFalse(isset($result['details']['columns']['diffs_by_table']['orders']['table_missing']));
        $this->assertNotEmpty($result['details']['columns']['diffs_by_table']['orders']['field_changed']);
    }
}
