<?php

/**
 * @file plugins/importexport/fullJournalTransfer/filter/export/ReviewFormNativeXmlFilter.php
 *
 * Copyright (c) 2014-2024 Lepidus Tecnologia
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReviewFormNativeXmlFilter
 *
 * @brief Converts review forms (with their elements) to native XML.
 */

namespace APP\plugins\importexport\fullJournalTransfer\filter\export;

use APP\plugins\importexport\fullJournalTransfer\classes\FullJournalFilterTrait;
use DOMDocument;
use DOMElement;
use PKP\db\DAORegistry;
use PKP\plugins\importexport\native\filter\NativeExportFilter;
use PKP\reviewForm\ReviewForm;

class ReviewFormNativeXmlFilter extends NativeExportFilter
{
    use FullJournalFilterTrait;

    public function __construct($filterGroup)
    {
        $this->setDisplayName('Native XML review form export');
        parent::__construct($filterGroup);
    }

    public function getClassName(): string
    {
        return static::class;
    }

    /**
     * @param ReviewForm[] $reviewForms
     */
    public function &process(&$reviewForms)
    {
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        $deployment = $this->getDeployment();

        $rootNode = $doc->createElementNS($deployment->getNamespace(), 'review_forms');
        foreach ($reviewForms as $reviewForm) {
            $rootNode->appendChild($this->createReviewFormNode($doc, $reviewForm));
        }
        $doc->appendChild($rootNode);
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $rootNode->setAttribute('xsi:schemaLocation', $deployment->getNamespace() . ' ' . $deployment->getSchemaFilename());

        return $doc;
    }

    public function createReviewFormNode(DOMDocument $doc, ReviewForm $reviewForm): DOMElement
    {
        $deployment = $this->getDeployment();

        $node = $doc->createElementNS($deployment->getNamespace(), 'review_form');
        $node->setAttribute('id', (string) $reviewForm->getId());
        $node->setAttribute('seq', (string) (int) $reviewForm->getSequence());
        $node->setAttribute('is_active', $reviewForm->getActive() ? '1' : '0');

        $this->createLocalizedNodes($doc, $node, 'title', $reviewForm->getTitle(null));
        $this->createLocalizedNodes($doc, $node, 'description', $reviewForm->getDescription(null));
        $this->addReviewFormElements($doc, $node, $reviewForm);

        return $node;
    }

    public function addReviewFormElements(DOMDocument $doc, DOMElement $reviewFormNode, ReviewForm $reviewForm): void
    {
        $reviewFormElementDao = DAORegistry::getDAO('ReviewFormElementDAO'); /** @var \PKP\reviewForm\ReviewFormElementDAO $reviewFormElementDao */
        $reviewFormElements = $reviewFormElementDao->getByReviewFormId((int) $reviewForm->getId())->toArray();
        if (empty($reviewFormElements)) {
            return;
        }
        $this->appendExportedNode('review-form-element=>native-xml', $reviewFormElements, $doc, $reviewFormNode);
    }
}
