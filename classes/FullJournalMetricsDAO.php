<?php

/**
 * Copyright (c) 2014-2024 Lepidus Tecnologia
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 */
namespace APP\plugins\importexport\fullJournalTransfer\classes;

use PKP\db\DAO;

class FullJournalMetricsDAO extends DAO
{
    public function getByContextId(int $contextId): array
    {
        $result = $this->retrieve(
            'SELECT assoc_type, assoc_id, day, country_id, region, city, file_type, load_id, metric, metric_type
             FROM metrics
             WHERE context_id = ?',
            [(int) $contextId]
        );

        $returner = [];
        foreach ($result as $row) {
            $returner[] = (array) $row;
        }

        return $returner;
    }
}