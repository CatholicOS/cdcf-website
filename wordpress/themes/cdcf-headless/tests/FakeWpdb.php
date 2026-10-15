<?php

declare(strict_types=1);

/**
 * Recording stand-in for $wpdb. Every SQL string passed to query/get_*
 * lands in $queries; every insert lands in $inserts. Read methods pop
 * their return value from the FIFO $results queue (falling back to an
 * empty result), so a test scripts the database by pushing values in
 * the order the code under test will read them.
 */
final class FakeWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public string $last_error = '';
    /** @var list<array{table: string, data: array<string, mixed>}> */
    public array $inserts = [];
    /** @var list<string> */
    public array $queries = [];
    /** @var list<mixed> */
    public array $results = [];
    public bool $failInsert = false;
    private int $nextId = 1;

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4';
    }

    public function prepare(string $query, mixed ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $query = str_replace(['%d', '%s'], ['%d', "'%s'"], $query);
        return vsprintf($query, array_map(
            static fn($a) => is_string($a) ? addslashes($a) : $a,
            $args
        ));
    }

    public function insert(string $table, array $data, mixed $format = null): int|false
    {
        if ($this->failInsert) {
            $this->last_error = 'Duplicate entry';
            return false;
        }
        $this->inserts[] = ['table' => $table, 'data' => $data];
        $this->insert_id = $this->nextId++;
        return 1;
    }

    public function query(string $sql): int|bool
    {
        $this->queries[] = $sql;
        return array_shift($this->results) ?? 1;
    }

    public function get_results(string $sql, string $output = 'OBJECT'): array
    {
        $this->queries[] = $sql;
        return array_shift($this->results) ?? [];
    }

    public function get_row(string $sql, string $output = 'OBJECT'): ?array
    {
        $this->queries[] = $sql;
        return array_shift($this->results);
    }

    public function get_var(string $sql): mixed
    {
        $this->queries[] = $sql;
        return array_shift($this->results);
    }
}
