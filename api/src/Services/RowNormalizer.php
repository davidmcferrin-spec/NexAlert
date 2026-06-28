<?php
/**
 * NexAlert - Row Normalizer
 * Casts MySQL TINYINT flags to int so JSON responses are not string "0"/"1".
 */

declare(strict_types=1);

namespace NexAlert\Services;

class RowNormalizer
{
    /** @param array<string, mixed> $row */
    public static function flags(array $row, array $columns): array
    {
        foreach ($columns as $col) {
            if (array_key_exists($col, $row)) {
                $row[$col] = (int) $row[$col];
            }
        }

        return $row;
    }

    /** @param list<array<string, mixed>> $rows */
    public static function mapFlags(array $rows, array $columns): array
    {
        return array_map(
            static fn (array $row): array => self::flags($row, $columns),
            $rows
        );
    }
}
