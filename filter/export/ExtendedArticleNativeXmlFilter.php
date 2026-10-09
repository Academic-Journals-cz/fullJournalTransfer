<?php

/**
 * @file plugins/importexport/fullJournalTransfer/filter/export/ExtendedArticleNativeXmlFilter.php
 *
 * Copyright (c) 2014-2024 Lepidus Tecnologia
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ExtendedArticleNativeXmlFilter
 *
 * @brief Converts a submission to the native XML extended with the editorial
 *  workflow data: stage assignments, review rounds, review assignments (with
 *  reviewer files, form responses and comments), editor decisions, discussions
 *  and the activity log (event log + e-mail log).
 */

namespace APP\plugins\importexport\fullJournalTransfer\filter\export;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\importexport\fullJournalTransfer\classes\ActivityLog;
use APP\plugins\importexport\fullJournalTransfer\classes\FullJournalFilterTrait;
use APP\plugins\importexport\fullJournalTransfer\classes\XmlText;
use APP\plugins\importexport\native\filter\ArticleNativeXmlFilter;
use APP\submission\Submission;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\DB;
use PKP\db\DAORegistry;
use PKP\note\Note;
use PKP\plugins\importexport\PKPImportExportFilter;
use PKP\query\Query;
use PKP\stageAssignment\StageAssignment;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\submission\reviewRound\ReviewRound;
use PKP\submissionFile\SubmissionFile;
use PKP\userGroup\UserGroup;
use PKP\workflow\WorkflowStageDAO;

class ExtendedArticleNativeXmlFilter extends ArticleNativeXmlFilter
{
    use FullJournalFilterTrait;

    public function __construct($filterGroup)
    {
        parent::__construct($filterGroup);
    }

    public function getClassName(): string
    {
        return static::class;
    }

    /**
     * @copydoc SubmissionNativeXmlFilter::createSubmissionNode()
     */
    public function createSubmissionNode($doc, $submission)
    {
        $submissionNode = parent::createSubmissionNode($doc, $submission);

        $this->setOptionalAttribute($submissionNode, 'datetime_submitted', $submission->getData('dateSubmitted'));
        $this->setOptionalAttribute($submissionNode, 'last_modified', $submission->getData('lastModified'));
        $this->setOptionalAttribute($submissionNode, 'date_last_activity', $submission->getData('dateLastActivity'));

        $this->addPublicationCategories($doc, $submissionNode, $submission);
        $this->addReviewerSuggestions($doc, $submissionNode, $submission);
        $this->addSubmissionFileDates($doc, $submissionNode, $submission);
        $this->addStageAssignments($doc, $submissionNode, $submission);
        $this->addStages($doc, $submissionNode, $submission);
        if (empty($this->opts['no-activity-log'])) {
            $this->addActivityLog($doc, $submissionNode, $submission);
        }

        return $submissionNode;
    }

    /**
     * Export the publications (versions) with the extended publication filter,
     * which records the issue of every version.
     *
     * @copydoc SubmissionNativeXmlFilter::addPublications()
     */
    public function addPublications($doc, $submissionNode, $submission)
    {
        $filter = $this->getSubFilter('extended-publication=>native-xml');
        foreach ($submission->getData('publications') as $publication) {
            $publicationDoc = $filter->execute($publication, true);
            if ($publicationDoc instanceof DOMDocument && $publicationDoc->documentElement instanceof DOMElement) {
                $submissionNode->appendChild($doc->importNode($publicationDoc->documentElement, true));
            } else {
                $this->getDeployment()->addError(Application::ASSOC_TYPE_SUBMISSION, $submission->getId(), __('plugins.importexport.publication.exportFailed'));
                throw new \Exception(__('plugins.importexport.publication.exportFailed'));
            }
        }
    }

    /**
     * Files of the review process and of discussions are exported with their
     * review round / review assignment / note (as workflow_file elements), so
     * skip them here without the warning the native filter would produce.
     *
     * @copydoc SubmissionNativeXmlFilter::addFiles()
     */
    public function addFiles(DOMDocument $doc, DOMElement $submissionNode, Submission $submission): void
    {
        $submissionFiles = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$submission->getId()])
            ->includeDependentFiles()
            ->getMany();

        $excludedFileStages = [
            SubmissionFile::SUBMISSION_FILE_QUERY,
            SubmissionFile::SUBMISSION_FILE_NOTE,
            SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT,
            SubmissionFile::SUBMISSION_FILE_REVIEW_FILE,
            SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION,
            SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_FILE,
            SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_REVISION,
        ];

        $filter = PKPImportExportFilter::getFilter('SubmissionFile=>native-xml', $this->getDeployment(), $this->opts);
        $filter->setNoValidation(true);
        foreach ($submissionFiles as $submissionFile) {
            if (in_array($submissionFile->getData('fileStage'), $excludedFileStages)) {
                continue;
            }
            $submissionFileDoc = $filter->execute($submissionFile, true);
            if ($submissionFileDoc instanceof DOMDocument && $submissionFileDoc->documentElement instanceof DOMElement) {
                $clone = $doc->importNode($submissionFileDoc->documentElement, true);
                $submissionNode->appendChild($clone);
            }
        }
    }

    //
    // Categories of the publications (versions)
    //
    public function addPublicationCategories(DOMDocument $doc, DOMElement $submissionNode, Submission $submission): void
    {
        $deployment = $this->getDeployment();
        $publicationIds = [];
        foreach ($submission->getData('publications') ?? [] as $publication) {
            $publicationIds[] = (int) $publication->getId();
        }
        if (empty($publicationIds)) {
            return;
        }
        $rows = DB::table('publication_categories')
            ->whereIn('publication_id', $publicationIds)
            ->orderBy('publication_category_id')
            ->get();
        if ($rows->isEmpty()) {
            return;
        }
        $categoriesNode = $doc->createElementNS($deployment->getNamespace(), 'publication_categories');
        foreach ($rows as $row) {
            $node = $doc->createElementNS($deployment->getNamespace(), 'publication_category');
            $node->setAttribute('publication_id', (string) $row->publication_id);
            $node->setAttribute('category_id', (string) $row->category_id);
            $categoriesNode->appendChild($node);
        }
        $submissionNode->appendChild($categoriesNode);
    }

    //
    // Reviewers suggested by the authors
    //
    public function addReviewerSuggestions(DOMDocument $doc, DOMElement $submissionNode, Submission $submission): void
    {
        $deployment = $this->getDeployment();
        if (!DB::getSchemaBuilder()->hasTable('reviewer_suggestions')) {
            return;
        }
        $suggestions = DB::table('reviewer_suggestions')
            ->where('submission_id', (int) $submission->getId())
            ->orderBy('reviewer_suggestion_id')
            ->get();
        if ($suggestions->isEmpty()) {
            return;
        }
        $settings = DB::table('reviewer_suggestion_settings')
            ->whereIn('reviewer_suggestion_id', $suggestions->pluck('reviewer_suggestion_id')->all())
            ->orderBy('setting_name')
            ->orderBy('locale')
            ->get()
            ->groupBy('reviewer_suggestion_id');

        $suggestionsNode = $doc->createElementNS($deployment->getNamespace(), 'reviewer_suggestions');
        foreach ($suggestions as $suggestion) {
            $node = $doc->createElementNS($deployment->getNamespace(), 'reviewer_suggestion');
            $this->setOptionalAttribute($node, 'suggesting_user_email', $this->getUserEmailById((int) $suggestion->suggesting_user_id));
            $node->setAttribute('email', (string) $suggestion->email);
            $this->setOptionalAttribute($node, 'orcid_id', $suggestion->orcid_id);
            $this->setOptionalAttribute($node, 'approved_at', $suggestion->approved_at);
            $this->setOptionalAttribute($node, 'approver_email', $this->getUserEmailById((int) $suggestion->approver_id));
            $this->setOptionalAttribute($node, 'reviewer_email', $this->getUserEmailById((int) $suggestion->reviewer_id));
            $this->setOptionalAttribute($node, 'created_at', $suggestion->created_at);
            $this->setOptionalAttribute($node, 'updated_at', $suggestion->updated_at);
            $this->appendSettingNodes($doc, $node, $settings[$suggestion->reviewer_suggestion_id] ?? []);
            $suggestionsNode->appendChild($node);
        }
        $submissionNode->appendChild($suggestionsNode);
    }

    //
    // Timestamps of the submission files
    //
    public function addSubmissionFileDates(DOMDocument $doc, DOMElement $submissionNode, Submission $submission): void
    {
        $deployment = $this->getDeployment();
        $rows = DB::table('submission_files')
            ->where('submission_id', (int) $submission->getId())
            ->orderBy('submission_file_id')
            ->get(['submission_file_id', 'created_at', 'updated_at']);
        if ($rows->isEmpty()) {
            return;
        }
        $datesNode = $doc->createElementNS($deployment->getNamespace(), 'submission_file_dates');
        foreach ($rows as $row) {
            $node = $doc->createElementNS($deployment->getNamespace(), 'file_dates');
            $node->setAttribute('id', (string) $row->submission_file_id);
            $this->setOptionalAttribute($node, 'created_at', $row->created_at);
            $this->setOptionalAttribute($node, 'updated_at', $row->updated_at);
            $datesNode->appendChild($node);
        }
        $submissionNode->appendChild($datesNode);
    }

    //
    // Stage assignments (participants)
    //
    public function addStageAssignments(DOMDocument $doc, DOMElement $submissionNode, Submission $submission): void
    {
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();

        $stageAssignments = StageAssignment::withSubmissionIds([(int) $submission->getId()])
            ->orderBy('stage_assignment_id')
            ->get();
        if ($stageAssignments->isEmpty()) {
            return;
        }

        $assignmentsNode = $doc->createElementNS($deployment->getNamespace(), 'stage_assignments');
        foreach ($stageAssignments as $stageAssignment) {
            $userEmail = $this->getUserEmailById((int) $stageAssignment->userId);
            if (!$userEmail) {
                continue;
            }
            $userGroup = UserGroup::find((int) $stageAssignment->userGroupId);
            if (!$userGroup || (int) $userGroup->contextId !== (int) $context->getId()) {
                continue;
            }
            $node = $doc->createElementNS($deployment->getNamespace(), 'stage_assignment');
            $node->setAttribute('user_email', $userEmail);
            $node->setAttribute('user_group_ref', (string) $userGroup->getLocalizedData('name', $context->getPrimaryLocale()));
            $node->setAttribute('recommend_only', $stageAssignment->recommendOnly ? '1' : '0');
            $node->setAttribute('can_change_metadata', $stageAssignment->canChangeMetadata ? '1' : '0');
            $this->setOptionalAttribute($node, 'date_assigned', $stageAssignment->dateAssigned);
            $assignmentsNode->appendChild($node);
        }
        if ($assignmentsNode->hasChildNodes()) {
            $submissionNode->appendChild($assignmentsNode);
        }
    }

    //
    // Workflow stages
    //
    public function addStages(DOMDocument $doc, DOMElement $submissionNode, Submission $submission): void
    {
        $deployment = $this->getDeployment();

        // Editor decisions grouped by stage and review round
        $decisionsByStage = [];
        $decisions = Repo::decision()->getCollector()
            ->filterBySubmissionIds([(int) $submission->getId()])
            ->getMany();
        foreach ($decisions as $decision) {
            $stageId = (int) $decision->getData('stageId');
            $reviewRoundId = (int) ($decision->getData('reviewRoundId') ?? 0);
            $decisionsByStage[$stageId][$reviewRoundId][] = $decision;
        }

        foreach ($this->getStageMapping() as $stageId => $stagePath) {
            $stageNode = $doc->createElementNS($deployment->getNamespace(), 'stage');
            $stageNode->setAttribute('path', $stagePath);

            if (in_array($stageId, [WORKFLOW_STAGE_ID_INTERNAL_REVIEW, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW])) {
                $this->addReviewRounds($doc, $stageNode, $submission, $stageId, $decisionsByStage[$stageId] ?? []);
            }
            // Decisions without a review round
            foreach ($decisionsByStage[$stageId][0] ?? [] as $decision) {
                $this->addDecision($doc, $stageNode, $decision);
            }
            $this->addQueries($doc, $stageNode, $submission, $stageId);

            if ($stageNode->hasChildNodes()) {
                $submissionNode->appendChild($stageNode);
            }
        }
    }

    public function addReviewRounds(DOMDocument $doc, DOMElement $stageNode, Submission $submission, int $stageId, array $decisionsByRound): void
    {
        $deployment = $this->getFullJournalDeployment();
        $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO'); /** @var \PKP\submission\reviewRound\ReviewRoundDAO $reviewRoundDao */
        $reviewRounds = $reviewRoundDao->getBySubmissionId((int) $submission->getId(), $stageId);

        while ($reviewRound = $reviewRounds->next()) {
            /** @var ReviewRound $reviewRound */
            $deployment->setReviewRound($reviewRound);

            $reviewRoundNode = $doc->createElementNS($deployment->getNamespace(), 'review_round');
            $reviewRoundNode->setAttribute('id', (string) $reviewRound->getId());
            $reviewRoundNode->setAttribute('round', (string) (int) $reviewRound->getRound());
            $reviewRoundNode->setAttribute('status', (string) (int) $reviewRound->getStatus());

            $this->addReviewRoundFiles($doc, $reviewRoundNode, $submission, $reviewRound);
            $this->addReviewAssignments($doc, $reviewRoundNode, $reviewRound);
            foreach ($decisionsByRound[(int) $reviewRound->getId()] ?? [] as $decision) {
                $this->addDecision($doc, $reviewRoundNode, $decision);
            }

            $stageNode->appendChild($reviewRoundNode);
        }
        $deployment->setReviewRound(null);
    }

    /**
     * Files of a review round: the files sent to the reviewers and the revisions
     * uploaded by the authors.
     */
    public function addReviewRoundFiles(DOMDocument $doc, DOMElement $roundNode, Submission $submission, ReviewRound $reviewRound): void
    {
        $submissionFiles = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([(int) $submission->getId()])
            ->filterByFileStages([
                SubmissionFile::SUBMISSION_FILE_REVIEW_FILE,
                SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION,
                SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_FILE,
                SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_REVISION,
            ])
            ->filterByReviewRoundIds([(int) $reviewRound->getId()])
            ->getMany();

        foreach ($submissionFiles as $submissionFile) {
            $this->appendExportedNode('workflow-file=>native-xml', $submissionFile, $doc, $roundNode);
        }
    }

    public function addReviewAssignments(DOMDocument $doc, DOMElement $roundNode, ReviewRound $reviewRound): void
    {
        $deployment = $this->getFullJournalDeployment();

        $reviewAssignments = Repo::reviewAssignment()->getCollector()
            ->filterByReviewRoundIds([(int) $reviewRound->getId()])
            ->getMany();

        foreach ($reviewAssignments as $reviewAssignment) {
            /** @var ReviewAssignment $reviewAssignment */
            $reviewerEmail = $this->getUserEmailById((int) $reviewAssignment->getReviewerId());
            if (!$reviewerEmail) {
                continue;
            }
            $deployment->setReviewAssignment($reviewAssignment);

            $node = $doc->createElementNS($deployment->getNamespace(), 'review_assignment');
            $node->setAttribute('id', (string) $reviewAssignment->getId());
            $node->setAttribute('reviewer_email', $reviewerEmail);
            $node->setAttribute('method', (string) (int) $reviewAssignment->getReviewMethod());
            $node->setAttribute('round', (string) (int) $reviewAssignment->getRound());
            $node->setAttribute('step', (string) (int) $reviewAssignment->getStep());
            $node->setAttribute('declined', $reviewAssignment->getDeclined() ? '1' : '0');
            $node->setAttribute('cancelled', $reviewAssignment->getCancelled() ? '1' : '0');
            $node->setAttribute('reminder_was_automatic', (string) (int) $reviewAssignment->getReminderWasAutomatic());
            $node->setAttribute('considered', (string) (int) $reviewAssignment->getConsidered());
            $node->setAttribute('request_resent', $reviewAssignment->getRequestResent() ? '1' : '0');

            $this->setOptionalAttribute($node, 'date_assigned', $reviewAssignment->getDateAssigned());
            $this->setOptionalAttribute($node, 'date_notified', $reviewAssignment->getDateNotified());
            $this->setOptionalAttribute($node, 'date_confirmed', $reviewAssignment->getDateConfirmed());
            $this->setOptionalAttribute($node, 'date_completed', $reviewAssignment->getDateCompleted());
            $this->setOptionalAttribute($node, 'date_acknowledged', $reviewAssignment->getDateAcknowledged());
            $this->setOptionalAttribute($node, 'date_due', $reviewAssignment->getDateDue());
            $this->setOptionalAttribute($node, 'date_response_due', $reviewAssignment->getDateResponseDue());
            $this->setOptionalAttribute($node, 'last_modified', $reviewAssignment->getLastModified());
            $this->setOptionalAttribute($node, 'date_cancelled', $reviewAssignment->getData('dateCancelled'));
            $this->setOptionalAttribute($node, 'date_rated', $reviewAssignment->getDateRated());
            $this->setOptionalAttribute($node, 'date_reminded', $reviewAssignment->getDateReminded());
            $this->setOptionalAttribute($node, 'date_considered', $reviewAssignment->getDateConsidered());
            $this->setOptionalAttribute($node, 'quality', $reviewAssignment->getQuality());
            $this->setOptionalAttribute($node, 'recommendation', $reviewAssignment->getRecommendation());
            $this->setOptionalAttribute($node, 'competing_interests', $reviewAssignment->getCompetingInterests());
            $this->setOptionalAttribute($node, 'review_form_id', $reviewAssignment->getReviewFormId());

            // Settings (e.g. ORCID review put code)
            $settings = DB::table('review_assignment_settings')
                ->where('review_id', (int) $reviewAssignment->getId())
                ->get();
            foreach ($settings as $setting) {
                if ($setting->setting_value === null) {
                    continue;
                }
                $settingNode = $doc->createElementNS($deployment->getNamespace(), 'setting');
                $settingNode->setAttribute('name', $setting->setting_name);
                if ((string) $setting->locale !== '') {
                    $settingNode->setAttribute('locale', $setting->locale);
                }
                $settingNode->appendChild($doc->createTextNode(XmlText::sanitize((string) $setting->setting_value)));
                $node->appendChild($settingNode);
            }

            // Files uploaded by the reviewer
            $this->addReviewerFiles($doc, $node, $reviewAssignment);

            // Review files the reviewer has access to
            $reviewFileIds = DB::table('review_files')
                ->where('review_id', (int) $reviewAssignment->getId())
                ->pluck('submission_file_id')
                ->all();
            if (!empty($reviewFileIds)) {
                $node->appendChild($doc->createElementNS(
                    $deployment->getNamespace(),
                    'review_files',
                    implode(':', array_map('intval', $reviewFileIds))
                ));
            }

            if ($reviewAssignment->getReviewFormId()) {
                $this->addReviewFormResponses($doc, $node, $reviewAssignment);
            }
            $this->addSubmissionComments($doc, $node, $reviewAssignment);

            $roundNode->appendChild($node);
        }
        $deployment->setReviewAssignment(null);
    }

    public function addReviewerFiles(DOMDocument $doc, DOMElement $reviewAssignmentNode, ReviewAssignment $reviewAssignment): void
    {
        $submissionFiles = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([(int) $reviewAssignment->getSubmissionId()])
            ->filterByAssoc(Application::ASSOC_TYPE_REVIEW_ASSIGNMENT, [(int) $reviewAssignment->getId()])
            ->getMany();

        foreach ($submissionFiles as $submissionFile) {
            $this->appendExportedNode('workflow-file=>native-xml', $submissionFile, $doc, $reviewAssignmentNode);
        }
    }

    public function addReviewFormResponses(DOMDocument $doc, DOMElement $reviewAssignmentNode, ReviewAssignment $reviewAssignment): void
    {
        $deployment = $this->getDeployment();
        $rows = DB::table('review_form_responses')
            ->where('review_id', (int) $reviewAssignment->getId())
            ->orderBy('review_form_element_id')
            ->get();

        foreach ($rows as $row) {
            $responseNode = $doc->createElementNS($deployment->getNamespace(), 'response');
            $responseNode->setAttribute('form_element_id', (string) $row->review_form_element_id);
            $responseNode->setAttribute('type', (string) $row->response_type);
            $value = (string) $row->response_value;
            if ($row->response_type === 'object') {
                // stored as JSON (OJS 3.4+) or PHP-serialized (older data); transfer as JSON
                $decoded = json_decode($value, true);
                if (!is_array($decoded)) {
                    $decoded = @unserialize($value, ['allowed_classes' => false]);
                }
                $value = json_encode(is_array($decoded) ? array_values($decoded) : [], JSON_UNESCAPED_UNICODE);
            }
            $responseNode->appendChild($doc->createTextNode(XmlText::sanitize($value)));
            $reviewAssignmentNode->appendChild($responseNode);
        }
    }

    public function addSubmissionComments(DOMDocument $doc, DOMElement $reviewAssignmentNode, ReviewAssignment $reviewAssignment): void
    {
        $deployment = $this->getDeployment();
        $submissionCommentDao = DAORegistry::getDAO('SubmissionCommentDAO'); /** @var \PKP\submission\SubmissionCommentDAO $submissionCommentDao */

        $comments = $submissionCommentDao->getReviewerCommentsByReviewerId(
            (int) $reviewAssignment->getSubmissionId(),
            null,
            (int) $reviewAssignment->getId()
        );

        while ($comment = $comments->next()) {
            $authorEmail = $this->getUserEmailById((int) $comment->getAuthorId());
            if (!$authorEmail) {
                continue;
            }

            $commentNode = $doc->createElementNS($deployment->getNamespace(), 'submission_comment');
            $commentNode->setAttribute('comment_type', (string) (int) $comment->getCommentType());
            $commentNode->setAttribute('role', (string) (int) $comment->getRoleId());
            $commentNode->setAttribute('author', $authorEmail);
            $this->setOptionalAttribute($commentNode, 'date_posted', $comment->getDatePosted());
            $this->setOptionalAttribute($commentNode, 'date_modified', $comment->getDateModified());
            $commentNode->setAttribute('viewable', $comment->getViewable() ? '1' : '0');

            $commentNode->appendChild($this->createTextNode($doc, 'title', XmlText::sanitize((string) $comment->getCommentTitle())));
            $commentNode->appendChild($this->createTextNode($doc, 'comments', XmlText::sanitize((string) $comment->getComments())));

            $reviewAssignmentNode->appendChild($commentNode);
        }
    }

    public function addDecision(DOMDocument $doc, DOMElement $parentNode, $decision): void
    {
        $deployment = $this->getDeployment();
        $editorEmail = $this->getUserEmailById((int) ($decision->getData('editorId') ?? 0));
        if (!$editorEmail) {
            return;
        }

        $decisionNode = $doc->createElementNS($deployment->getNamespace(), 'decision');
        $decisionNode->setAttribute('decision', (string) (int) $decision->getData('decision'));
        $decisionNode->setAttribute('editor_email', $editorEmail);
        $this->setOptionalAttribute($decisionNode, 'round', $decision->getData('round'));
        $this->setOptionalAttribute($decisionNode, 'date_decided', $decision->getData('dateDecided'));
        $parentNode->appendChild($decisionNode);
    }

    //
    // Discussions (queries)
    //
    public function addQueries(DOMDocument $doc, DOMElement $stageNode, Submission $submission, int $stageId): void
    {
        $deployment = $this->getFullJournalDeployment();

        $queries = Query::withAssoc(Application::ASSOC_TYPE_SUBMISSION, (int) $submission->getId())
            ->withStageId($stageId)
            ->orderBy('seq')
            ->get();
        if ($queries->isEmpty()) {
            return;
        }

        $queriesNode = $doc->createElementNS($deployment->getNamespace(), 'queries');
        foreach ($queries as $query) {
            /** @var Query $query */
            $queryNode = $doc->createElementNS($deployment->getNamespace(), 'query');
            $queryNode->setAttribute('seq', (string) (float) $query->seq);
            $queryNode->setAttribute('closed', $query->closed ? '1' : '0');
            $this->setOptionalAttribute($queryNode, 'date_posted', $query->datePosted?->format('Y-m-d H:i:s'));
            $this->setOptionalAttribute($queryNode, 'date_modified', $query->dateModified?->format('Y-m-d H:i:s'));

            $participantsNode = $doc->createElementNS($deployment->getNamespace(), 'participants');
            $participantIds = $query->queryParticipants()->pluck('user_id')->all();
            foreach ($participantIds as $participantId) {
                if ($email = $this->getUserEmailById((int) $participantId)) {
                    $participantsNode->appendChild($this->createTextNode($doc, 'participant', $email));
                }
            }
            $queryNode->appendChild($participantsNode);

            $repliesNode = $doc->createElementNS($deployment->getNamespace(), 'replies');
            $notes = Note::withAssoc(Application::ASSOC_TYPE_QUERY, (int) $query->id)
                ->withSort(Note::NOTE_ORDER_DATE_CREATED, \PKP\db\DAO::SORT_DIRECTION_ASC)
                ->get();
            foreach ($notes as $note) {
                /** @var Note $note */
                $deployment->setNote($note);
                $noteNode = $doc->createElementNS($deployment->getNamespace(), 'note');
                if ($email = $this->getUserEmailById((int) $note->userId)) {
                    $noteNode->setAttribute('user_email', $email);
                }
                $this->setOptionalAttribute($noteNode, 'date_created', $note->dateCreated?->format('Y-m-d H:i:s'));
                $this->setOptionalAttribute($noteNode, 'date_modified', $note->dateModified?->format('Y-m-d H:i:s'));
                $noteNode->appendChild($this->createTextNode($doc, 'title', XmlText::sanitize((string) $note->title)));
                $noteNode->appendChild($this->createTextNode($doc, 'contents', XmlText::sanitize((string) $note->contents)));
                $this->addNoteFiles($doc, $noteNode, $submission, $note);
                $repliesNode->appendChild($noteNode);
            }
            $deployment->setNote(null);
            $queryNode->appendChild($repliesNode);

            $queriesNode->appendChild($queryNode);
        }
        $stageNode->appendChild($queriesNode);
    }

    public function addNoteFiles(DOMDocument $doc, DOMElement $noteNode, Submission $submission, Note $note): void
    {
        $noteFiles = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([(int) $submission->getId()])
            ->filterByAssoc(Application::ASSOC_TYPE_NOTE, [(int) $note->id])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_QUERY])
            ->getMany();

        foreach ($noteFiles as $submissionFile) {
            $this->appendExportedNode('workflow-file=>native-xml', $submissionFile, $doc, $noteNode);
        }
    }

    //
    // Activity log
    //
    public function addActivityLog(DOMDocument $doc, DOMElement $submissionNode, Submission $submission): void
    {
        $deployment = $this->getFullJournalDeployment();
        $submissionFileIds = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([(int) $submission->getId()])
            ->includeDependentFiles()
            ->getIds()
            ->all();

        $eventLogNode = ActivityLog::createEventLogNode($doc, $deployment, (int) $submission->getId(), $submissionFileIds);
        if ($eventLogNode) {
            $submissionNode->appendChild($eventLogNode);
        }
        $emailLogNode = ActivityLog::createEmailLogNode($doc, $deployment, (int) $submission->getId());
        if ($emailLogNode) {
            $submissionNode->appendChild($emailLogNode);
        }
    }

    private function getStageMapping(): array
    {
        return [
            WORKFLOW_STAGE_ID_SUBMISSION => WorkflowStageDAO::WORKFLOW_STAGE_PATH_SUBMISSION,
            WORKFLOW_STAGE_ID_INTERNAL_REVIEW => WorkflowStageDAO::WORKFLOW_STAGE_PATH_INTERNAL_REVIEW,
            WORKFLOW_STAGE_ID_EXTERNAL_REVIEW => WorkflowStageDAO::WORKFLOW_STAGE_PATH_EXTERNAL_REVIEW,
            WORKFLOW_STAGE_ID_EDITING => WorkflowStageDAO::WORKFLOW_STAGE_PATH_EDITING,
            WORKFLOW_STAGE_ID_PRODUCTION => WorkflowStageDAO::WORKFLOW_STAGE_PATH_PRODUCTION,
        ];
    }
}
