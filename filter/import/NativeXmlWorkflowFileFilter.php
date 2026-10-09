<?php

/**
 * @file plugins/importexport/fullJournalTransfer/filter/import/NativeXmlWorkflowFileFilter.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Copyright (c) 2014-2024 Lepidus Tecnologia
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NativeXmlWorkflowFileFilter
 *
 * @brief Imports a workflow file (review file, author revision, reviewer
 *  attachment, discussion file). The file is attached to the review round,
 *  review assignment or note that is currently being imported (see the
 *  deployment). The file is inserted through the DAO: the repository's add()
 *  would send notifications and write "file uploaded" log entries.
 */

namespace APP\plugins\importexport\fullJournalTransfer\filter\import;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\importexport\fullJournalTransfer\classes\FullJournalFilterTrait;
use APP\plugins\importexport\native\filter\NativeXmlArticleFileFilter;
use DOMElement;
use PKP\core\Core;
use PKP\db\DAORegistry;
use PKP\submissionFile\SubmissionFile;

class NativeXmlWorkflowFileFilter extends NativeXmlArticleFileFilter
{
    use FullJournalFilterTrait;

    /** @var array<int, array<string, \PKP\submission\Genre>> */
    protected array $genresByContextId = [];

    public function getClassName(): string
    {
        return static::class;
    }

    public function getPluralElementName()
    {
        return 'workflow_files';
    }

    public function getSingularElementName()
    {
        return 'workflow_file';
    }

    /**
     * @param DOMElement $node
     *
     * @return ?SubmissionFile
     */
    public function handleElement($node)
    {
        $deployment = $this->getFullJournalDeployment();
        $submission = $deployment->getSubmission();
        $context = $deployment->getContext();

        $stageName = $node->getAttribute('stage');
        $stageNameIdMapping = $deployment->getStageNameStageIdMapping();
        if (!isset($stageNameIdMapping[$stageName])) {
            $deployment->addWarning(Application::ASSOC_TYPE_SUBMISSION, $submission->getId(), __('plugins.importexport.native.error.submissionFileInvalidFileStage', ['id' => $node->getAttribute('id')]));
            return null;
        }
        $fileStage = (int) $stageNameIdMapping[$stageName];

        // Genre
        $genreId = null;
        if ($genreName = $node->getAttribute('genre')) {
            $genre = $this->findGenre((int) $context->getId(), $genreName);
            if (!$genre) {
                $deployment->addError(Application::ASSOC_TYPE_SUBMISSION_FILE, $submission->getId(), __('plugins.importexport.common.error.unknownGenre', ['param' => $genreName]));
                return null;
            }
            $genreId = (int) $genre->getId();
        }

        // Uploader
        $uploaderUsername = $node->getAttribute('uploader');
        $uploaderUsername = $deployment->getMappedUsername($uploaderUsername) ?? $uploaderUsername;
        $uploader = $uploaderUsername ? Repo::user()->getByUsername($uploaderUsername, true) : null;
        if (!$uploader) {
            $uploader = $deployment->getUser();
        }

        $submissionFile = Repo::submissionFile()->dao->newDataObject();
        $submissionFile->setData('submissionId', (int) $submission->getId());
        $submissionFile->setData('locale', $submission->getData('locale'));
        $submissionFile->setData('fileStage', $fileStage);
        $submissionFile->setData('createdAt', Core::getCurrentDate());
        $submissionFile->setData('updatedAt', Core::getCurrentDate());
        $submissionFile->setData('dateCreated', $node->getAttribute('date_created') ?: null);
        $submissionFile->setData('language', $node->getAttribute('language') ?: null);
        $submissionFile->setData('uploaderUserId', $uploader ? (int) $uploader->getId() : null);
        $submissionFile->setData('viewable', $node->getAttribute('viewable') === 'true');

        foreach ([
            'caption' => 'caption',
            'copyright_owner' => 'copyrightOwner',
            'credit' => 'credit',
            'sales_type' => 'salesType',
            'terms' => 'terms',
        ] as $attribute => $prop) {
            if (($value = $node->getAttribute($attribute)) !== '') {
                $submissionFile->setData($prop, $value);
            }
        }
        if (strlen($directSalesPrice = $node->getAttribute('direct_sales_price'))) {
            $submissionFile->setData('directSalesPrice', $directSalesPrice);
        }
        if ($genreId) {
            $submissionFile->setData('genreId', $genreId);
        }
        if (($oldSourceSubmissionFileId = $node->getAttribute('source_submission_file_id')) !== '') {
            $submissionFile->setData('sourceSubmissionFileId', $deployment->getSubmissionFileDBId($oldSourceSubmissionFileId));
        }

        // Association with the review round / review assignment / discussion note
        $assocType = (int) $node->getAttribute('assoc_type');
        if (in_array($fileStage, [
            SubmissionFile::SUBMISSION_FILE_REVIEW_FILE,
            SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION,
            SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_FILE,
            SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_REVISION,
        ])) {
            $reviewRound = $deployment->getReviewRound();
            if (!$reviewRound) {
                $deployment->addWarning(Application::ASSOC_TYPE_SUBMISSION, $submission->getId(), __('plugins.importexport.fullJournal.warning.fileWithoutParent', ['id' => $node->getAttribute('id')]));
                return null;
            }
            $submissionFile->setData('assocType', Application::ASSOC_TYPE_REVIEW_ROUND);
            $submissionFile->setData('assocId', (int) $reviewRound->getId());
        } elseif ($fileStage === SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT) {
            $reviewAssignment = $deployment->getReviewAssignment();
            if (!$reviewAssignment) {
                $deployment->addWarning(Application::ASSOC_TYPE_SUBMISSION, $submission->getId(), __('plugins.importexport.fullJournal.warning.fileWithoutParent', ['id' => $node->getAttribute('id')]));
                return null;
            }
            $submissionFile->setData('assocType', Application::ASSOC_TYPE_REVIEW_ASSIGNMENT);
            $submissionFile->setData('assocId', (int) $reviewAssignment->getId());
        } elseif ($fileStage === SubmissionFile::SUBMISSION_FILE_QUERY) {
            $note = $deployment->getNote();
            if (!$note) {
                $deployment->addWarning(Application::ASSOC_TYPE_SUBMISSION, $submission->getId(), __('plugins.importexport.fullJournal.warning.fileWithoutParent', ['id' => $node->getAttribute('id')]));
                return null;
            }
            $submissionFile->setData('assocType', Application::ASSOC_TYPE_NOTE);
            $submissionFile->setData('assocId', (int) $note->id);
        } elseif ($assocType === Application::ASSOC_TYPE_SUBMISSION_FILE) {
            // dependent file; resolved below from submission_file_ref
        }

        // Child nodes
        $fileIds = [];
        $currentFileId = null;
        foreach ($this->childElements($node) as $childNode) {
            switch ($childNode->tagName) {
                case 'id':
                    $this->parseIdentifier($childNode, $submissionFile);
                    break;
                case 'creator':
                case 'description':
                case 'name':
                case 'publisher':
                case 'source':
                case 'sponsor':
                case 'subject':
                    [$locale, $value] = $this->parseLocalizedContent($childNode);
                    $submissionFile->setData($childNode->tagName, $value, $locale ?: $submission->getData('locale'));
                    break;
                case 'submission_file_ref':
                    if ($fileStage === SubmissionFile::SUBMISSION_FILE_DEPENDENT) {
                        $newAssocId = $deployment->getSubmissionFileDBId($childNode->getAttribute('id'));
                        if ($newAssocId) {
                            $submissionFile->setData('assocType', Application::ASSOC_TYPE_SUBMISSION_FILE);
                            $submissionFile->setData('assocId', $newAssocId);
                        }
                    }
                    break;
                case 'file':
                    $fileId = $deployment->getFileDBId($childNode->getAttribute('id')) ?: $this->handleRevisionElement($childNode);
                    if (!$fileId) {
                        break;
                    }
                    if ($childNode->getAttribute('id') == $node->getAttribute('file_id')) {
                        $currentFileId = $fileId;
                    } else {
                        $fileIds[] = $fileId;
                    }
                    break;
                default:
                    $deployment->addWarning(Application::ASSOC_TYPE_SUBMISSION, $submission->getId(), __('plugins.importexport.common.error.unknownElement', ['param' => $childNode->tagName]));
            }
        }

        if (!$currentFileId) {
            $deployment->addWarning(Application::ASSOC_TYPE_SUBMISSION, $submission->getId(), __('plugins.importexport.native.error.submissionFileWithoutRevision', ['id' => $node->getAttribute('id')]));
            return null;
        }

        // The current revision must be the last one
        $fileIds[] = $currentFileId;

        $submissionFileDao = Repo::submissionFile()->dao;

        // First revision: insert (this also links the file to the review round)
        $submissionFile->setData('fileId', array_shift($fileIds));
        $submissionFileId = (int) $submissionFileDao->insert($submissionFile);
        $submissionFile = Repo::submissionFile()->get($submissionFileId);

        // Further revisions: update the same submission file
        foreach ($fileIds as $fileId) {
            $submissionFile->setData('fileId', $fileId);
            $submissionFileDao->update($submissionFile);
        }
        $submissionFile = Repo::submissionFile()->get($submissionFileId);

        $deployment->setSubmissionFileDBId($node->getAttribute('id'), $submissionFileId);
        $deployment->incrementCounter('workflow files');

        return $submissionFile;
    }

    protected function findGenre(int $contextId, string $genreName): ?\PKP\submission\Genre
    {
        if (!isset($this->genresByContextId[$contextId])) {
            $genreDao = DAORegistry::getDAO('GenreDAO'); /** @var \PKP\submission\GenreDAO $genreDao */
            $genres = $genreDao->getByContextId($contextId);
            $this->genresByContextId[$contextId] = [];
            while ($genre = $genres->next()) {
                foreach ((array) $genre->getName(null) as $name) {
                    $this->genresByContextId[$contextId][$name] = $genre;
                }
            }
        }
        return $this->genresByContextId[$contextId][$genreName] ?? null;
    }
}
