<?php

/**
 * @file plugins/importexport/fullJournalTransfer/classes/MetricsTables.php
 *
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class MetricsTables
 *
 * @brief Description of the usage statistics tables (OJS 3.4/3.5) that are
 *  transferred: primary key, columns referencing other transferred objects and
 *  nullable columns.
 *
 *  The institution based COUNTER tables (metrics_counter_submission_institution_*)
 *  reference the institutions of the context, which are transferred with the
 *  journal (institutions element).
 */

namespace APP\plugins\importexport\fullJournalTransfer\classes;

class MetricsTables
{
    /**
     * @return array<string, array{pk: string, map: array<string,string>, nullable: string[]}>
     */
    public static function getTables(): array
    {
        return [
            'metrics_context' => [
                'pk' => 'metrics_context_id',
                'map' => [],
                'nullable' => [],
            ],
            'metrics_issue' => [
                'pk' => 'metrics_issue_id',
                'map' => ['issue_id' => 'issue', 'issue_galley_id' => 'issueGalley'],
                'nullable' => ['issue_galley_id'],
            ],
            'metrics_submission' => [
                'pk' => 'metrics_submission_id',
                'map' => ['submission_id' => 'submission', 'representation_id' => 'representation', 'submission_file_id' => 'submissionFile'],
                'nullable' => ['representation_id', 'submission_file_id', 'file_type'],
            ],
            'metrics_submission_geo_daily' => [
                'pk' => 'metrics_submission_geo_daily_id',
                'map' => ['submission_id' => 'submission'],
                'nullable' => [],
            ],
            'metrics_submission_geo_monthly' => [
                'pk' => 'metrics_submission_geo_monthly_id',
                'map' => ['submission_id' => 'submission'],
                'nullable' => [],
            ],
            'metrics_counter_submission_daily' => [
                'pk' => 'metrics_counter_submission_daily_id',
                'map' => ['submission_id' => 'submission'],
                'nullable' => [],
            ],
            'metrics_counter_submission_monthly' => [
                'pk' => 'metrics_counter_submission_monthly_id',
                'map' => ['submission_id' => 'submission'],
                'nullable' => [],
            ],
            'metrics_counter_submission_institution_daily' => [
                'pk' => 'metrics_counter_submission_institution_daily_id',
                'map' => ['submission_id' => 'submission', 'institution_id' => 'institution'],
                'nullable' => [],
            ],
            'metrics_counter_submission_institution_monthly' => [
                'pk' => 'metrics_counter_submission_institution_monthly_id',
                'map' => ['submission_id' => 'submission', 'institution_id' => 'institution'],
                'nullable' => [],
            ],
        ];
    }

    public static function getFileName(string $table): string
    {
        return $table . '.csv';
    }
}
