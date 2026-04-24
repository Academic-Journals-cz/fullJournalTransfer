<?php
namespace APP\plugins\importexport\fullJournalTransfer\filter\import;

use APP\core\Application;
use APP\core\Services;
use APP\facades\Repo;
use APP\plugins\importexport\native\filter\NativeXmlArticleFileFilter;
use DOMElement;
use PKP\db\DAORegistry;
use Illuminate\Support\Facades\DB;

class NativeXmlWorkflowFileFilter extends NativeXmlArticleFileFilter {

    /** @var array<int, array<string, object>> */
    protected array $genresByContextId = [];

    public function getClassName(): string {
        return static::class;
    }

    public function getPluralElementName(): string {
        return 'workflow_files';
    }

    public function getSingularElementName(): string {
        return 'workflow_file';
    }

    public function handleElement($node) {
        $deployment = $this->getDeployment();
        $submission = $deployment->getSubmission();
        $context = $deployment->getContext();
        $reviewRound = $deployment->getReviewRound();

        $stageName = $node->getAttribute('stage');
        $stageNameIdMapping = $deployment->getStageNameStageIdMapping();
        assert(isset($stageNameIdMapping[$stageName]));
        $stageId = $stageNameIdMapping[$stageName];

        $request = Application::get()->getRequest();
        $router = $request->getRouter();
        $router->_contextPaths[0] = $context->getPath();
        $dispatcher = Application::get()->getDispatcher();
        $request->setDispatcher($dispatcher);

        $errorOccurred = false;

        $genreId = null;
        $genreName = $node->getAttribute('genre');

        if ($genreName) {
            $contextId = (int) $context->getId();

            if (!isset($this->genresByContextId[$contextId])) {
                $genreDao = DAORegistry::getDAO('GenreDAO');
                $genres = $genreDao->getByContextId($contextId);

                $this->genresByContextId[$contextId] = [];

                while ($genre = $genres->next()) {
                    $names = $genre->getName(null) ?? [];
                    foreach ($names as $name) {
                        $this->genresByContextId[$contextId][$name] = $genre;
                    }
                }
            }

            if (!isset($this->genresByContextId[$contextId][$genreName])) {
                $deployment->addError(
                        ASSOC_TYPE_SUBMISSION,
                        $submission->getId(),
                        __('plugins.importexport.common.error.unknownGenre', ['param' => $genreName])
                );
                $errorOccurred = true;
            } else {
                $genre = $this->genresByContextId[$contextId][$genreName];
                $genreId = (int) $genre->getId();
            }
        }

        $uploaderUsername = $node->getAttribute('uploader');

        if (!$uploaderUsername) {
            $user = $deployment->getUser();
        } else {
            $user = Repo::user()->getByUsername($uploaderUsername, true);
        }

        $uploaderUserId = $user ? (int) $user->getId() : (int) Application::get()->getRequest()->getUser()->getId();

        $submissionFile = Repo::submissionFile()->newDataObject();
        $submissionFile->setData('submissionId', (int) $submission->getId());
        $submissionFile->setData('locale', $submission->getLocale());
        $submissionFile->setData('fileStage', (int) $stageId);
        $submissionFile->setData('createdAt', \Core::getCurrentDate());
        $submissionFile->setData('updatedAt', \Core::getCurrentDate());

        $dateCreated = $node->getAttribute('date_created');
        if ($dateCreated !== '') {
            $submissionFile->setData('dateCreated', $dateCreated);
        }
        if ($language = $node->getAttribute('language')) {
            $submissionFile->setData('language', $language);
        }
        if ($caption = $node->getAttribute('caption')) {
            $submissionFile->setData('caption', $caption);
        }
        if ($copyrightOwner = $node->getAttribute('copyright_owner')) {
            $submissionFile->setData('copyrightOwner', $copyrightOwner);
        }
        if ($credit = $node->getAttribute('credit')) {
            $submissionFile->setData('credit', $credit);
        }
        if (strlen($directSalesPrice = $node->getAttribute('direct_sales_price'))) {
            $submissionFile->setData('directSalesPrice', $directSalesPrice);
        }
        if ($genreId) {
            $submissionFile->setData('genreId', $genreId);
        }
        if ($salesType = $node->getAttribute('sales_type')) {
            $submissionFile->setData('salesType', $salesType);
        }
        if ($sourceSubmissionFileId = $node->getAttribute('source_submission_file_id')) {
            $mappedSourceSubmissionFileId = $deployment->getSubmissionFileDBId($sourceSubmissionFileId);
            $submissionFile->setData('sourceSubmissionFileId', $mappedSourceSubmissionFileId ?: null);
        }

        if ($terms = $node->getAttribute('terms')) {
            $submissionFile->setData('terms', $terms);
        }
        if ($uploaderUserId) {
            $submissionFile->setData('uploaderUserId', $uploaderUserId);
        }
        if ($node->getAttribute('viewable') === 'true') {
            $submissionFile->setViewable(true);
        }

        if ($node->getAttribute('assoc_type')) {
            $reviewRoundFileStages = [SUBMISSION_FILE_REVIEW_FILE, SUBMISSION_FILE_REVIEW_REVISION];

            if (in_array($submissionFile->getData('fileStage'), $reviewRoundFileStages, true) && $reviewRound) {
                $submissionFile->setData('assocType', ASSOC_TYPE_REVIEW_ROUND);
                $submissionFile->setData('assocId', (int) $reviewRound->getId());
            }

            if ($submissionFile->getData('fileStage') == SUBMISSION_FILE_REVIEW_ATTACHMENT) {
                $reviewAssignment = $deployment->getReviewAssignment();
                if ($reviewAssignment) {
                    $submissionFile->setData('assocType', ASSOC_TYPE_REVIEW_ASSIGNMENT);
                    $submissionFile->setData('assocId', (int) $reviewAssignment->getId());
                }
            }

            if ($submissionFile->getData('fileStage') == SUBMISSION_FILE_QUERY) {
                $note = $deployment->getNote();
                if ($note) {
                    $submissionFile->setData('assocType', ASSOC_TYPE_NOTE);
                    $submissionFile->setData('assocId', (int) $note->getId());
                }
            }
        }

        $allRevisionIds = [];

        for ($childNode = $node->firstChild; $childNode !== null; $childNode = $childNode->nextSibling) {
            if (!($childNode instanceof DOMElement)) {
                continue;
            }

            switch ($childNode->tagName) {
                case 'creator':
                case 'description':
                case 'name':
                case 'publisher':
                case 'source':
                case 'sponsor':
                case 'subject':
                    [$locale, $value] = $this->parseLocalizedContent($childNode);
                    $submissionFile->setData($childNode->tagName, $value, $locale);
                    break;

                case 'submission_file_ref':
                    if ($submissionFile->getData('fileStage') == SUBMISSION_FILE_DEPENDENT) {
                        $oldAssocId = $childNode->getAttribute('id');
                        $newAssocId = $deployment->getSubmissionFileDBId($oldAssocId);
                        if ($newAssocId) {
                            $submissionFile->setData('assocType', ASSOC_TYPE_SUBMISSION_FILE);
                            $submissionFile->setData('assocId', $newAssocId);
                        }
                    }
                    break;

                case 'file':
                    if ($deployment->getFileDBId($childNode->getAttribute('id'))) {
                        $newFileId = $deployment->getFileDBId($childNode->getAttribute('id'));
                    } else {
                        $newFileId = $this->handleRevisionElement($childNode);
                    }

                    if ($newFileId) {
                        $allRevisionIds[] = $newFileId;
                    }

                    if ($childNode->getAttribute('id') === $node->getAttribute('file_id')) {
                        $submissionFile->setData('fileId', $newFileId);
                    }
                    break;

                default:
                    $deployment->addWarning(
                            ASSOC_TYPE_SUBMISSION,
                            $submission->getId(),
                            __('plugins.importexport.common.error.unknownElement', ['param' => $childNode->tagName])
                    );
            }
        }

        if ($errorOccurred) {
            return null;
        }

        $submissionFileDao = app(\PKP\submissionFile\DAO::class);

        $submissionFileId = null;

        if (count($allRevisionIds) < 2) {
            $submissionFileId = (int) $submissionFileDao->insert($submissionFile);
            $submissionFile = Repo::submissionFile()->get($submissionFileId);
        } else {
            $currentFileId = $submissionFile->getData('fileId');

            $allRevisionIds = array_filter(
                    $allRevisionIds,
                    fn($fileId) => $fileId !== $currentFileId
            );
            $allRevisionIds = array_values($allRevisionIds);

            foreach ($allRevisionIds as $i => $fileId) {
                if ($i === 0) {
                    $submissionFile->setData('fileId', $fileId);
                    $submissionFileId = (int) $submissionFileDao->insert($submissionFile);
                    $submissionFile = Repo::submissionFile()->get($submissionFileId);
                } else {
                    $submissionFile->setData('fileId', $fileId);
                    $submissionFileDao->update($submissionFile);
                    $submissionFile = Repo::submissionFile()->get($submissionFileId);
                }
            }

            $submissionFile->setData('fileId', $currentFileId);
            $submissionFileDao->update($submissionFile);
            $submissionFile = Repo::submissionFile()->get($submissionFileId);
        }

        $reviewFileStages = [
            SUBMISSION_FILE_REVIEW_FILE,
            SUBMISSION_FILE_REVIEW_REVISION,
            SUBMISSION_FILE_REVIEW_ATTACHMENT
        ];

        if (in_array($submissionFile->getData('fileStage'), $reviewFileStages, true) && $reviewRound) {
            DB::table('review_round_files')->updateOrInsert(
                    [
                        'submission_file_id' => (int) $submissionFileId,
                        'review_round_id' => (int) $reviewRound->getId(),
                    ],
                    [
                        'submission_id' => (int) $submission->getId(),
                        'stage_id' => (int) $reviewRound->getStageId(),
                    ]
            );
        }

        $deployment->setSubmissionFileDBId(
                $node->getAttribute('id'),
                $submissionFileId
        );

        return $submissionFile;
    }
}
