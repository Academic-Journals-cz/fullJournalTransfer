<?php

/**
 * @file plugins/importexport/fullJournalTransfer/filter/export/WorkflowFileNativeXmlFilter.php
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2000-2020 John Willinsky
 * Copyright (c) 2014-2024 Lepidus Tecnologia
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class WorkflowFileNativeXmlFilter
 *
 * @brief Converts a workflow file (review file, reviewer attachment, discussion
 *  file) to a native XML workflow_file element. The element is a submission_file
 *  with the assoc_type of the file, which tells the import to which review round,
 *  review assignment or note the file belongs.
 */

namespace APP\plugins\importexport\fullJournalTransfer\filter\export;

use DOMDocument;
use DOMElement;
use PKP\plugins\importexport\native\filter\SubmissionFileNativeXmlFilter;
use PKP\submissionFile\SubmissionFile;

class WorkflowFileNativeXmlFilter extends SubmissionFileNativeXmlFilter
{
    public function __construct($filterGroup)
    {
        parent::__construct($filterGroup);
    }

    public function getClassName(): string
    {
        return static::class;
    }

    public function getSubmissionFileElementName()
    {
        return 'workflow_file';
    }

    public function createSubmissionFileNode(DOMDocument $doc, SubmissionFile $submissionFile): ?DOMElement
    {
        $submissionFileNode = parent::createSubmissionFileNode($doc, $submissionFile);

        if ($submissionFileNode && $submissionFile->getData('assocType')) {
            $submissionFileNode->setAttribute('assoc_type', (string) (int) $submissionFile->getData('assocType'));
        }

        return $submissionFileNode;
    }
}
