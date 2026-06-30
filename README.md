# tx-admin/schema-compare

框架无关的数据库结构对比 Composer 包，支持 ClickHouse 和 MySQL（骨架），适配 ThinkPHP 6 / Hyperf。

---

## 目录结构

```
src/
├── Contracts/
│   ├── ConnectionAdapterInterface.php   DB 连接抽象
│   └── SchemaStrategyInterface.php      对比策略接口
├── Adapters/
│   ├── ThinkPHPAdapter.php              ThinkPHP 6 适配器
│   └── HyperfAdapter.php                Hyperf 适配器
├── Strategy/
│   ├── AbstractStrategy.php             策略基类（管理 compareFields + buildMap）
│   └── Ck/
│       ├── CkColumnsStrategy.php        system.columns 对比
│       ├── CkIndexesStrategy.php        system.tables 索引键对比
│       └── CkProjectionsStrategy.php    system.projection_parts_columns 对比
├── Driver/
│   ├── CkSchemaDriver.php               ClickHouse 驱动（默认 3 策略）
│   └── MysqlSchemaDriver.php            MySQL 驱动（骨架）
├── AbstractSchemaDriver.php             驱动基类
├── SchemaCompareManager.php             驱动注册中心
└── Exceptions/
    ├── SchemaCompareException.php
    ├── InvalidBaselineException.php
    └── DriverNotFoundException.php
```

---

## 核心设计

### 分层职责

| 层级 | 职责 | 不涉及 |
|------|------|--------|
| **策略** `SchemaStrategyInterface` | 查询单一维度数据 + diff | 框架、文件 IO |
| **驱动** `AbstractSchemaDriver` | 聚合策略，提供 fetchStructure / diff / compareFromJson | 文件 IO、SVN |
| **管理器** `SchemaCompareManager` | 驱动注册 + 代理入口 | — |
| **应用层**（center_sys/api_sys） | 文件 IO、SVN 提交、配置读取 | 对比逻辑 |

### 策略设计

每个策略类实现 `SchemaStrategyInterface`：

```php
interface SchemaStrategyInterface
{
    public function getKey(): string;               // 如 'columns'
    public function getDefaultCompareFields(): array;
    public function fetchData(ConnectionAdapterInterface $adapter, string $database): array;
    public function diff(array $baseline, array $live): array;
}
```

驱动构造时注入策略集合（空数组 → 使用 `defaultStrategies()`），可按需覆盖或扩展。

---

## 快速上手

### ThinkPHP 6

```php
use TxAdmin\SchemaCompare\Adapters\ThinkPHPAdapter;
use TxAdmin\SchemaCompare\Driver\CkSchemaDriver;

$adapter = new ThinkPHPAdapter('clickhouse');   // 连接池名
$driver  = new CkSchemaDriver($adapter);

// 导出基准 JSON
$json = $driver->exportJson('2001_log_metadata');
file_put_contents('/path/to/baseline.json', $json);

// 对比基准 vs 实时
$result = $driver->compareFromJson(
    file_get_contents('/path/to/baseline.json'),
    '2001_log_metadata'
);

var_dump($result['has_diff']);       // bool
var_dump($result['details']);        // ['columns' => [...], 'indexes' => [...], 'projections' => [...]]
```

### Hyperf

```php
use TxAdmin\SchemaCompare\Adapters\HyperfAdapter;
use TxAdmin\SchemaCompare\Driver\CkSchemaDriver;

$adapter = new HyperfAdapter('clickhouse');
$driver  = new CkSchemaDriver($adapter);

$result = $driver->compareFromJson($jsonBaseline, $database);
```

### 使用管理器（多驱动场景）

```php
use TxAdmin\SchemaCompare\SchemaCompareManager;

$manager = new SchemaCompareManager();
$manager->register(new CkSchemaDriver(new ThinkPHPAdapter('clickhouse')));
// $manager->register(new MysqlSchemaDriver(new ThinkPHPAdapter('default')));

$result = $manager->compareFromJson('clickhouse', $jsonBaseline, $database);
```

---

## diff 返回格式

```php
[
    'has_diff' => bool,   // 是否有差异
    'details'  => [
        'columns'     => [
            'has_diff'       => bool,   // 该维度是否有差异
            'summary'        => [
                'only_in_baseline' => n,  // 基准有但线上无（SVN有但MySQL没有 = 可能被删了）
                'only_in_live'     => n,  // 线上有但基准无（MySQL有但SVN没有 = 可能新增了）
                'field_changed'    => n,  // 同名字段属性变化数
            ],
            'diffs_by_table' => [
                'table_name' => [
                    'only_in_baseline' => [...],  // 该表被删除的字段列表
                    'only_in_live'     => [...],  // 该表新增的字段列表
                    'field_changed'    => [...],  // 字段属性变化详情
                ]
            ],
        ],
        'indexes'     => [
            'has_diff'         => bool,
            'summary'          => [...],
            'only_in_baseline' => [...],  // 基准有但线上无的索引
            'only_in_live'     => [...],  // 线上有但基准无的索引
            'changed'          => [...],  // 索引属性变化
        ],
        'projections' => [
            'has_diff'            => bool,
            'summary'             => [...],
            'diffs_by_projection' => ['table.proj' => [...]],
        ],
    ],
]
```

### 字段属性名对照（Columns）

| 英文属性名 | 中文含义 | 示例值 |
|-----------|---------|--------|
| `type` | 类型 | `varchar(255)`、`int(11)` |
| `is_nullable` | 是否可空 | `YES` / `NO` |
| `column_default` | 默认值 | `NULL`、`0`、`current_timestamp()` |
| `extra` | 额外属性 | `auto_increment`、`on update CURRENT_TIMESTAMP` |
| `character_set_name` | 字符集 | `utf8mb4` |
| `collation_name` | 排序规则 | `utf8mb4_general_ci` |
| `comment` | 备注 | 字段注释 |

### 术语说明

- **baseline（基准）**：SVN 中保存的 JSON 基准文件，代表"期望的结构"
- **live（线上）**：当前数据库实时查询到的结构，代表"实际的结构"
- **only_in_baseline**：基准有但线上无 → SVN有但MySQL没有（可能被删了）
- **only_in_live**：线上有但基准无 → MySQL有但SVN没有（可能新增了）
- **field_changed**：同名字段的属性不一致（如类型、默认值等变了）

---

## 自定义策略字段

```php
use TxAdmin\SchemaCompare\Strategy\Ck\CkColumnsStrategy;

// 只对比 type 字段
$driver = new CkSchemaDriver($adapter, 'clickhouse', [
    new CkColumnsStrategy(['type']),
    new CkIndexesStrategy(),
    // 不加 CkProjectionsStrategy → 不对比投影
]);
```

---

## 自定义策略类

```php
use TxAdmin\SchemaCompare\Strategy\AbstractStrategy;
use TxAdmin\SchemaCompare\Contracts\ConnectionAdapterInterface;

class CkEnginesStrategy extends AbstractStrategy
{
    public function getKey(): string { return 'engines'; }

    public function getDefaultCompareFields(): array { return ['engine']; }

    public function fetchData(ConnectionAdapterInterface $adapter, string $database): array
    {
        return $adapter->query(
            "SELECT name AS table, engine FROM system.tables WHERE database = '{$database}' ORDER BY name"
        );
    }

    public function diff(array $baseline, array $live): array
    {
        // ...自定义 diff 逻辑
    }
}

// 注入到驱动
$driver = new CkSchemaDriver($adapter, 'clickhouse', [
    new CkColumnsStrategy(),
    new CkIndexesStrategy(),
    new CkProjectionsStrategy(),
    new CkEnginesStrategy(),    // ← 新增维度
]);
```

---

## PHP 版本

最低 PHP **7.4**（不使用 readonly、enum 等 8.x 特性）。
