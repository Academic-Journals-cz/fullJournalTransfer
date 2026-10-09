<?php

/**
 * @file plugins/importexport/fullJournalTransfer/filter/import/NativeXmlExtendedPublicationFilter.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Copyright (c) 2014-2024 Lepidus Tecnologia
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NativeXmlExtendedPublicationFilter
 *
 * @brief Native publication import that
 *  - assigns the publication (version) to the imported issue by the exported
 *    issue ID (the native filter can only use the issue being imported or an
 *    issue identification, and fails for articles that are not in an issue),
 *  - records the IDs of the imported galleys (needed to remap the usage
 *    statistics).
 */

namespace APP\plugins\importexport\fullJournalTransfer\filter\import;

use APP\plugins\importexport\fullJournalTransfer\classes\FullJournalFilterTrait;
use APP\plugins\importexport\native\filter\NativeXmlPublicationFilter;
use PKP\galley\Galley;

class NativeXmlExtendedPublicationFilter extends NativeXmlPublicationFilter
{
    use FullJournalFilterTrait;

    public function getClassName(): string
    {
        return static::class;
    }

    /**
     * Record how the publication IDs map (publication_categories, ...).
     *
     * @copydoc NativeXmlPKPPublicationFilter::handleElement()
     */
    public function handleElement($node)
    {
        $publication = parent::handleElement($node);
        if ($publication) {
            foreach ($this->childElements($node, 'id') as $idNode) {
                if ($idNode->getAttribute('type') === 'internal') {
                    $this->getFullJournalDeployment()->setMappedId('publication', trim($idNode->textContent), (int) $publication->getId());
                }
            }
        }
        return $publication;
    }

    /**
     * @copydoc NativeXmlPublicationFilter::populatePublishedPublication()
     */
    public function populatePublishedPublication($publication, $node)
    {
        $deployment = $this->getFullJournalDeployment();

        // 1. the issue of this version, exported by ID
        $issueIdNode = $this->firstChildElement($node, 'issueId');
        if ($issueIdNode) {
            $issueId = $deployment->getIssueDBId(trim($issueIdNode->textContent));
            if ($issueId) {
                $publication->setData('issueId', $issueId);
                return $publication;
            }
        }

        // 2. the issue being imported
        $issue = $deployment->getIssue();
        if ($issue) {
            $publication->setData('issueId', $issue->getId());
            return $publication;
        }

        // 3. an issue identification (only present for versions whose issue could not be exported by ID)
        if ($this->firstChildElement($node, 'issue_identification')) {
            return parent::populatePublishedPublication($publication, $node);
        }

        // 4. not assigned to an issue (article in the workflow, declined, ...)
        return $publication;
    }

    /**
     * @copydoc NativeXmlPublicationFilter::handleChildElement()
     */
    public function handleChildElement($n, $publication)
    {
        switch ($n->tagName) {
            case 'issueId':
                // handled in populatePublishedPublication()
                return;
            case 'article_galley':
                $this->parseExtendedArticleGalley($n, $publication);
                return;
            default:
                parent::handleChildElement($n, $publication);
        }
    }

    /**
     * DOIs are looked up / created within the imported journal.
     *
     * @copydoc NativeXmlPKPPublicationFilter::parseIdentifier()
     */
    public function parseIdentifier($element, $publication)
    {
        if ($element->getAttribute('type') === 'doi' && $element->getAttribute('advice') === 'update') {
            $publication->setData('doiId', $this->findOrCreateDoiId($element->textContent));
            return;
        }
        parent::parseIdentifier($element, $publication);
    }

    /**
     * @copydoc NativeXmlPublicationFilter::getImportFilter()
     */
    public function getImportFilter($elementName)
    {
        if ($elementName === 'article_galley') {
            return $this->getSubFilter('native-xml=>extended-article-galley');
        }
        return parent::getImportFilter($elementName);
    }

    /**
     * Import a galley and record its ID.
     */
    protected function parseExtendedArticleGalley($n, $publication): void
    {
        $deployment = $this->getFullJournalDeployment();
        $importedObjects = $this->parseArticleGalley($n, $publication);
        $galley = is_array($importedObjects) ? reset($importedObjects) : null;
        if ($galley instanceof Galley) {
            foreach ($this->childElements($n, 'id') as $idNode) {
                if ($idNode->getAttribute('type') === 'internal') {
                    $deployment->setRepresentationDBId(trim($idNode->textContent), (int) $galley->getId());
                }
            }
            $deployment->incrementCounter('galleys');
        }
    }
}
