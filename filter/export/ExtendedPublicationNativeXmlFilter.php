<?php

/**
 * @file plugins/importexport/fullJournalTransfer/filter/export/ExtendedPublicationNativeXmlFilter.php
 *
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ExtendedPublicationNativeXmlFilter
 *
 * @brief Native publication export that
 *  - records the ID of the issue the publication (version) is assigned to, so
 *    every version keeps its own issue on import; dangling issue references
 *    are dropped instead of breaking the export,
 *  - exports the contributors and galleys without the per-element schema
 *    validation of the native filters (which aborts the whole export with a
 *    generic "failed to parse authors" message on the first irregular record)
 *    and repairs the irregularities found in journals migrated from older OJS
 *    versions: missing given names, missing or foreign author user groups,
 *    affiliations without a name, non-integer sequences, galleys without a
 *    label and galleys whose file no longer exists. Every repair is reported
 *    as a warning.
 */

namespace APP\plugins\importexport\fullJournalTransfer\filter\export;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\importexport\fullJournalTransfer\classes\FullJournalFilterTrait;
use APP\plugins\importexport\native\filter\PublicationNativeXmlFilter;
use DOMDocument;
use DOMElement;
use Exception;
use PKP\security\Role;
use PKP\userGroup\UserGroup;

class ExtendedPublicationNativeXmlFilter extends PublicationNativeXmlFilter
{
    use FullJournalFilterTrait;

    /** @var ?int cached ID of the author user group used for authors without a valid group */
    private ?int $fallbackAuthorUserGroupId = null;

    public function getClassName(): string
    {
        return static::class;
    }

    /**
     * @copydoc PublicationNativeXmlFilter::createEntityNode()
     */
    public function createEntityNode($doc, $entity)
    {
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();

        $issue = null;
        if ($issueId = (int) $entity->getData('issueId')) {
            $issue = Repo::issue()->get($issueId);
            if (!$issue || (int) $issue->getJournalId() !== (int) $context->getId()) {
                // the issue does not exist (any more); export the version as not assigned to an issue
                $issue = null;
                $entity = clone $entity;
                $entity->setData('issueId', null);
            }
        }

        $entityNode = parent::createEntityNode($doc, $entity);

        if ($issue) {
            // last element of the publication sequence (see native.xsd)
            $entityNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'issueId', (string) $issue->getId()));
        }

        return $entityNode;
    }

    //
    // Contributors
    //
    /**
     * @copydoc PKPPublicationNativeXmlFilter::addAuthors()
     */
    public function addAuthors($doc, $entityNode, $entity)
    {
        $deployment = $this->getDeployment();

        $authors = $entity->getData('authors');
        $authors = is_array($authors) ? $authors : ($authors ? $authors->toArray() : []);
        foreach ($authors as $author) {
            $this->repairAuthor($author, $entity);
        }

        $filter = $this->getSubFilter('author=>native-xml');
        $authorsDoc = $filter->execute($authors, true);
        if (!$authorsDoc instanceof DOMDocument || !$authorsDoc->documentElement instanceof DOMElement) {
            $deployment->addError(Application::ASSOC_TYPE_PUBLICATION, $entity->getId(), __('plugins.importexport.author.exportFailed'));
            throw new Exception(__('plugins.importexport.author.exportFailed'));
        }

        $this->repairAuthorNodes($authorsDoc->documentElement, $entity);
        $entityNode->appendChild($doc->importNode($authorsDoc->documentElement, true));
    }

    /**
     * Fix (in memory only) the author data the native export cannot handle.
     */
    protected function repairAuthor($author, $publication): void
    {
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $locale = $publication->getData('locale') ?: $context->getPrimaryLocale();

        // user group: must exist and belong to the journal
        $userGroup = $author->getUserGroupId() ? UserGroup::find((int) $author->getUserGroupId()) : null;
        if (!$userGroup || (int) $userGroup->contextId !== (int) $context->getId()) {
            $fallbackUserGroupId = $this->getFallbackAuthorUserGroupId();
            if ($fallbackUserGroupId) {
                $this->warn($publication, 'contributor ' . $author->getId() . ' (' . $author->getFullName() . ') has no valid user group; the author group of the journal was used');
                $author->setUserGroupId($fallbackUserGroupId);
            }
        }

        // given name: required by the schema
        if (empty(array_filter((array) $author->getGivenName(null), 'strlen'))) {
            $familyNames = array_filter((array) $author->getFamilyName(null), 'strlen');
            if (!empty($familyNames)) {
                $this->warn($publication, 'contributor ' . $author->getId() . ' has no given name; the family name was exported as the given name');
                $author->setGivenName($familyNames, null);
                $author->setFamilyName([], null);
            } else {
                $this->warn($publication, 'contributor ' . $author->getId() . ' has no name; "-" was exported as the given name');
                $author->setGivenName('-', $locale);
            }
        }

        // sequence: an integer in the schema
        $author->setSequence((int) round((float) $author->getSequence()));
    }

    /**
     * Remove the affiliation elements without a name (the schema requires one).
     */
    protected function repairAuthorNodes(DOMElement $authorsNode, $publication): void
    {
        foreach ($this->childElements($authorsNode, 'author') as $authorNode) {
            $remove = [];
            foreach ($this->childElements($authorNode) as $child) {
                if (in_array($child->tagName, ['affiliation', 'rorAffiliation']) && !$this->firstChildElement($child, 'name')) {
                    $remove[] = $child;
                }
            }
            foreach ($remove as $child) {
                $authorNode->removeChild($child);
                $this->warn($publication, 'contributor ' . $authorNode->getAttribute('id') . ' has an affiliation without a name; it was skipped');
            }
        }
    }

    protected function getFallbackAuthorUserGroupId(): ?int
    {
        if ($this->fallbackAuthorUserGroupId === null) {
            $contextId = (int) $this->getDeployment()->getContext()->getId();
            $userGroup = UserGroup::withContextIds([$contextId])->withRoleIds([Role::ROLE_ID_AUTHOR])->isDefault(true)->first()
                ?? UserGroup::withContextIds([$contextId])->withRoleIds([Role::ROLE_ID_AUTHOR])->first();
            $this->fallbackAuthorUserGroupId = $userGroup ? (int) $userGroup->id : 0;
        }
        return $this->fallbackAuthorUserGroupId ?: null;
    }

    //
    // Galleys
    //
    /**
     * @copydoc PKPPublicationNativeXmlFilter::addRepresentations()
     */
    public function addRepresentations($doc, $entityNode, $entity)
    {
        $deployment = $this->getDeployment();
        $filter = $this->getSubFilter($this->getRepresentationExportFilterGroupName());

        $representationDao = Application::getRepresentationDAO();
        foreach ($representationDao->getByPublicationId((int) $entity->getId()) as $representation) {
            $submissionFileId = (int) $representation->getData('submissionFileId');
            $submissionFile = $submissionFileId ? Repo::submissionFile()->get($submissionFileId) : null;
            if ($submissionFileId && !$submissionFile) {
                $this->warn($entity, 'galley ' . $representation->getId() . ' refers to the submission file ' . $submissionFileId . ', which does not exist; the galley was exported without a file');
                $representation = clone $representation;
                $representation->setData('submissionFileId', null);
            }

            $representationDoc = $filter->execute($representation, true);
            if (!$representationDoc instanceof DOMDocument || !$representationDoc->documentElement instanceof DOMElement) {
                $deployment->addError(Application::ASSOC_TYPE_PUBLICATION, $entity->getId(), 'The galley ' . $representation->getId() . ' could not be exported.');
                throw new Exception('The galley ' . $representation->getId() . ' could not be exported.');
            }
            $representationNode = $representationDoc->documentElement;

            // the schema requires a label
            if (!$this->firstChildElement($representationNode, 'name')) {
                $label = 'PDF';
                if ($submissionFile) {
                    $extension = pathinfo((string) $submissionFile->getData('path'), PATHINFO_EXTENSION);
                    $label = $extension !== '' ? strtoupper($extension) : $label;
                }
                $nameNode = $representationDoc->createElementNS($deployment->getNamespace(), 'name', htmlspecialchars($label, ENT_COMPAT, 'UTF-8'));
                $nameNode->setAttribute('locale', $representation->getData('locale') ?: ($entity->getData('locale') ?: $deployment->getContext()->getPrimaryLocale()));
                $seqNode = $this->firstChildElement($representationNode, 'seq');
                $representationNode->insertBefore($nameNode, $seqNode);
                $this->warn($entity, 'galley ' . $representation->getId() . ' has no label; "' . $label . '" was used');
            }

            $entityNode->appendChild($doc->importNode($representationNode, true));
        }
    }

    protected function warn($publication, string $message): void
    {
        $deployment = $this->getDeployment();
        $submission = $deployment->getSubmission();
        $prefix = $submission ? 'submission ' . $submission->getId() . ': ' : '';
        $deployment->addWarning(Application::ASSOC_TYPE_PUBLICATION, (int) $publication->getId(), $prefix . $message);
    }
}
