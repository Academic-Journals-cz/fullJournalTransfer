<?php

namespace APP\plugins\importexport\fullJournalTransfer\filter\export;

use APP\plugins\importexport\native\filter\IssueNativeXmlFilter;
use APP\plugins\importexport\native\filter\NativeFilterHelper;
use DOMDocument;
use DOMElement;
use PKP\db\DAORegistry;
use APP\facades\Repo;

class ExtendedIssueNativeXmlFilter extends IssueNativeXmlFilter {

    public function getClassName(): string {
        return static::class;
    }


    public function &process(&$issues) {
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;

        $deployment = $this->getDeployment();
        $journal = $deployment->getContext();

        if (count($issues) === 1) {
            $rootNode = $this->createIssueNode($doc, $issues[0]);
        } else {
            $rootNode = $doc->createElementNS($deployment->getNamespace(), 'extended_issues');
            foreach ($issues as $issue) {
                $rootNode->appendChild($this->createIssueNode($doc, $issue));
            }
        }

        foreach ($issues as $issue) {
            $customOrderNode = $this->createCustomOrderNode($doc, $issue, $journal);
            if ($customOrderNode) {
                $rootNode->appendChild($customOrderNode);
            }
        }

        $doc->appendChild($rootNode);
        $rootNode->setAttributeNS(
                'http://www.w3.org/2000/xmlns/',
                'xmlns:xsi',
                'http://www.w3.org/2001/XMLSchema-instance'
        );
        $rootNode->setAttribute(
                'xsi:schemaLocation',
                $deployment->getNamespace() . ' ' . $deployment->getSchemaFilename()
        );

        return $doc;
    }

    public function createIssueNode($doc, $issue) {
        $deployment = $this->getDeployment();
        $deployment->setIssue($issue);
        $journal = $deployment->getContext();

        $issueNode = $doc->createElementNS($deployment->getNamespace(), 'extended_issue');
        $this->addIdentifiers($doc, $issueNode, $issue);

        $published = $issue->getPublished() ? 1 : 0;
        $issueNode->setAttribute('published', (string) $published);
        
        $issueNode->setAttribute('published', $published);
        $issueNode->setAttribute('access_status', $issue->getAccessStatus());
        $issueNode->setAttribute('url_path', $issue->getData('urlPath'));

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

        return $issueNode;
    }
    
    public function addArticles($doc, $issueNode, $issue) {
        $filterDao = DAORegistry::getDAO('FilterDAO');

        $nativeExportFilters = $filterDao->getObjectsByGroup('extended-article=>native-xml');
        $nativeExportFilters = is_array($nativeExportFilters) ? $nativeExportFilters : $nativeExportFilters->toArray();

        if (count($nativeExportFilters) !== 1) {
            throw new \Exception(
                            sprintf(
                                    'Expected exactly 1 filter for group "%s", got %d.',
                                    'extended-article=>native-xml',
                                    count($nativeExportFilters)
                            )
                    );
        }

        $exportFilter = array_shift($nativeExportFilters);
        $exportFilter->setOpts($this->opts);
        $exportFilter->setDeployment($this->getDeployment());
        $exportFilter->setIncludeSubmissionsNode(true);

        echo __('plugins.importexport.fullJournal.exportingArticles') . "\n";

        $submissions = Repo::submission()
            ->getCollector()
            ->filterByContextIds([$issue->getJournalId()])
            ->filterByIssueIds([$issue->getId()])
            ->getMany()
            ->toArray();

        $articlesDoc = $exportFilter->execute($submissions);
        
        if ($articlesDoc->documentElement instanceof \DOMElement) {
            $clone = $doc->importNode($articlesDoc->documentElement, true);
            $issueNode->appendChild($clone);
        }
    }

    public function createCustomOrderNode($doc, $issue, $journal) {
        $deployment = $this->getDeployment();

        $order = null;

        if (method_exists($issue, 'getSequence')) {
            $order = $issue->getSequence();
        } elseif (method_exists($issue, 'getData')) {
            $order = $issue->getData('seq') ?? $issue->getData('sequence');
        }

        if ($order === null) {
            return null;
        }

        $customOrderNode = $doc->createElementNS(
                $deployment->getNamespace(),
                'custom_order',
                (string) $order
        );
        $customOrderNode->setAttribute('id', (string) $issue->getId());

        return $customOrderNode;
    }
}
