<?php

/**
 * @file plugins/importexport/fullJournalTransfer/filter/export/ExtendedIssueNativeXmlFilter.php
 *
 * Copyright (c) 2014-2024 Lepidus Tecnologia
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ExtendedIssueNativeXmlFilter
 *
 * @brief Converts issues to native XML; the articles of the issue are exported
 *  with the extended article filter, the custom issue/section orderings are kept.
 */

namespace APP\plugins\importexport\fullJournalTransfer\filter\export;

use APP\facades\Repo;
use APP\issue\Issue;
use APP\plugins\importexport\fullJournalTransfer\classes\FullJournalFilterTrait;
use APP\plugins\importexport\native\filter\IssueNativeXmlFilter;
use APP\plugins\importexport\native\filter\NativeFilterHelper;
use DOMDocument;
use DOMElement;

class ExtendedIssueNativeXmlFilter extends IssueNativeXmlFilter
{
    use FullJournalFilterTrait;

    public function getClassName(): string
    {
        return static::class;
    }

    /**
     * @param Issue[] $issues
     */
    public function &process(&$issues)
    {
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;

        $deployment = $this->getDeployment();
        $journal = $deployment->getContext();

        $rootNode = $doc->createElementNS($deployment->getNamespace(), 'extended_issues');
        foreach ($issues as $issue) {
            $rootNode->appendChild($this->createIssueNode($doc, $issue));
        }

        // Custom ordering of the issues in the archive
        foreach ($issues as $issue) {
            $order = Repo::issue()->dao->getCustomIssueOrder((int) $journal->getId(), (int) $issue->getId());
            if ($order === null) {
                continue;
            }
            $customOrderNode = $doc->createElementNS($deployment->getNamespace(), 'custom_order', (string) $order);
            $customOrderNode->setAttribute('id', (string) $issue->getId());
            $rootNode->appendChild($customOrderNode);
        }

        $doc->appendChild($rootNode);
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $rootNode->setAttribute('xsi:schemaLocation', $deployment->getNamespace() . ' ' . $deployment->getSchemaFilename());

        return $doc;
    }

    /**
     * @copydoc IssueNativeXmlFilter::createIssueNode()
     */
    public function createIssueNode($doc, $issue)
    {
        $deployment = $this->getDeployment();
        $deployment->setIssue($issue);

        echo '  ' . __('plugins.importexport.fullJournal.exportingIssue', ['issue' => $issue->getIssueIdentification()]) . PHP_EOL;

        $issueNode = $doc->createElementNS($deployment->getNamespace(), 'extended_issue');
        $this->addIdentifiers($doc, $issueNode, $issue);

        $issueNode->setAttribute('published', (string) (int) $issue->getPublished());

        $currentIssue = Repo::issue()->getCurrent((int) $issue->getJournalId());
        $isCurrentIssue = $currentIssue !== null && (int) $issue->getId() === (int) $currentIssue->getId();
        $issueNode->setAttribute('current', $isCurrentIssue ? '1' : '0');
        $issueNode->setAttribute('access_status', (string) (int) $issue->getAccessStatus());
        $this->setOptionalAttribute($issueNode, 'url_path', $issue->getData('urlPath'));

        $this->createLocalizedNodes($doc, $issueNode, 'description', $issue->getDescription(null));

        $nativeFilterHelper = new NativeFilterHelper();
        $issueNode->appendChild($nativeFilterHelper->createIssueIdentificationNode($this, $doc, $issue));

        $this->addDates($doc, $issueNode, $issue);
        $this->addSections($doc, $issueNode, $issue);

        $coversNode = $nativeFilterHelper->createIssueCoversNode($this, $doc, $issue);
        if ($coversNode) {
            $issueNode->appendChild($coversNode);
        }

        $this->addIssueGalleys($doc, $issueNode, $issue);
        $this->addArticles($doc, $issueNode, $issue);
        $this->addCustomSectionOrders($doc, $issueNode, $issue);

        return $issueNode;
    }

    /**
     * Dates are exported with their time (the native filter keeps the date only).
     *
     * @copydoc IssueNativeXmlFilter::addDates()
     */
    public function addDates($doc, $issueNode, $issue)
    {
        $deployment = $this->getDeployment();
        $dates = [
            'date_published' => $issue->getDatePublished(),
            'date_notified' => $issue->getDateNotified(),
            'last_modified' => $issue->getLastModified(),
            'open_access_date' => $issue->getOpenAccessDate(),
        ];
        foreach ($dates as $name => $value) {
            if (!$value || str_starts_with($value, '0000-00-00')) {
                continue;
            }
            $issueNode->appendChild($doc->createElementNS($deployment->getNamespace(), $name, date('Y-m-d\TH:i:sP', strtotime($value))));
        }
    }

    /**
     * @copydoc IssueNativeXmlFilter::addArticles()
     */
    public function addArticles($doc, $issueNode, $issue)
    {
        $deployment = $this->getFullJournalDeployment();

        $submissions = Repo::submission()
            ->getCollector()
            ->filterByContextIds([(int) $issue->getJournalId()])
            ->filterByIssueIds([(int) $issue->getId()])
            ->getMany();

        $submissionsArray = [];
        foreach ($submissions as $submission) {
            // the collector matches any version; export the submission with the issue of its current version only
            if ($deployment->getSubmissionExportIssueId($submission) !== (int) $issue->getId()) {
                continue;
            }
            if ($error = $deployment->getSubmissionValidationError($submission)) {
                echo '  ' . __('plugins.importexport.fullJournal.warning.submissionSkipped', [
                    'submissionId' => $submission->getId(),
                    'reason' => $error,
                ]) . PHP_EOL;
                continue;
            }
            $submissionsArray[] = $submission;
        }

        $filter = $this->getSubFilter('extended-article=>native-xml');
        $filter->setIncludeSubmissionsNode(true);
        $articlesDoc = $filter->execute($submissionsArray, true);
        if ($articlesDoc instanceof DOMDocument && $articlesDoc->documentElement instanceof DOMElement) {
            $clone = $doc->importNode($articlesDoc->documentElement, true);
            $issueNode->appendChild($clone);
        } else {
            // the schema requires the articles element
            $issueNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'extended_articles'));
        }
    }

    /**
     * Custom ordering of the sections within the issue (table of contents).
     */
    public function addCustomSectionOrders($doc, $issueNode, $issue): void
    {
        $deployment = $this->getDeployment();
        $journal = $deployment->getContext();
        if (!Repo::section()->customSectionOrderingExists((int) $issue->getId())) {
            return;
        }
        foreach (Repo::section()->getByIssueId((int) $issue->getId()) as $section) {
            $order = Repo::section()->getCustomSectionOrder((int) $issue->getId(), (int) $section->getId());
            if ($order === null) {
                continue;
            }
            $node = $doc->createElementNS($deployment->getNamespace(), 'custom_section_order', (string) $order);
            $node->setAttribute('section_ref', (string) $section->getAbbrev($journal->getPrimaryLocale()));
            $issueNode->appendChild($node);
        }
    }
}
