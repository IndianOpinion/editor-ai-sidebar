<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar\Tests\Support;

/**
 * The stub wpdb with a real MySQL connection, for the integration test.
 * Returns what core's wpdb returns: affected rows for writes, true for
 * schema statements, arrays for ARRAY_A reads.
 */
final class MysqliWpdb extends \wpdb
{
    public function __construct(private readonly \mysqli $link)
    {
    }

    protected function escape(string $value): string
    {
        return $this->link->real_escape_string($value);
    }

    public function query(string $query): int|bool
    {
        $this->queries[] = $query;
        $result = $this->link->query($query);
        if ($result === false) {
            $this->last_error = $this->link->error;
            return false;
        }
        if ($result instanceof \mysqli_result) {
            $rows = $result->num_rows;
            $result->free();
            return (int) $rows;
        }
        return preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)/i', $query) === 1 ? $this->link->affected_rows : true;
    }

    public function get_row(string $query, string $output = 'OBJECT'): mixed
    {
        $rows = $this->get_results($query, $output);
        return $rows[0] ?? null;
    }

    public function get_results(string $query, string $output = 'OBJECT'): array
    {
        $this->queries[] = $query;
        $result = $this->link->query($query);
        if (!$result instanceof \mysqli_result) {
            return [];
        }
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        return $rows;
    }

    public function get_var(string $query): mixed
    {
        $row = $this->get_row($query, 'ARRAY_A');
        return is_array($row) ? array_values($row)[0] ?? null : null;
    }
}
