<?php
declare(strict_types=1);

/** Read definitions only, never the seed statements in the installation schema. */
function kk_schema_definitions(string $sql): array
{
    preg_match_all('/CREATE TABLE IF NOT EXISTS `([^`]+)` \(([\s\S]*?)\) ENGINE=[^;]+;/', $sql, $matches, PREG_SET_ORDER);
    $tables = [];
    foreach ($matches as $match) {
        preg_match_all('/^\s+`([^`]+)` ([^\r\n]+)/m', $match[2], $columns, PREG_SET_ORDER);
        foreach ($columns as $column) $tables[$match[1]][$column[1]] = rtrim($column[2], ',');
    }
    return $tables;
}

function kk_schema_issues(array $expected, array $actual): array
{
    $issues = [];
    foreach ($expected as $table => $columns) {
        if (!isset($actual[$table])) { $issues[] = "Missing table: $table"; continue; }
        foreach ($columns as $name => $definition) {
            if (!isset($actual[$table][$name])) { $issues[] = "Missing column: $table.$name"; continue; }
            $live = $actual[$table][$name];
            if (preg_match('/^enum\(([^)]+)\)/i', $definition, $wanted)) {
                if (!preg_match('/^enum\(([^)]+)\)/i', $live['COLUMN_TYPE'], $found)) {
                    $issues[] = "Review type manually: $table.$name";
                    continue;
                }
                $values = static fn($s) => str_getcsv($s, ',', "'", '\\');
                $missing = array_diff($values($wanted[1]), $values($found[1]));
                $unknown = array_diff($values($found[1]), $values($wanted[1]));
                if ($missing) $issues[] = "Missing enum values: $table.$name (" . implode(', ', $missing) . ')';
                if ($unknown) $issues[] = "Preserve custom enum values before migration: $table.$name (" . implode(', ', $unknown) . ')';
            }
            if (preg_match('/^varchar\((\d+)\)/i', $definition, $wanted) && preg_match('/^varchar\((\d+)\)/i', $live['COLUMN_TYPE'], $found) && (int)$found[1] < (int)$wanted[1]) {
                $issues[] = "Column too short: $table.$name ({$found[1]} < {$wanted[1]})";
            }
            if ($table === 'drawer_logs' && $name === 'pin_used' && $live['IS_NULLABLE'] !== 'YES') {
                $issues[] = 'drawer_logs.pin_used must allow NULL (PINs must not be logged).';
            }
        }
    }
    return $issues;
}
