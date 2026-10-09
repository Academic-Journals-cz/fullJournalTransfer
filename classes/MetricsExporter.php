<?php

/**
 * @file plugins/importexport/fullJournalTransfer/classes/MetricsExporter.php
 *
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class MetricsExporter
 *
 * @brief Writes the usage statistics of a journal into CSV files (one per
 *  metrics table). The rows are streamed, so journals with millions of rows
 *  can be exported without loading everything into memory.
 */

namespace APP\plugins\importexport\fullJournalTransfer\classes;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MetricsExporter
{
    public const CHUNK_SIZE = 5000;

    /**
     * @return array<string,int> Number of exported rows per table
     */
    public function export(int $contextId, string $targetDir): array
    {
        if (!is_dir($targetDir) && !mkdir($targetDir, 0770, true)) {
            throw new Exception('Could not create the directory ' . $targetDir);
        }

        $counts = [];
        foreach (MetricsTables::getTables() as $table => $spec) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $columns = array_values(array_diff(Schema::getColumnListing($table), [$spec['pk']]));
            if (!in_array('context_id', $columns)) {
                continue;
            }

            $path = rtrim($targetDir, '/') . '/' . MetricsTables::getFileName($table);
            $handle = fopen($path, 'w');
            if (!$handle) {
                throw new Exception('Could not write ' . $path);
            }
            fputcsv($handle, $columns);

            $count = 0;
            DB::table($table)
                ->select(array_merge([$spec['pk']], $columns))
                ->where('context_id', $contextId)
                ->chunkById(self::CHUNK_SIZE, function ($rows) use ($handle, $columns, &$count) {
                    foreach ($rows as $row) {
                        $values = [];
                        foreach ($columns as $column) {
                            $value = $row->{$column};
                            // NULL is written as the literal \N (empty strings stay empty)
                            $values[] = $value === null ? '\\N' : (string) $value;
                        }
                        fputcsv($handle, $values);
                        $count++;
                    }
                }, $spec['pk']);
            fclose($handle);

            if ($count === 0) {
                unlink($path);
            } else {
                $counts[$table] = $count;
            }
        }

        return $counts;
    }
}
