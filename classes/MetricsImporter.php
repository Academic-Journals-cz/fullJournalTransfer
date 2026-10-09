<?php

/**
 * @file plugins/importexport/fullJournalTransfer/classes/MetricsImporter.php
 *
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class MetricsImporter
 *
 * @brief Reads the usage statistics CSV files of an archive and inserts them
 *  for the imported journal. IDs of submissions, galleys, files, issues and
 *  issue galleys are remapped; rows that refer to objects that were not
 *  imported are skipped.
 */

namespace APP\plugins\importexport\fullJournalTransfer\classes;

use APP\journal\Journal;
use APP\plugins\importexport\fullJournalTransfer\FullJournalImportExportDeployment;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MetricsImporter
{
    public const BATCH_SIZE = 1000;

    public function __construct(private FullJournalImportExportDeployment $deployment)
    {
    }

    /**
     * @return array<string,int> Number of imported rows per table
     */
    public function import(string $sourceDir, Journal $journal): array
    {
        $counts = [];
        foreach (MetricsTables::getTables() as $table => $spec) {
            $path = rtrim($sourceDir, '/') . '/' . MetricsTables::getFileName($table);
            if (!is_readable($path)) {
                continue;
            }
            if (!Schema::hasTable($table)) {
                $this->deployment->addWarning(\APP\core\Application::ASSOC_TYPE_JOURNAL, $journal->getId(), 'Metrics table ' . $table . ' does not exist, skipped.');
                continue;
            }
            $counts[$table] = $this->importTable($path, $table, $spec, (int) $journal->getId());
        }
        return $counts;
    }

    protected function importTable(string $path, string $table, array $spec, int $contextId): int
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new Exception('Could not read ' . $path);
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return 0;
        }
        $existingColumns = Schema::getColumnListing($table);
        $columns = [];
        foreach ($header as $index => $column) {
            if (in_array($column, $existingColumns) && $column !== $spec['pk']) {
                $columns[$index] = $column;
            }
        }

        $batch = [];
        $imported = 0;
        $skipped = 0;
        while (($values = fgetcsv($handle)) !== false) {
            if (count($values) === 1 && $values[0] === null) {
                continue; // blank line
            }
            $row = [];
            foreach ($columns as $index => $column) {
                $value = $values[$index] ?? null;
                if ($value === '\\N' || ($value === '' && in_array($column, $spec['nullable']))) {
                    $value = null;
                }
                $row[$column] = $value;
            }
            $row['context_id'] = $contextId;

            $skip = false;
            foreach ($spec['map'] as $column => $entity) {
                if (!array_key_exists($column, $row) || $row[$column] === null || $row[$column] === '') {
                    continue;
                }
                $newId = $this->mapId($entity, $row[$column]);
                if ($newId === null) {
                    $skip = true;
                    break;
                }
                $row[$column] = $newId;
            }
            if ($skip) {
                $skipped++;
                continue;
            }

            $batch[] = $row;
            if (count($batch) >= self::BATCH_SIZE) {
                DB::table($table)->insert($batch);
                $imported += count($batch);
                $batch = [];
            }
        }
        if (!empty($batch)) {
            DB::table($table)->insert($batch);
            $imported += count($batch);
        }
        fclose($handle);

        if ($skipped > 0) {
            $this->deployment->incrementCounter('metrics rows skipped (' . $table . ')', $skipped);
        }
        return $imported;
    }

    protected function mapId(string $entity, $oldId): ?int
    {
        return match ($entity) {
            'submissionFile' => $this->deployment->getSubmissionFileDBId($oldId),
            default => $this->deployment->getMappedId($entity, $oldId),
        };
    }
}
