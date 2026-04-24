<?php

namespace APP\plugins\importexport\fullJournalTransfer\filter\import;

use APP\plugins\importexport\native\filter\NativeXmlArticleFilter;
use APP\plugins\importexport\native\filter\NativeXmlSubmissionFilter;
use PKP\db\DAORegistry;
use DOMDocument;
use DOMElement;
use APP\facades\Repo;
use PKP\workflow\WorkflowStageDAO;
use APP\decision\Decision;
use Illuminate\Support\Facades\App;

class NativeXmlExtendedArticleFilter extends NativeXmlArticleFilter {

    /**
     * Files whose source_submission_file_id could not be resolved at import time.
     * Key = old submission_file id from XML, value = old source submission_file id from XML.
     *
     * @var array<string,string>
     */
    private array $pendingSourceSubmissionFileIds = [];

    public function getClassName(): string {
        return static::class;
    }

    public function handleElement($node) {
        $submission = parent::handleElement($node);
        $deployment = $this->getDeployment();

        if ($submission) {
            $deployment->setSubmission($submission);

            for ($childNode = $node->firstChild; $childNode !== null; $childNode = $childNode->nextSibling) {
                if (
                        $childNode instanceof \DOMElement &&
                        $childNode->tagName === 'id' &&
                        $childNode->getAttribute('type') === 'internal'
                ) {
                    $deployment->setSubmissionDBId($childNode->textContent, $submission->getId());
                }
            }
        }

        return $submission;
    }

    public function handleChildElement($node, $submission) {
        if ($node->tagName === 'stage') {
            $this->parseStage($node, $submission);
            return;
        }

        // NativeXmlSubmissionFileFilter in OJS 3.4 imports source_submission_file_id
        // as a raw database id. In full journal transfer XML this value is the old
        // submission_file id, so it must be mapped before the native filter runs.
        if ($node->tagName === 'submission_file') {
            $this->parseSubmissionFile($node);
            return;
        }

        parent::handleChildElement($node, $submission);
    }

    public function parseStage($node, $submission) {
        $stageId = WorkflowStageDAO::getIdFromPath($node->getAttribute('path'));
        $deployment = $this->getDeployment();

        for ($childNode = $node->firstChild; $childNode !== null; $childNode = $childNode->nextSibling) {
            if (is_a($childNode, 'DOMElement')) {
                switch ($childNode->tagName) {
                    case 'participant':
                        $this->parseStageAssignment($childNode, $submission, $stageId);
                        break;
                    case 'decision':
                        $this->parseDecision($childNode, $submission, $stageId);
                        break;
                    case 'review_round':
                        $this->parseReviewRound($childNode, $submission, $stageId);
                        break;
                    case 'queries':
                        $queryNodes = $childNode->getElementsByTagNameNS($deployment->getNamespace(), 'query');
                        for ($i = 0; $i < $queryNodes->length; $i++) {
                            $queryNode = $queryNodes->item($i);
                            $this->parseQuery($queryNode, $submission, $stageId);
                        }
                        break;
                    default:
                        break;
                }
            }
        }
    }

    public function parseStageAssignment($node, $submission, $stageId) {
        $deployment = $this->getDeployment();

        $user = Repo::user()->getByEmail($node->getAttribute('user_email'), true);

        if (is_null($user)) {
            $deployment->addWarning(
                    ASSOC_TYPE_SUBMISSION,
                    $submission->getId(),
                    __(
                            'plugins.importexport.fullJournal.error.userNotFound',
                            ['email' => $node->getAttribute('user_email')]
                    )
            );

            return null;
        }

        $userGroups = Repo::userGroup()
                ->getCollector()
                ->filterByContextIds([(int) $submission->getContextId()])
                ->getMany();

        $userGroups = is_array($userGroups) ? $userGroups : $userGroups->all();

        $userGroupRef = $node->getAttribute('user_group_ref');

        foreach ($userGroups as $userGroup) {
            $groupNames = $userGroup->getName(null) ?? [];

            if (in_array($userGroupRef, $groupNames, true)) {
                return DAORegistry::getDAO('StageAssignmentDAO')->build(
                                $submission->getId(),
                                $userGroup->getId(),
                                $user->getId(),
                                (int) $node->getAttribute('recommend_only'),
                                (int) $node->getAttribute('can_change_metadata')
                        );
            }
        }

        return null;
    }

    public function parseReviewRound($node, $submission, $stageId) {
        $deployment = $this->getDeployment();

        $reviewRoundDAO = DAORegistry::getDAO('ReviewRoundDAO');
        $reviewRound = $reviewRoundDAO->newDataObject();

        $reviewRound = $reviewRoundDAO->build(
                $submission->getId(),
                $stageId,
                $node->getAttribute('round'),
                $node->getAttribute('status')
        );

        $deployment->setReviewRound($reviewRound);

        for ($childNode = $node->firstChild; $childNode !== null; $childNode = $childNode->nextSibling) {
            if (is_a($childNode, 'DOMElement')) {
                switch ($childNode->tagName) {
                    case 'workflow_file':
                        $this->parseArticleFile($childNode);
                        break;
                    case 'review_assignment':
                        $this->parseReviewAssignment($childNode, $reviewRound);
                        break;
                    case 'decision':
                        $this->parseDecision($childNode, $submission, $stageId, $reviewRound);
                        break;
                    default:
                        break;
                }
            }
        }

        return $reviewRound;
    }

    public function parseDecision($node, $submission, $stageId, $reviewRound = null) {
        $deployment = $this->getDeployment();

        $editor = Repo::user()->getByEmail($node->getAttribute('editor_email'), true);

        if (is_null($editor)) {
            $deployment->addWarning(
                    ASSOC_TYPE_SUBMISSION,
                    $submission->getId(),
                    __(
                            'plugins.importexport.fullJournal.error.userNotFound',
                            ['email' => $node->getAttribute('editor_email')]
                    )
            );

            return null;
        }

        $decision = new Decision();
        $decision->setData('submissionId', (int) $submission->getId());
        $decision->setData('stageId', (int) $stageId);
        $decision->setData('editorId', (int) $editor->getId());
        $decision->setData('decision', (int) $node->getAttribute('decision'));
        $decision->setData('dateDecided', $node->getAttribute('date_decided'));

        if ($reviewRound) {
            $decision->setData('reviewRoundId', (int) $reviewRound->getId());
            $decision->setData('round', (int) $reviewRound->getRound());
        } else {
            $round = $node->getAttribute('round');
            if ($round !== '') {
                $decision->setData('round', (int) $round);
            }
        }

        /** @var \PKP\decision\DAO $decisionDao */
        $decisionDao = App::make(\PKP\decision\DAO::class);

        $decisionId = $decisionDao->insert($decision);

        return $decisionId;
    }
    
    public function parseQuery($node, $submission, $stageId) {
        $queryDAO = DAORegistry::getDAO('QueryDAO');
        $noteDAO = DAORegistry::getDAO('NoteDAO');
        $deployment = $this->getDeployment();

        $query = $queryDAO->newDataObject();
        $query->setAssocType(ASSOC_TYPE_SUBMISSION);
        $query->setAssocId($submission->getId());
        $query->setStageId($stageId);
        $query->setIsClosed((bool) $node->getAttribute('closed'));
        $query->setSequence((float) $node->getAttribute('seq'));

        $queryId = $queryDAO->insertObject($query);

        $participantNodes = $node->getElementsByTagNameNS($deployment->getNamespace(), 'participant');
        for ($i = 0; $i < $participantNodes->count(); $i++) {
            $participantNode = $participantNodes->item($i);
            $email = trim((string) $participantNode->textContent);
            $participant = Repo::user()->getByEmail($email, true);

            if ($participant) {
                $queryDAO->insertParticipant($queryId, $participant->getId());
            }
        }

        $noteNodes = $node->getElementsByTagNameNS($deployment->getNamespace(), 'note');
        for ($i = 0; $i < $noteNodes->count(); $i++) {
            $noteNode = $noteNodes->item($i);
            $titleNode = $noteNode->getElementsByTagNameNS($deployment->getNamespace(), 'title')->item(0);
            $contentsNode = $noteNode->getElementsByTagNameNS($deployment->getNamespace(), 'contents')->item(0);
            $email = $noteNode->getAttribute('user_email');
            $noteUser = Repo::user()->getByEmail($email, true);

            $note = $noteDAO->newDataObject();
            if ($noteUser) {
                $note->setUserId($noteUser->getId());
            }
            $note->setDateCreated($noteNode->getAttribute('date_created'));
            $note->setTitle($titleNode ? $titleNode->textContent : '');
            $note->setContents($contentsNode ? $contentsNode->textContent : '');
            $note->setAssocType(ASSOC_TYPE_QUERY);
            $note->setAssocId($queryId);

            $noteDAO->insertObject($note);

            $deployment->setNote($note);
            $noteFilesNodes = $noteNode->getElementsByTagNameNS($deployment->getNamespace(), 'workflow_file');
            for ($fileIndex = 0; $fileIndex < $noteFilesNodes->count(); $fileIndex++) {
                $workflowFileNode = $noteFilesNodes->item($fileIndex);
                $this->parseArticleFile($workflowFileNode);
            }
        }

        return $queryId;
    }

    public function parseReviewAssignment($node, $reviewRound) {
        $deployment = $this->getDeployment();
        $submission = $deployment->getSubmission();

        $reviewAssignmentDAO = DAORegistry::getDAO('ReviewAssignmentDAO');
        $reviewAssignment = $reviewAssignmentDAO->newDataObject();

        $reviewer = Repo::user()->getByEmail($node->getAttribute('reviewer_email'), true);

        if (is_null($reviewer)) {
            $deployment->addWarning(
                    ASSOC_TYPE_SUBMISSION,
                    $submission->getId(),
                    __(
                            'plugins.importexport.fullJournal.error.userNotFound',
                            ['email' => $node->getAttribute('reviewer_email')]
                    )
            );

            return null;
        }

        $reviewAssignment->setSubmissionId($reviewRound->getSubmissionId());
        $reviewAssignment->setReviewerId($reviewer->getId());
        $reviewAssignment->setReviewRoundId($reviewRound->getId());
        $reviewAssignment->setDateAssigned($node->getAttribute('date_assigned'));
        $reviewAssignment->setDateNotified($node->getAttribute('date_notified'));
        $reviewAssignment->setDateDue($node->getAttribute('date_due'));
        $reviewAssignment->setDateResponseDue($node->getAttribute('date_response_due'));
        $reviewAssignment->setLastModified($node->getAttribute('last_modified'));
        $reviewAssignment->setDeclined((int) $node->getAttribute('declined'));
        $reviewAssignment->setCancelled((int) $node->getAttribute('cancelled'));
        $reviewAssignment->setReminderWasAutomatic((int) $node->getAttribute('reminder_was_automatic'));
        $reviewAssignment->setRound($reviewRound->getRound());
        $reviewAssignment->setReviewMethod((int) $node->getAttribute('method'));
        $reviewAssignment->setStageId($reviewRound->getStageId());
        if ($node->getAttribute('unconsidered') == 'true') {
            $reviewAssignment->setStatus(REVIEW_ASSIGNMENT_STATUS_CANCELLED);
        }

        if ($reviewFormId = $node->getAttribute('review_form_id')) {
            $mappedReviewFormId = $deployment->getReviewFormDBId($reviewFormId);
            $reviewAssignment->setReviewFormId($mappedReviewFormId ?: null);
        }

        if ($quality = $node->getAttribute('quality')) {
            $reviewAssignment->setQuality($quality);
        }
        if ($recommendation = $node->getAttribute('recommendation')) {
            $reviewAssignment->setRecommendation($recommendation);
        }
        if ($competingInterests = $node->getAttribute('competing_interests')) {
            $reviewAssignment->setCompetingInterests($competingInterests);
        }
        if ($dateRated = $node->getAttribute('date_rated')) {
            $reviewAssignment->setDateRated($dateRated);
        }
        if ($dateReminded = $node->getAttribute('date_reminded')) {
            $reviewAssignment->setDateReminded($dateReminded);
        }
        if ($dateConfirmed = $node->getAttribute('date_confirmed')) {
            $reviewAssignment->setDateConfirmed($dateConfirmed);
        }
        if ($dateCompleted = $node->getAttribute('date_completed')) {
            $reviewAssignment->setDateCompleted($dateCompleted);
        }
        if ($dateAcknowledged = $node->getAttribute('date_acknowledged')) {
            $reviewAssignment->setDateAcknowledged($dateAcknowledged);
        }

        $reviewAssignmentDAO->insertObject($reviewAssignment);
        $deployment->setReviewAssignment($reviewAssignment);

        for ($childNode = $node->firstChild; $childNode !== null; $childNode = $childNode->nextSibling) {
            if ($childNode instanceof \DOMElement) {
                switch ($childNode->tagName) {
                    case 'review_files':
                        $reviewFileIds = preg_split('/:/', $childNode->textContent);
                        $reviewFilesDAO = DAORegistry::getDAO('ReviewFilesDAO');
                        foreach ($reviewFileIds as $reviewFileId) {
                            $newSubmissionFileId = $deployment->getSubmissionFileDBId($reviewFileId);
                            if (!is_null($newSubmissionFileId)) {
                                $reviewFilesDAO->grant($reviewAssignment->getId(), $newSubmissionFileId);
                            }
                        }
                        break;
                    case 'workflow_file':
                        $this->parseArticleFile($childNode);
                        break;
                    case 'response':
                        $this->parseResponse($childNode, $reviewAssignment);
                        break;
                    case 'submission_comment':
                        $this->parseSubmissionComment($childNode, $reviewAssignment);
                        break;
                }
            }
        }

        return $reviewAssignment;
    }

    public function parseResponse($node, $reviewAssignment) {
        $deployment = $this->getDeployment();

        $newReviewFormElementId = $deployment->getReviewFormElementDBId($node->getAttribute('form_element_id'));

        $reviewFormResponseDAO = DAORegistry::getDAO('ReviewFormResponseDAO');
        $reviewFormResponse = $reviewFormResponseDAO->newDataObject();
        $reviewFormResponse->setReviewId($reviewAssignment->getId());
        $reviewFormResponse->setResponseType($node->getAttribute('type'));
        $reviewFormResponse->setReviewFormElementId($newReviewFormElementId);

        if ($node->getAttribute('type') === 'object') {
            $reviewFormResponse->setValue(preg_split('/:/', $node->textContent));
        } else {
            $reviewFormResponse->setValue($node->textContent);
        }

        $reviewFormResponseDAO->insertObject($reviewFormResponse);
    }

    public function parseSubmissionComment($node, $reviewAssignment) {
        $deployment = $this->getDeployment();

        $commentAuthor = Repo::user()->getByEmail($node->getAttribute('author'), true);

        if (is_null($commentAuthor)) {
            $deployment->addWarning(
                    ASSOC_TYPE_SUBMISSION,
                    $reviewAssignment->getSubmissionId(),
                    __(
                            'plugins.importexport.fullJournal.error.userNotFound',
                            ['email' => $node->getAttribute('author')]
                    )
            );

            return null;
        }

        $submissionCommentDAO = DAORegistry::getDAO('SubmissionCommentDAO');
        $comment = $submissionCommentDAO->newDataObject();
        $comment->setCommentType($node->getAttribute('comment_type'));
        $comment->setRoleId($node->getAttribute('role'));
        $comment->setSubmissionId($reviewAssignment->getSubmissionId());
        $comment->setAssocId($reviewAssignment->getId());
        $comment->setAuthorId($commentAuthor->getId());
        $comment->setDatePosted($node->getAttribute('date_posted'));
        $comment->setDateModified($node->getAttribute('date_modified'));
        $comment->setViewable($node->getAttribute('viewable'));

        for ($childNode = $node->firstChild; $childNode !== null; $childNode = $childNode->nextSibling) {
            if ($childNode instanceof \DOMElement) {
                switch ($childNode->tagName) {
                    case 'title':
                        $comment->setCommentTitle($childNode->textContent);
                        break;
                    case 'comments':
                        $comment->setComments($childNode->textContent);
                        break;
                }
            }
        }

        return $submissionCommentDAO->insertObject($comment);
    }

    /**
     * Import a native <submission_file> node without modifying the core native plugin.
     *
     * The native OJS 3.4 submission file import filter expects source_submission_file_id
     * to be a real DB id. During full journal transfer it is still the OLD XML id.
     * Therefore we clone the node and either replace the attribute with the mapped id,
     * or temporarily remove it and resolve it after the referenced file is imported.
     */
    public function parseSubmissionFile(DOMElement $node) {
        $deployment = $this->getDeployment();

        $oldSubmissionFileId = trim((string) $node->getAttribute('id'));
        $oldSourceSubmissionFileId = trim((string) $node->getAttribute('source_submission_file_id'));

        $submissionFileDoc = new DOMDocument('1.0', 'utf-8');
        $submissionFileNode = $submissionFileDoc->importNode($node, true);
        $submissionFileDoc->appendChild($submissionFileNode);

        if ($oldSourceSubmissionFileId !== '') {
            $mappedSourceSubmissionFileId = $deployment->getSubmissionFileDBId($oldSourceSubmissionFileId);

            if ($mappedSourceSubmissionFileId) {
                $submissionFileNode->setAttribute(
                    'source_submission_file_id',
                    (string) $mappedSourceSubmissionFileId
                );
            } else {
                // Prevent FK violation during insert. The relation is restored later
                // once both old ids are available in deployment's submission file map.
                $submissionFileNode->removeAttribute('source_submission_file_id');

                if ($oldSubmissionFileId !== '') {
                    $this->pendingSourceSubmissionFileIds[$oldSubmissionFileId] = $oldSourceSubmissionFileId;
                }
            }
        }

        $importFilter = $this->getImportFilter('submission_file');
        $importFilter->setDeployment($deployment);

        $submissionFile = $importFilter->execute($submissionFileDoc);

        $this->resolvePendingSourceSubmissionFileIds();

        return $submissionFile;
    }

    /**
     * Resolve source_submission_file_id values that could not be mapped before insert.
     */
    private function resolvePendingSourceSubmissionFileIds(): void {
        if (empty($this->pendingSourceSubmissionFileIds)) {
            return;
        }

        $deployment = $this->getDeployment();

        foreach ($this->pendingSourceSubmissionFileIds as $oldSubmissionFileId => $oldSourceSubmissionFileId) {
            $newSubmissionFileId = $deployment->getSubmissionFileDBId($oldSubmissionFileId);
            $newSourceSubmissionFileId = $deployment->getSubmissionFileDBId($oldSourceSubmissionFileId);

            if (!$newSubmissionFileId || !$newSourceSubmissionFileId) {
                continue;
            }

            $submissionFile = Repo::submissionFile()->get((int) $newSubmissionFileId);
            if (!$submissionFile) {
                continue;
            }

            Repo::submissionFile()->edit(
                $submissionFile,
                ['sourceSubmissionFileId' => (int) $newSourceSubmissionFileId]
            );

            unset($this->pendingSourceSubmissionFileIds[$oldSubmissionFileId]);
        }
    }

    public function parseArticleFile($node) {
        $filterDAO = DAORegistry::getDAO('FilterDAO');
        $importFilters = $filterDAO->getObjectsByGroup('native-xml=>workflow-file');
        $importFilter = array_shift($importFilters);
        assert(isset($importFilter));

        $importFilter->setDeployment($this->getDeployment());
        $reviewRoundFileDoc = new DOMDocument('1.0', 'utf-8');
        $reviewRoundFileDoc->appendChild($reviewRoundFileDoc->importNode($node, true));
        return $importFilter->execute($reviewRoundFileDoc);
    }

    public function getImportFilter($elementName) {
        if ($elementName === 'publication') {
            $filterDao = DAORegistry::getDAO('FilterDAO');
            $importFilters = $filterDao->getObjectsByGroup('native-xml=>extended-publication');
            $importFilters = is_array($importFilters) ? $importFilters : $importFilters->toArray();

            if (count($importFilters) !== 1) {
                throw new \Exception(
                                sprintf(
                                        'Expected exactly 1 filter for group "%s", got %d.',
                                        'native-xml=>extended-publication',
                                        count($importFilters)
                                )
                        );
            }

            $importFilter = array_shift($importFilters);
            $importFilter->setDeployment($this->getDeployment());

            return $importFilter;
        }

        return parent::getImportFilter($elementName);
    }
}
