<?php

/**
 * @file plugins/importexport/fullJournalTransfer/FullJournalImportExportDeployment.php
 *
 * Copyright (c) 2014-2024 Lepidus Tecnologia
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class FullJournalImportExportDeployment
 *
 * @brief Deployment for the full journal transfer. Keeps the state shared between
 *  the filters (current review round, note, ...) and the maps between the IDs found
 *  in the XML (old installation) and the IDs created during the import.
 */

namespace APP\plugins\importexport\fullJournalTransfer;

use APP\plugins\importexport\native\NativeImportExportDeployment;
use PKP\note\Note;
use PKP\reviewForm\ReviewForm;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\submission\reviewRound\ReviewRound;

class FullJournalImportExportDeployment extends NativeImportExportDeployment
{
    /** Name of the sub directory of the archive that holds the metrics CSV files */
    public const METRICS_DIR = 'metrics';

    /** Name of the sub directory of the archive that holds the public files of the journal */
    public const PUBLIC_FILES_DIR = 'public';

    /** Directory inside the archive holding files_dir/contexts/<id> (publisher library) */
    public const CONTEXT_FILES_DIR = 'contexts';

    private ?ReviewForm $reviewForm = null;
    private ?ReviewRound $reviewRound = null;
    private ?ReviewAssignment $reviewAssignment = null;
    private ?Note $note = null;

    /** @var array<string,array<int|string,int>> old ID => new ID maps, keyed by entity name */
    private array $idMaps = [];

    /** @var array<string,int> Statistics collected during the import (displayed at the end) */
    private array $counters = [];

    /** @var array<string,mixed> CLI options of the import (no-metrics, no-activity-log, ...) */
    private array $importOptions = [];

    /** @var array<string,string> username in the XML => username on this site (users are matched by e-mail) */
    private array $usernameMap = [];

    public function __construct($context, $user = null)
    {
        parent::__construct($context, $user);
        foreach ($this->getIdMapNames() as $name) {
            $this->idMaps[$name] = [];
        }
    }

    public function getSchemaFilename()
    {
        return 'fullJournal.xsd';
    }

    public function getSubmissionNodeName()
    {
        return 'extended_article';
    }

    public function getSubmissionsNodeName()
    {
        return 'extended_articles';
    }

    //
    // Current objects shared between filters
    //
    public function setReviewRound(?ReviewRound $reviewRound): void
    {
        $this->reviewRound = $reviewRound;
    }

    public function getReviewRound(): ?ReviewRound
    {
        return $this->reviewRound;
    }

    public function setReviewForm(?ReviewForm $reviewForm): void
    {
        $this->reviewForm = $reviewForm;
    }

    public function getReviewForm(): ?ReviewForm
    {
        return $this->reviewForm;
    }

    public function setReviewAssignment(?ReviewAssignment $reviewAssignment): void
    {
        $this->reviewAssignment = $reviewAssignment;
    }

    public function getReviewAssignment(): ?ReviewAssignment
    {
        return $this->reviewAssignment;
    }

    public function setNote(?Note $note): void
    {
        $this->note = $note;
    }

    public function getNote(): ?Note
    {
        return $this->note;
    }

    //
    // Generic old ID => new ID maps
    //
    public function getIdMapNames(): array
    {
        return [
            'navigationMenuItem',
            'reviewForm',
            'reviewFormElement',
            'representation', // publication galleys
            'issue',
            'issueGalley',
            'submission',
            'reviewAssignment',
            'reviewRound',
        ];
    }

    public function setMappedId(string $entity, int|string $oldId, int $newId): void
    {
        $this->idMaps[$entity][(string) $oldId] = $newId;
    }

    public function getMappedId(string $entity, int|string|null $oldId): ?int
    {
        if ($oldId === null || $oldId === '') {
            return null;
        }
        return $this->idMaps[$entity][(string) $oldId] ?? null;
    }

    public function getIdMap(string $entity): array
    {
        return $this->idMaps[$entity] ?? [];
    }

    //
    // Convenience wrappers kept for readability of the filters
    //
    public function setNavigationMenuItemDBId($oldId, int $newId): void
    {
        $this->setMappedId('navigationMenuItem', $oldId, $newId);
    }

    public function getNavigationMenuItemDBId($oldId): ?int
    {
        return $this->getMappedId('navigationMenuItem', $oldId);
    }

    public function setReviewFormDBId($oldId, int $newId): void
    {
        $this->setMappedId('reviewForm', $oldId, $newId);
    }

    public function getReviewFormDBId($oldId): ?int
    {
        return $this->getMappedId('reviewForm', $oldId);
    }

    public function setReviewFormElementDBId($oldId, int $newId): void
    {
        $this->setMappedId('reviewFormElement', $oldId, $newId);
    }

    public function getReviewFormElementDBId($oldId): ?int
    {
        return $this->getMappedId('reviewFormElement', $oldId);
    }

    public function setRepresentationDBId($oldId, int $newId): void
    {
        $this->setMappedId('representation', $oldId, $newId);
    }

    public function getRepresentationDBId($oldId): ?int
    {
        return $this->getMappedId('representation', $oldId);
    }

    public function setIssueDBId($oldId, int $newId): void
    {
        $this->setMappedId('issue', $oldId, $newId);
    }

    public function getIssueDBId($oldId): ?int
    {
        return $this->getMappedId('issue', $oldId);
    }

    public function setIssueGalleyDBId($oldId, int $newId): void
    {
        $this->setMappedId('issueGalley', $oldId, $newId);
    }

    public function getIssueGalleyDBId($oldId): ?int
    {
        return $this->getMappedId('issueGalley', $oldId);
    }

    public function setSubmissionDBId($oldId, int $newId): void
    {
        $this->setMappedId('submission', $oldId, $newId);
    }

    public function getSubmissionDBId($oldId): ?int
    {
        return $this->getMappedId('submission', $oldId);
    }

    public function getSubmissionDBIds(): array
    {
        return $this->getIdMap('submission');
    }

    public function setReviewAssignmentDBId($oldId, int $newId): void
    {
        $this->setMappedId('reviewAssignment', $oldId, $newId);
    }

    public function getReviewAssignmentDBId($oldId): ?int
    {
        return $this->getMappedId('reviewAssignment', $oldId);
    }

    public function setReviewRoundDBId($oldId, int $newId): void
    {
        $this->setMappedId('reviewRound', $oldId, $newId);
    }

    public function getReviewRoundDBId($oldId): ?int
    {
        return $this->getMappedId('reviewRound', $oldId);
    }

    //
    // Usernames (the native file elements reference the uploader by username)
    //
    public function setMappedUsername(string $oldUsername, string $newUsername): void
    {
        $this->usernameMap[mb_strtolower($oldUsername)] = $newUsername;
    }

    public function getMappedUsername(?string $oldUsername): ?string
    {
        if ($oldUsername === null || $oldUsername === '') {
            return null;
        }
        return $this->usernameMap[mb_strtolower($oldUsername)] ?? null;
    }

    //
    // Import options
    //
    public function setImportOptions(array $options): void
    {
        $this->importOptions = $options;
    }

    public function getImportOptions(): array
    {
        return $this->importOptions;
    }

    public function hasImportOption(string $name): bool
    {
        return !empty($this->importOptions[$name]);
    }

    //
    // Counters (import statistics)
    //
    public function incrementCounter(string $name, int $by = 1): void
    {
        $this->counters[$name] = ($this->counters[$name] ?? 0) + $by;
    }

    public function getCounters(): array
    {
        return $this->counters;
    }

    /**
     * Check whether a submission can be exported with the native filters.
     * Incomplete submissions (submission wizard not finished) and submissions
     * without a title cannot be represented in the XML and are skipped.
     *
     * @return ?string null if the submission is valid, otherwise the reason
     */
    public function getSubmissionValidationError($submission): ?string
    {
        if ($submission->getData('submissionProgress')) {
            return 'incomplete submission (submission wizard not finished)';
        }

        $publications = $submission->getData('publications');
        if (empty($publications) || !count($publications)) {
            return 'no publication (version) found';
        }

        foreach ($publications as $publication) {
            if (empty(array_filter((array) $publication->getData('title'), 'strlen'))) {
                return 'publication ' . $publication->getId() . ' has no title';
            }
        }
        // Irregular contributor data (missing given name or user group, affiliations
        // without a name) is repaired by ExtendedPublicationNativeXmlFilter.

        return null;
    }

    /**
     * @deprecated use getSubmissionValidationError()
     */
    public function validateSubmission($submission): bool
    {
        return $this->getSubmissionValidationError($submission) === null;
    }

    /**
     * Get the ID of the issue a submission is exported with: the issue of its
     * current publication, provided the issue (still) exists in the journal.
     * Submissions without such an issue are exported on the journal level.
     */
    public function getSubmissionExportIssueId($submission): ?int
    {
        $currentPublication = $submission->getCurrentPublication();
        $issueId = $currentPublication ? (int) $currentPublication->getData('issueId') : 0;
        if (!$issueId) {
            return null;
        }
        static $existingIssues = [];
        if (!array_key_exists($issueId, $existingIssues)) {
            $issue = \APP\facades\Repo::issue()->get($issueId);
            $existingIssues[$issueId] = $issue && (int) $issue->getJournalId() === (int) $this->getContext()->getId();
        }
        return $existingIssues[$issueId] ? $issueId : null;
    }
}
