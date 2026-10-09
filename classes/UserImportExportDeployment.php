<?php

/**
 * @file plugins/importexport/fullJournalTransfer/classes/UserImportExportDeployment.php
 *
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class UserImportExportDeployment
 *
 * @brief Deployment for the users filters (which expect a PKPUserImportExportDeployment)
 *  that keeps a reference to the journal deployment, so that the user filter can
 *  record the username mapping and the import statistics.
 */

namespace APP\plugins\importexport\fullJournalTransfer\classes;

use APP\plugins\importexport\fullJournalTransfer\FullJournalImportExportDeployment;
use PKP\plugins\importexport\users\PKPUserImportExportDeployment;

class UserImportExportDeployment extends PKPUserImportExportDeployment
{
    public function __construct($context, $user, private ?FullJournalImportExportDeployment $journalDeployment = null)
    {
        parent::__construct($context, $user);
    }

    public function getJournalDeployment(): ?FullJournalImportExportDeployment
    {
        return $this->journalDeployment;
    }
}
