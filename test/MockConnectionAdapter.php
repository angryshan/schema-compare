<?php

declare(strict_types=1);

namespace TxAdmin\SchemaCompare\Test;

use TxAdmin\SchemaCompare\Contracts\ConnectionAdapterInterface;

class MockConnectionAdapter implements ConnectionAdapterInterface
{
    /** @var array<string, array> */
    private array $responses = [];

    public function setResponse(string $sql, array $rows): self
    {
        $this->responses[$this->normalizeSql($sql)] = $rows;

        return $this;
    }

    public function query(string $sql): array
    {
        $key = $this->normalizeSql($sql);

        return $this->responses[$key] ?? [];
    }

    private function normalizeSql(string $sql): string
    {
        return preg_replace('/\s+/', ' ', trim($sql));
    }
}
