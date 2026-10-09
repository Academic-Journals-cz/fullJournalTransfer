<?php

/**
 * @file plugins/importexport/fullJournalTransfer/filter/import/NativeXmlExtendedIssueFilter.php
 *
 * Copyright (c) 2014-2024 Lepidus Tecnologia
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NativeXmlExtendedIssueFilter
 *
 * @brief Imports an issue; its articles are imported with the extended article
 *  filter, the custom section ordering and the issue/galley IDs are recorded.
 */

namespace APP\plugins\importexport\fullJournalTransfer\filter\import;

use APP\core\Application;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\issue\IssueGalley;
use APP\plugins\importexport\fullJournalTransfer\classes\FullJournalFilterTrait;
use APP\plugins\importexport\native\filter\NativeFilterHelper;
use APP\plugins\importexport\native\filter\NativeXmlIssueFilter;
use DOMElement;
use Illuminate\Support\Facades\DB;

class NativeXmlExtendedIssueFilter extends NativeXmlIssueFilter
{
    use FullJournalFilterTrait;

    public function getClassName(): string
    {
        return static::class;
    }

    public function getPluralElementName()
    {
        return 'extended_issues';
    }

    public function getSingularElementName()
    {
        return 'extended_issue';
    }

    /**
     * @copydoc NativeXmlIssueFilter::handleElement()
     */
    public function handleElement($node)
    {
        $deployment = $this->getFullJournalDeployment();

        $oldIssueId = null;
        foreach ($this->childElements($node, 'id') as $idNode) {
            if ($idNode->getAttribute('type') === 'internal') {
                $oldIssueId = trim($idNode->textContent);
            }
        }

        $issue = parent::handleElement($node);

        if ($issue instanceof Issue) {
            if ($oldIssueId !== null && $oldIssueId !== '') {
                $deployment->setIssueDBId($oldIssueId, (int) $issue->getId());
            }
            // the DAO stamps last_modified on every update; restore the exported value
            $lastModifiedNode = $this->firstChildElement($node, 'last_modified');
            $lastModified = $lastModifiedNode ? $this->parseDateTime($lastModifiedNode->textContent) : null;
            if ($lastModified !== null) {
                DB::table('issues')->where('issue_id', (int) $issue->getId())->update(['last_modified' => $lastModified]);
            }
            $deployment->incrementCounter('issues');
            echo '  ' . __('plugins.importexport.fullJournal.importedIssue', ['issue' => $issue->getIssueIdentification()]) . PHP_EOL;
        }

        return $issue;
    }

    /**
     * DOIs are looked up / created within the imported journal.
     *
     * @copydoc NativeXmlIssueFilter::parseIdentifier()
     */
    public function parseIdentifier($element, $issue)
    {
        if ($element->getAttribute('type') === 'doi' && $element->getAttribute('advice') === 'update') {
            $issue->setData('doiId', $this->findOrCreateDoiId($element->textContent));
            return;
        }
        parent::parseIdentifier($element, $issue);
    }

    /**
     * Every exported issue is created as a new issue, even when an issue with
     * the same identification exists (the native filter would merge the
     * articles into the existing issue).
     *
     * @copydoc NativeXmlIssueFilter::_issueExists()
     */
    public function _issueExists($node)
    {
        return null;
    }

    /**
     * @copydoc NativeXmlIssueFilter::handleChildElement()
     */
    public function handleChildElement($n, $issue, $processOnlyChildren)
    {
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();

        $localizedSetterMappings = $this->_getLocalizedIssueSetterMappings();
        $dateSetterMappings = $this->_getDateIssueSetterMappings();

        if (isset($localizedSetterMappings[$n->tagName])) {
            if (!$processOnlyChildren) {
                $setterFunction = $localizedSetterMappings[$n->tagName];
                [$locale, $value] = $this->parseLocalizedContent($n);
                $issue->$setterFunction($value, $locale ?: $context->getPrimaryLocale());
            }
            return;
        }

        if (isset($dateSetterMappings[$n->tagName])) {
            if (!$processOnlyChildren) {
                $setterFunction = $dateSetterMappings[$n->tagName];
                $issue->$setterFunction($this->parseDateTime($n->textContent));
            }
            return;
        }

        switch ($n->tagName) {
            case 'id':
                if (!$processOnlyChildren) {
                    $this->parseIdentifier($n, $issue);
                }
                break;
            case 'extended_articles':
                $this->parseArticles($n, $issue);
                break;
            case 'issue_galleys':
                if (!$processOnlyChildren) {
                    $this->parseIssueGalleys($n, $issue);
                }
                break;
            case 'sections':
                $this->parseSections($n, $issue);
                break;
            case 'covers':
                if (!$processOnlyChildren) {
                    $nativeFilterHelper = new NativeFilterHelper();
                    $nativeFilterHelper->parseIssueCovers($this, $n, $issue);
                }
                break;
            case 'issue_identification':
                if (!$processOnlyChildren) {
                    $this->parseIssueIdentification($n, $issue);
                }
                break;
            case 'custom_section_order':
                $this->parseCustomSectionOrder($n, $issue);
                break;
            default:
                $deployment->addWarning(Application::ASSOC_TYPE_ISSUE, $issue->getId(), __('plugins.importexport.common.error.unknownElement', ['param' => $n->tagName]));
        }
    }

    /**
     * @copydoc NativeXmlIssueFilter::parseArticles()
     */
    public function parseArticles($node, $issue)
    {
        $deployment = $this->getDeployment();
        foreach ($this->childElements($node) as $childNode) {
            if ($childNode->tagName === 'extended_article') {
                $this->importNodeWith('native-xml=>extended-article', $childNode);
            } else {
                $deployment->addWarning(Application::ASSOC_TYPE_ISSUE, $issue->getId(), __('plugins.importexport.common.error.unknownElement', ['param' => $childNode->tagName]));
            }
        }
    }

    /**
     * @copydoc NativeXmlIssueFilter::parseIssueGalley()
     */
    public function parseIssueGalley($n, $issue)
    {
        $deployment = $this->getFullJournalDeployment();
        $importedObjects = parent::parseIssueGalley($n, $issue);

        $issueGalley = is_array($importedObjects) ? reset($importedObjects) : null;
        if ($issueGalley instanceof IssueGalley) {
            foreach ($this->childElements($n, 'id') as $idNode) {
                if ($idNode->getAttribute('type') === 'internal') {
                    $deployment->setIssueGalleyDBId(trim($idNode->textContent), (int) $issueGalley->getId());
                }
            }
            $deployment->incrementCounter('issue galleys');
        }

        return $importedObjects;
    }

    /**
     * Custom ordering of the sections within the issue.
     */
    public function parseCustomSectionOrder(DOMElement $node, Issue $issue): void
    {
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();

        $sectionRef = $node->getAttribute('section_ref');
        if ($sectionRef === '') {
            return;
        }
        $section = Repo::section()->getCollector()
            ->filterByContextIds([(int) $context->getId()])
            ->filterByAbbrevs([$sectionRef])
            ->getMany()
            ->first();
        if (!$section) {
            return;
        }
        Repo::section()->upsertCustomSectionOrder((int) $issue->getId(), (int) $section->getId(), (int) $node->textContent);
    }
}
