<?php

namespace APP\plugins\importexport\fullJournalTransfer\filter\export;

use PKP\db\DAORegistry;
use APP\core\Services;
use APP\plugins\importexport\native\filter\ArticleNativeXmlFilter;
use APP\facades\Repo;

class ExtendedArticleNativeXmlFilter extends ArticleNativeXmlFilter {

    public function __construct($filterGroup) {
        parent::__construct($filterGroup);
    }

    public function getClassName(): string {
        return static::class;
    }

    public function createSubmissionNode($doc, $submission) {
        $deployment = $this->getDeployment();
        $submissionNode = parent::createSubmissionNode($doc, $submission);

        $this->addStages($doc, $submissionNode, $submission);

        return $submissionNode;
    }

    public function addStages($doc, $submissionNode, $submission) {
        $deployment = $this->getDeployment();
        foreach ($this->getStageMapping() as $stageId => $stagePath) {
            $submissionNode->appendChild($stageNode = $doc->createElementNS($deployment->getNamespace(), 'stage'));
            $stageNode->setAttribute('path', $stagePath);

            $this->addStageChildNodes($doc, $stageNode, $submission, $stageId);

            if ($stageId == $submission->getStageId()) {
                break;
            }
        }
    }

    public function addStageChildNodes($doc, $stageNode, $submission, $stageId) {
        $this->addParticipants($doc, $stageNode, $submission, $stageId);

        if ($stageId === WORKFLOW_STAGE_ID_EXTERNAL_REVIEW) {
            $this->addReviewRounds($doc, $stageNode, $submission, $stageId);
        } else {
            $this->addEditorDecisions($doc, $stageNode, $submission, $stageId);
        }

        $this->addQueries($doc, $stageNode, $submission, $stageId);
    }

    public function addParticipants($doc, $stageNode, $submission, $stageId) {
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $contextId = (int) $context->getId();

        /** @var \StageAssignmentDAO $stageAssignmentDAO */
        $stageAssignmentDAO = DAORegistry::getDAO('StageAssignmentDAO');

        $stageAssignments = $stageAssignmentDAO->getBySubmissionAndStageId(
                (int) $submission->getId(),
                (int) $stageId
        );

        while ($stageAssignment = $stageAssignments->next()) {

            $user = Repo::user()->get((int) $stageAssignment->getUserId());
            if (!$user) {
                continue;
            }

            $userGroup = Repo::userGroup()->get((int) $stageAssignment->getUserGroupId());
            if (!$userGroup || (int) $userGroup->getContextId() !== $contextId) {
                continue;
            }

            $participantNode = $doc->createElementNS(
                    $deployment->getNamespace(),
                    'participant'
            );

            $participantNode->setAttribute(
                    'user_email',
                    (string) $user->getEmail()
            );

            $participantNode->setAttribute(
                    'user_group_ref',
                    (string) $userGroup->getName($context->getPrimaryLocale())
            );

            $participantNode->setAttribute(
                    'recommend_only',
                    (string) (int) $stageAssignment->getRecommendOnly()
            );

            $participantNode->setAttribute(
                    'can_change_metadata',
                    (string) (int) $stageAssignment->getCanChangeMetadata()
            );

            $stageNode->appendChild($participantNode);
        }
    }

    public function addEditorDecisions($doc, $parentNode, $submission, $stageId, $reviewRound = null) {
        $deployment = $this->getDeployment();

        $collector = Repo::decision()
                ->getCollector()
                ->filterBySubmissionIds([(int) $submission->getId()]);

        if (method_exists($collector, 'filterByStageIds')) {
            $collector->filterByStageIds([(int) $stageId]);
        }

        if ($reviewRound && method_exists($collector, 'filterByReviewRoundIds')) {
            $collector->filterByReviewRoundIds([(int) $reviewRound->getId()]);
        }

        $editorDecisions = $collector->getMany();

        foreach ($editorDecisions as $editorDecision) {
            $editorId = (int) ($editorDecision->getData('editorId') ?? 0);
            if (!$editorId) {
                continue;
            }

            $editor = Repo::user()->get($editorId);
            if (!$editor) {
                continue;
            }

            $decisionNode = $doc->createElementNS(
                    $deployment->getNamespace(),
                    'decision'
            );

            $decisionNode->setAttribute(
                    'round',
                    (string) ($editorDecision->getData('round') ?? 0)
            );

            $decisionNode->setAttribute(
                    'review_round_id',
                    (string) ($editorDecision->getData('reviewRoundId') ?? 0)
            );

            $decisionNode->setAttribute(
                    'decision',
                    (string) ($editorDecision->getData('decision') ?? '')
            );

            $decisionNode->setAttribute(
                    'editor_email',
                    (string) $editor->getEmail()
            );

            $decisionNode->setAttribute(
                    'date_decided',
                    (string) ($editorDecision->getData('dateDecided') ?? '')
            );

            $parentNode->appendChild($decisionNode);
        }
    }

    public function addQueries($doc, $parentNode, $submission, $stageId) {
        $deployment = $this->getDeployment();
        $queryDAO = DAORegistry::getDAO('QueryDAO');

        $queries = $queryDAO->getByAssoc(ASSOC_TYPE_SUBMISSION, $submission->getId(), $stageId);
        $queriesNode = $doc->createElementNS($deployment->getNamespace(), 'queries');
        while ($query = $queries->next()) {
            $participantIds = $queryDAO->getParticipantIds($query->getId());

            if (empty($participantIds)) {
                continue;
            }

            $queryNode = $doc->createElementNS($deployment->getNamespace(), 'query');
            $queryNode->setAttribute('seq', $query->getData('sequence'));
            $queryNode->setAttribute('closed', (int) $query->getData('closed'));

            $queryNode->appendChild($this->createQueryParticipantsNode($doc, $deployment, $participantIds));
            $queryNode->appendChild($this->createQueryRepliesNode($doc, $deployment, $submission, $query));

            $queriesNode->appendChild($queryNode);
        }

        if ($queriesNode->hasChildNodes()) {
            $parentNode->appendChild($queriesNode);
        }
    }

    private function createQueryParticipantsNode($doc, $deployment, $participantIds) {
        $participantsNode = $doc->createElementNS(
                $deployment->getNamespace(),
                'participants'
        );

        foreach ($participantIds as $participantId) {
            $participant = \APP\facades\Repo::user()->get((int) $participantId);
            if (!$participant) {
                continue;
            }

            $participantNode = $doc->createElementNS(
                    $deployment->getNamespace(),
                    'participant',
                    htmlspecialchars((string) $participant->getEmail(), ENT_COMPAT, 'UTF-8')
            );

            $participantsNode->appendChild($participantNode);
        }

        return $participantsNode;
    }

    private function createQueryRepliesNode($doc, $deployment, $submission, $query) {
        $repliesNode = $doc->createElementNS($deployment->getNamespace(), 'replies');
        $replies = $query->getReplies();

        foreach ($replies AS $note) {
            $user = $note->getUser();
            if (!$user) {
                continue;
            }
            $noteNode = $doc->createElementNS($deployment->getNamespace(), 'note');
            $noteNode->setAttribute('user_email', htmlspecialchars($user->getEmail(), ENT_COMPAT, 'UTF-8'));
            $noteNode->setAttribute('date_modified', $note->getDateModified());
            $noteNode->setAttribute('date_created', $note->getDateCreated());

            $titleNode = $doc->createElementNS(
                    $deployment->getNamespace(),
                    'title',
                    htmlspecialchars($note->getTitle(), ENT_COMPAT, 'UTF-8')
            );
            $contentsNode = $doc->createElementNS(
                    $deployment->getNamespace(),
                    'contents',
                    htmlspecialchars($note->getContents(), ENT_COMPAT, 'UTF-8')
            );

            $noteNode->appendChild($titleNode);
            $noteNode->appendChild($contentsNode);
            $this->addNoteFiles($doc, $noteNode, $submission, $note);

            $repliesNode->appendChild($noteNode);
        }

        return $repliesNode;
    }

    private function addNoteFiles($doc, $noteNode, $submission, $note) {
        $deployment = $this->getDeployment();

        $noteFiles = \APP\facades\Repo::submissionFile()
                ->getCollector()
                ->filterBySubmissionIds([(int) $submission->getId()])
                ->filterByAssoc(ASSOC_TYPE_NOTE, [(int) $note->getId()])
                ->filterByFileStages([SUBMISSION_FILE_QUERY])
                ->getMany();

        $filterDao = \PKP\db\DAORegistry::getDAO('FilterDAO');
        $nativeExportFilters = $filterDao->getObjectsByGroup('workflow-file=>native-xml');
        $nativeExportFilters = is_array($nativeExportFilters) ? $nativeExportFilters : $nativeExportFilters->toArray();

        if (count($nativeExportFilters) !== 1) {
            throw new \Exception(
                            sprintf(
                                    'Expected exactly 1 filter for group "%s", got %d.',
                                    'workflow-file=>native-xml',
                                    count($nativeExportFilters)
                            )
                    );
        }

        $exportFilter = array_shift($nativeExportFilters);
        $exportFilter->setDeployment($this->getDeployment());
        $exportFilter->setOpts($this->opts);

        foreach ($noteFiles as $submissionFile) {
            $submissionFileDoc = $exportFilter->execute($submissionFile);

            if ($submissionFileDoc && $submissionFileDoc->documentElement) {
                $clone = $doc->importNode($submissionFileDoc->documentElement, true);
                $noteNode->appendChild($clone);
            }
        }
    }

    public function addReviewRounds($doc, $stageNode, $submission, $stageId) {
        $deployment = $this->getDeployment();

        $reviewRoundDAO = DAORegistry::getDAO('ReviewRoundDAO');
        $reviewRounds = $reviewRoundDAO->getBySubmissionId($submission->getId(), $stageId);
        while ($reviewRound = $reviewRounds->next()) {
            $reviewRoundNode = $doc->createElementNS($deployment->getNamespace(), 'review_round');
            $reviewRoundNode->setAttribute('round', $reviewRound->getRound());
            $reviewRoundNode->setAttribute('status', $reviewRound->getStatus());
            $this->addReviewRoundFiles($doc, $reviewRoundNode, $submission, $reviewRound);
            $this->addReviewAssignments($doc, $reviewRoundNode, $reviewRound);
            $this->addEditorDecisions($doc, $reviewRoundNode, $submission, $stageId, $reviewRound);
            $stageNode->appendChild($reviewRoundNode);
        }
    }

    public function addReviewRoundFiles($doc, $roundNode, $submission, $reviewRound) {
        $fileStages = [SUBMISSION_FILE_REVIEW_FILE, SUBMISSION_FILE_REVIEW_REVISION];
        $filterDao = \PKP\db\DAORegistry::getDAO('FilterDAO');

        $submissionFiles = \APP\facades\Repo::submissionFile()
                ->getCollector()
                ->filterBySubmissionIds([(int) $submission->getId()])
                ->filterByFileStages($fileStages)
                ->filterByReviewRoundIds([(int) $reviewRound->getId()])
                ->getMany();

        $deployment = $this->getDeployment();

        $nativeExportFilters = $filterDao->getObjectsByGroup('workflow-file=>native-xml');
        $nativeExportFilters = is_array($nativeExportFilters) ? $nativeExportFilters : $nativeExportFilters->toArray();

        if (count($nativeExportFilters) !== 1) {
            throw new \Exception(
                            sprintf(
                                    'Expected exactly 1 filter for group "%s", got %d.',
                                    'workflow-file=>native-xml',
                                    count($nativeExportFilters)
                            )
                    );
        }

        $exportFilter = array_shift($nativeExportFilters);
        $exportFilter->setDeployment($deployment);
        $exportFilter->setOpts($this->opts);

        foreach ($submissionFiles as $submissionFile) {
            $submissionFileDoc = $exportFilter->execute($submissionFile, true);

            if ($submissionFileDoc && $submissionFileDoc->documentElement) {
                $clone = $doc->importNode($submissionFileDoc->documentElement, true);
                $roundNode->appendChild($clone);
            }
        }
    }

    public function addReviewAssignments($doc, $roundNode, $reviewRound) {
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();

        /** @var \ReviewAssignmentDAO $reviewAssignmentDAO */
        $reviewAssignmentDAO = DAORegistry::getDAO('ReviewAssignmentDAO');

        $reviewAssignments = $reviewAssignmentDAO->getByReviewRoundId(
                (int) $reviewRound->getId()
        );

        foreach ($reviewAssignments as $reviewAssignment) {

            $reviewer = Repo::user()->get((int) $reviewAssignment->getReviewerId());
            if (!$reviewer) {
                continue;
            }

            $reviewAssignmentNode = $doc->createElementNS(
                    $deployment->getNamespace(),
                    'review_assignment'
            );

            $reviewAssignmentNode->setAttribute('cancelled', (string) (int) $reviewAssignment->getCancelled());
            $reviewAssignmentNode->setAttribute('date_assigned', (string) $reviewAssignment->getDateAssigned());
            $reviewAssignmentNode->setAttribute('date_due', (string) $reviewAssignment->getDateDue());
            $reviewAssignmentNode->setAttribute('date_notified', (string) $reviewAssignment->getDateNotified());
            $reviewAssignmentNode->setAttribute('date_response_due', (string) $reviewAssignment->getDateResponseDue());
            $reviewAssignmentNode->setAttribute('declined', (string) (int) $reviewAssignment->getDeclined());
            $reviewAssignmentNode->setAttribute('last_modified', (string) $reviewAssignment->getLastModified());
            $reviewAssignmentNode->setAttribute('method', (string) $reviewAssignment->getReviewMethod());
            $reviewAssignmentNode->setAttribute('reviewer_email', (string) $reviewer->getEmail());
            $reviewAssignmentNode->setAttribute('considered', (string) $reviewAssignment->getConsidered());
            $reviewAssignmentNode->setAttribute('was_automatic', (string) $reviewAssignment->getReminderWasAutomatic());

            if ($quality = $reviewAssignment->getQuality()) {
                $reviewAssignmentNode->setAttribute('quality', (string) $quality);
            }
            if ($recommendation = $reviewAssignment->getRecommendation()) {
                $reviewAssignmentNode->setAttribute('recommendation', (string) $recommendation);
            }
            if ($competingInterests = $reviewAssignment->getCompetingInterests()) {
                $reviewAssignmentNode->setAttribute('competing_interests', (string) $competingInterests);
            }
            if ($dateRated = $reviewAssignment->getDateRated()) {
                $reviewAssignmentNode->setAttribute('date_rated', (string) $dateRated);
            }
            if ($dateReminded = $reviewAssignment->getDateReminded()) {
                $reviewAssignmentNode->setAttribute('date_reminded', (string) $dateReminded);
            }
            if ($dateConfirmed = $reviewAssignment->getDateConfirmed()) {
                $reviewAssignmentNode->setAttribute('date_confirmed', (string) $dateConfirmed);
            }
            if ($dateCompleted = $reviewAssignment->getDateCompleted()) {
                $reviewAssignmentNode->setAttribute('date_completed', (string) $dateCompleted);
            }
            if ($dateAcknowledged = $reviewAssignment->getDateAcknowledged()) {
                $reviewAssignmentNode->setAttribute('date_acknowledged', (string) $dateAcknowledged);
            }

            $this->addReviewerFiles($doc, $reviewAssignmentNode, $reviewAssignment);

            $reviewFiles = Repo::submissionFile()
                    ->getCollector()
                    ->filterBySubmissionIds([(int) $reviewAssignment->getSubmissionId()])
                    ->filterByReviewIds([(int) $reviewAssignment->getId()])
                    ->filterByReviewRoundIds([(int) $reviewRound->getId()])
                    ->getMany();

            $reviewFileIds = [];
            foreach ($reviewFiles as $reviewFile) {
                $reviewFileIds[] = (int) $reviewFile->getId();
            }

            if (!empty($reviewFileIds)) {
                $reviewAssignmentNode->appendChild(
                        $doc->createElementNS(
                                $deployment->getNamespace(),
                                'review_files',
                                htmlspecialchars(join(':', $reviewFileIds), ENT_COMPAT, 'UTF-8')
                        )
                );
            }

            if ($reviewAssignment->getReviewFormId()) {
                $reviewAssignmentNode->setAttribute(
                        'review_form_id',
                        (string) $reviewAssignment->getReviewFormId()
                );
                $this->addReviewFormResponses($doc, $reviewAssignmentNode, $reviewAssignment);
            } else {
                $this->addSubmissionComments($doc, $reviewAssignmentNode, $reviewAssignment);
            }

            $roundNode->appendChild($reviewAssignmentNode);
        }
    }

    public function addReviewerFiles($doc, $reviewAssignmentNode, $reviewAssignment) {
        $deployment = $this->getDeployment();

        $submissionFiles = Repo::submissionFile()
                ->getCollector()
                ->filterBySubmissionIds([(int) $reviewAssignment->getSubmissionId()])
                ->filterByAssoc(ASSOC_TYPE_REVIEW_ASSIGNMENT, [(int) $reviewAssignment->getId()])
                ->getMany();

        $filterDao = DAORegistry::getDAO('FilterDAO');
        $nativeExportFilters = $filterDao->getObjectsByGroup('workflow-file=>native-xml');
        $nativeExportFilters = is_array($nativeExportFilters) ? $nativeExportFilters : $nativeExportFilters->toArray();

        if (count($nativeExportFilters) !== 1) {
            throw new \Exception(
                            sprintf(
                                    'Expected exactly 1 filter for group "%s", got %d.',
                                    'workflow-file=>native-xml',
                                    count($nativeExportFilters)
                            )
                    );
        }

        $exportFilter = array_shift($nativeExportFilters);
        $exportFilter->setDeployment($this->getDeployment());
        $exportFilter->setOpts($this->opts);

        foreach ($submissionFiles as $submissionFile) {
            $submissionFileDoc = $exportFilter->execute($submissionFile, true);

            if ($submissionFileDoc && $submissionFileDoc->documentElement) {
                $clone = $doc->importNode($submissionFileDoc->documentElement, true);
                $reviewAssignmentNode->appendChild($clone);
            }
        }
    }

    public function addReviewFormResponses($doc, $reviewAssignmentNode, $reviewAssignment) {
        $deployment = $this->getDeployment();
        $reviewFormResponseDAO = DAORegistry::getDAO('ReviewFormResponseDAO');
        $responseValues = $reviewFormResponseDAO->getReviewReviewFormResponseValues($reviewAssignment->getId());
        foreach ($responseValues as $reviewFormElementId => $value) {
            $response = $reviewFormResponseDAO->getReviewFormResponse($reviewAssignment->getId(), $reviewFormElementId);
            $responseValue = null;
            switch ($response->getResponseType()) {
                case 'int':
                    $responseValue = intval($response->getValue());
                    break;
                case 'string':
                    $responseValue = htmlspecialchars($response->getValue(), ENT_COMPAT, 'UTF-8');
                    break;
                case 'object':
                    $responseValue = join(':', $response->getValue());
                    break;
                default:
                    break;
            }
            $responseNode = $doc->createElementNS($deployment->getNamespace(), 'response', $responseValue);
            $responseNode->setAttribute('form_element_id', $response->getReviewFormElementId());
            $responseNode->setAttribute('type', $response->getResponseType());
            $reviewAssignmentNode->appendChild($responseNode);
        }
    }

    public function addSubmissionComments($doc, $reviewAssignmentNode, $reviewAssignment) {
        $deployment = $this->getDeployment();

        $submissionCommentDAO = DAORegistry::getDAO('SubmissionCommentDAO');

        $comments = $submissionCommentDAO->getReviewerCommentsByReviewerId(
                (int) $reviewAssignment->getSubmissionId(),
                null,
                (int) $reviewAssignment->getId()
        );

        while ($comment = $comments->next()) {

            $commentAuthor = Repo::user()->get((int) $comment->getAuthorId());
            if (!$commentAuthor) {
                continue;
            }

            $submissionCommentNode = $doc->createElementNS(
                    $deployment->getNamespace(),
                    'submission_comment'
            );

            $submissionCommentNode->setAttribute('comment_type', (string) $comment->getCommentType());
            $submissionCommentNode->setAttribute('role', (string) $comment->getRoleId());
            $submissionCommentNode->setAttribute('author', (string) $commentAuthor->getEmail());
            $submissionCommentNode->setAttribute('date_posted', (string) $comment->getDatePosted());
            $submissionCommentNode->setAttribute('date_modified', (string) $comment->getDateModified());
            $submissionCommentNode->setAttribute('viewable', (string) $comment->getViewable());

            $titleNode = $doc->createElementNS(
                    $deployment->getNamespace(),
                    'title',
                    htmlspecialchars((string) $comment->getCommentTitle(), ENT_COMPAT, 'UTF-8')
            );
            $submissionCommentNode->appendChild($titleNode);

            $commentsNode = $doc->createElementNS(
                    $deployment->getNamespace(),
                    'comments',
                    htmlspecialchars((string) $comment->getComments(), ENT_COMPAT, 'UTF-8')
            );
            $submissionCommentNode->appendChild($commentsNode);

            $reviewAssignmentNode->appendChild($submissionCommentNode);
        }
    }

    private function getStageMapping() {
        return [
            WORKFLOW_STAGE_ID_SUBMISSION => WORKFLOW_STAGE_PATH_SUBMISSION,
            WORKFLOW_STAGE_ID_INTERNAL_REVIEW => WORKFLOW_STAGE_PATH_INTERNAL_REVIEW,
            WORKFLOW_STAGE_ID_EXTERNAL_REVIEW => WORKFLOW_STAGE_PATH_EXTERNAL_REVIEW,
            WORKFLOW_STAGE_ID_EDITING => WORKFLOW_STAGE_PATH_EDITING,
            WORKFLOW_STAGE_ID_PRODUCTION => WORKFLOW_STAGE_PATH_PRODUCTION
        ];
    }
}
