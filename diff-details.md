# diff 结果 `details` 字段说明

本文档说明 `compareFromJson()` / `compareFromArray()` / `diff()` 返回值中 `details` 的完整结构。

## 顶层返回结构

```php
$diffResult = $driver->compareFromJson($jsonBaseline, $database);
// 或
$diffResult = $driver->diff($baseline, $live);
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `has_diff` | `bool` | 任一维度存在差异则为 `true` |
| `details` | `array` | **按策略维度拆分的差异详情**（本文重点） |
| `warnings` | `string[]` | 非致命提示，如基准含当前驱动未注册的维度 |

`details` 的 key 由驱动注册的策略 `getKey()` 决定：

| 驱动 | `details` 维度 key |
|------|-------------------|
| ClickHouse (`CkSchemaDriver`) | `tables` / `columns` / `indexes` / `projections` |
| MySQL (`MysqlSchemaDriver`) | `columns` / `indexes` / `tables` |

---

## 各维度通用结构

每个维度的值均由对应策略的 `diff()` 返回，结构统一为：

```php
[
    'has_diff' => bool,
    'summary'  => [
        'only_in_baseline' => int,  // 基准有、线上无的条目数
        'only_in_live'     => int,  // 线上有、基准无的条目数
        'field_changed'    => int,  // 双方都有但属性变化的条目数
    ],
    // 以下三选一（按维度不同）：
    'diffs_by_table'       => [...],  // tables / columns / CK indexes
    'diffs_by_index'       => [...],  // MySQL indexes
    'diffs_by_projection'  => [...],  // CK projections
]
```

### 语义约定

| 概念 | 含义 |
|------|------|
| **baseline（基准）** | 导出的 JSON / 数组快照，通常代表「期望结构」 |
| **live（线上）** | 对比时实时查询的目标库结构 |
| **only_in_baseline** | 基准有、线上没有 → 线上缺失（需补齐）或基准多余 |
| **only_in_live** | 线上有、基准没有 → 线上多出（需删除）或基准未收录 |
| **field_changed** | 同一对象两边都存在，但对比字段取值不同 |

属性差异统一格式：

```php
'字段名' => ['baseline' => '基准值', 'live' => '线上值']
```

---

## 1. `columns` — 字段对比

### ClickHouse（`CkColumnsStrategy`）

- **数据来源**：`system.columns`
- **diff key**：`{table}.{name}`（表名.列名）
- **归组字段**：`diffs_by_table`（key = 表名）

**默认对比字段：**

| 字段 | 说明 |
|------|------|
| `type` | 列类型（含长度，如 `Decimal(18,2)`） |
| `default_kind` | 默认值类型（`DEFAULT` / `MATERIALIZED` / `ALIAS`） |
| `default_expression` | 默认值表达式 |
| `comment` | 列注释 |
| `compression_codec` | 压缩编码 |
| `is_in_partition_key` | 是否在分区键中 |
| `is_in_sorting_key` | 是否在排序键中 |
| `is_in_primary_key` | 是否在主键中 |
| `is_in_sampling_key` | 是否在采样键中 |

### MySQL（`MysqlColumnsStrategy`）

- **数据来源**：`information_schema.COLUMNS`
- **diff key**：`{table}.{name}`
- **归组字段**：`diffs_by_table`
- 默认排除分表（表名以 `_数字` 结尾）

**默认对比字段：**

| 字段 | 说明 |
|------|------|
| `type` | 列类型（如 `varchar(255)`、`decimal(18,2)`） |
| `is_nullable` | 是否可空（`YES` / `NO`） |
| `column_default` | 默认值 |
| `extra` | 额外属性（`auto_increment`、`on update CURRENT_TIMESTAMP` 等） |
| `character_set_name` | 字符集 |
| `collation_name` | 排序规则 |
| `comment` | 列注释 |
| `ordinal_position` | 字段在表中的位置顺序 |

### 示例

```php
$diffResult['details']['columns'] = [
    'has_diff' => true,
    'summary' => [
        'only_in_baseline' => 1,
        'only_in_live'     => 1,
        'field_changed'    => 1,
    ],
    'diffs_by_table' => [
        'tx_user' => [
            // 基准有、线上无 → 线上缺该列
            'only_in_baseline' => ['tx_user.new_field'],
            // 线上有、基准无 → 线上多出该列
            'only_in_live' => ['tx_user.old_field'],
            // 同一列属性变化
            'field_changed' => [
                'tx_user.name' => [
                    'type' => [
                        'baseline' => 'varchar(100)',
                        'live'     => 'varchar(50)',
                    ],
                    'ordinal_position' => [
                        'baseline' => '3',
                        'live'     => '2',
                    ],
                ],
            ],
        ],
    ],
];
```

---

## 2. `indexes` — 索引 / 键对比

### ClickHouse（`CkIndexesStrategy`）

对比的是表级键定义（分区/排序/主键/采样），**不是**二级索引列表。

- **数据来源**：`system.tables`
- **diff key**：`{table}`（表名）
- **归组字段**：`diffs_by_table`

**默认对比字段：**

| 字段 | 说明 |
|------|------|
| `partition_key` | 分区键表达式 |
| `sorting_key` | 排序键表达式 |
| `primary_key` | 主键表达式 |
| `sampling_key` | 采样键表达式 |

> `engine` 会随 `fetchData` 一并查出，供 SQL 生成器判断 ALTER 是否可用，**不参与对比**。

**示例：**

```php
$diffResult['details']['indexes'] = [
    'has_diff' => true,
    'summary' => [
        'only_in_baseline' => 0,
        'only_in_live'     => 0,
        'field_changed'    => 1,
    ],
    'diffs_by_table' => [
        'event_log' => [
            'field_changed' => [
                'sorting_key' => [
                    'baseline' => 'user_id, event_time',
                    'live'     => 'event_time, user_id',
                ],
            ],
        ],
    ],
];
```

### MySQL（`MysqlIndexesStrategy`）

- **数据来源**：`information_schema.STATISTICS`
- **diff key**：`{table}.{index_name}.{seq_in_index}`（联合索引每个列一行）
- **归组字段**：`diffs_by_index`（key = `{table}.{index_name}`）
- 默认排除分表

**默认对比字段：**

| 字段 | 说明 |
|------|------|
| `column_name` | 索引列名 |
| `non_unique` | 是否非唯一（`1`=普通索引，`0`=唯一索引） |
| `index_type` | 索引类型（`BTREE` / `FULLTEXT` / `HASH`） |
| `collation` | 排序方向（`A`=升序，`D`=降序，空=无序） |
| `sub_part` | 前缀索引长度（空表示整列） |

**示例：**

```php
$diffResult['details']['indexes'] = [
    'has_diff' => true,
    'summary' => [
        'only_in_baseline' => 1,
        'only_in_live'     => 0,
        'field_changed'    => 1,
    ],
    'diffs_by_index' => [
        'tx_user.idx_name' => [
            'only_in_baseline' => ['tx_user.idx_name.1'],
            'field_changed' => [
                'tx_user.idx_name.1' => [
                    'column_name' => [
                        'baseline' => 'name',
                        'live'     => 'nickname',
                    ],
                ],
            ],
        ],
    ],
];
```

---

## 3. `tables` — 表级属性对比

### ClickHouse（`CkTableStrategy`）

- **数据来源**：`system.tables`
- **diff key**：`{table}`
- **归组字段**：`diffs_by_table`

**默认对比字段：**

| 字段 | 说明 |
|------|------|
| `comment` | 表注释 |

> `engine` 默认**不参与对比**（阿里云与本地引擎名可能不同）。

与 `indexes` 维度的分工：

- `tables`：表是否存在 + 表注释等表级属性
- `indexes`：分区键 / 排序键 / 主键 / 采样键

### MySQL（`MysqlTableStrategy`）

- **数据来源**：`information_schema.TABLES`（仅 `BASE TABLE`）
- **diff key**：`{table}`
- **归组字段**：`diffs_by_table`
- 默认排除分表

**默认对比字段：**

| 字段 | 说明 |
|------|------|
| `engine` | 存储引擎（`InnoDB` / `MyISAM` / `MEMORY`） |
| `table_collation` | 表排序规则（如 `utf8mb4_general_ci`） |
| `table_comment` | 表注释 |

> `auto_increment` 会随 `fetchData` 查出并写入基准 JSON，但**不参与对比**（随数据增长会产生噪音）。

**示例：**

```php
$diffResult['details']['tables'] = [
    'has_diff' => true,
    'summary' => [
        'only_in_baseline' => 1,  // 线上缺表
        'only_in_live'     => 0,
        'field_changed'    => 1,
    ],
    'diffs_by_table' => [
        'tx_order' => [
            'only_in_baseline' => ['tx_order'],
        ],
        'tx_user' => [
            'field_changed' => [
                'engine' => [
                    'baseline' => 'InnoDB',
                    'live'     => 'MyISAM',
                ],
                'table_comment' => [
                    'baseline' => '用户表',
                    'live'     => '',
                ],
            ],
        ],
    ],
];
```

---

## 4. `projections` — ClickHouse 投影对比

仅 ClickHouse 驱动具备（`CkProjectionsStrategy`）。

- **数据来源**：`system.projection_parts_columns`（`active = 1`）
- **diff key**：`{table}.{projection}.{column_name}`
- **归组字段**：`diffs_by_projection`（key = `{table}.{projection}`）

**默认对比字段：**

| 字段 | 说明 |
|------|------|
| `type` | 投影中该列的类型 |

**示例：**

```php
$diffResult['details']['projections'] = [
    'has_diff' => true,
    'summary' => [
        'only_in_baseline' => 1,
        'only_in_live'     => 0,
        'field_changed'    => 1,
    ],
    'diffs_by_projection' => [
        'event_log.proj_by_user' => [
            'only_in_baseline' => ['event_log.proj_by_user.user_id'],
            'field_changed' => [
                'event_log.proj_by_user.event_time' => [
                    'type' => [
                        'baseline' => 'DateTime',
                        'live'     => 'DateTime64(3)',
                    ],
                ],
            ],
        ],
    ],
];
```

---

## 缺表简化标记（`MissingTableSimplifier`）

若应用层经过 `MissingTableSimplifier` / `DiffPipeline` 处理，当判定某表整表缺失时，会在对应分组下附加：

| 字段 | 类型 | 说明 |
|------|------|------|
| `table_missing` | `bool` | 固定为 `true`，表示整表缺失 |
| `missing_side` | `string` | `'live'` = 线上缺表（基准有）；`'baseline'` = 基准缺表（线上有） |

处理规则简述：

- **`tables` 维度**：保留原有 `only_in_*` 计数，额外打上 `table_missing` / `missing_side`
- **`columns` / CK `indexes`**：清空字段级差异，只保留表级缺失标记
- **MySQL `indexes` / CK `projections`**：移除该表下所有子条目，合并为一条以表名为 key 的 `table_missing` 条目

```php
// 简化后示例（columns 维度）
'diffs_by_table' => [
    'tx_order' => [
        'table_missing' => true,
        'missing_side'  => 'live',       // 线上缺这张表
        'only_in_baseline' => [],
        'only_in_live'     => [],
        'field_changed'    => [],
    ],
]
```

> 未经简化处理器时，原始 `diff()` 结果**不会**包含 `table_missing` / `missing_side`。

---

## 维度对照速查

| 维度 key | CK 归组字段 | MySQL 归组字段 | CK diff key | MySQL diff key |
|----------|-------------|---------------|-------------|----------------|
| `tables` | `diffs_by_table` | `diffs_by_table` | `{table}` | `{table}` |
| `columns` | `diffs_by_table` | `diffs_by_table` | `{table}.{name}` | `{table}.{name}` |
| `indexes` | `diffs_by_table` | `diffs_by_index` | `{table}` | `{table}.{index}.{seq}` |
| `projections` | `diffs_by_projection` | — | `{table}.{proj}.{col}` | — |

---

## 读取示例

```php
$diffResult = $driver->compareFromJson($json, $database);

if ($diffResult['has_diff']) {
    foreach ($diffResult['details'] as $dimension => $detail) {
        if (empty($detail['has_diff'])) {
            continue;
        }

        echo "维度: {$dimension}\n";
        echo "  基准多出: {$detail['summary']['only_in_baseline']}\n";
        echo "  线上多出: {$detail['summary']['only_in_live']}\n";
        echo "  属性变化: {$detail['summary']['field_changed']}\n";

        // 按维度取对应归组
        $grouped = $detail['diffs_by_table']
            ?? $detail['diffs_by_index']
            ?? $detail['diffs_by_projection']
            ?? [];

        foreach ($grouped as $groupKey => $diffs) {
            // $diffs 可能含 only_in_baseline / only_in_live / field_changed
            // 经 MissingTableSimplifier 后还可能含 table_missing / missing_side
        }
    }
}
```
