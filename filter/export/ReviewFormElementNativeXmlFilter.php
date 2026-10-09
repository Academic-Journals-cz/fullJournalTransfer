<?php

/**
 * @file plugins/importexport/fullJournalTransfer/filter/export/ReviewFormElementNativeXmlFilter.php
 *
 * Copyright (c) 2019-2024 Lepidus Tecnologia
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReviewFormElementNativeXmlFilter
 *
 * @brief Converts review form elements to native XML.
 */

namespace APP\plugins\importexport\fullJournalTransfer\filter\export;

use DOMDocument;
use DOMElement;
use PKP\plugins\importexport\native\filter\NativeExportFilter;
use PKP\reviewForm\ReviewFormElement;

class ReviewFormElementNativeXmlFilter extends NativeExportFilter
{
    public function __construct($filterGroup)
    {
        $this->setDisplayName('Native XML review form element export');
        parent::__construct($filterGroup);
    }

    public function getClassName(): string
    {
        return static::class;
    }

    /**
     * @param ReviewFormElement[] $reviewFormElements
     */
    public function &process(&$reviewFormElements)
    {
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        $deployment = $this->getDeployment();

        $rootNode = $doc->createElementNS($deployment->getNamespace(), 'review_form_elements');
        foreach ($reviewFormElements as $reviewFormElement) {
            $rootNode->appendChild($this->createReviewFormElementNode($doc, $reviewFormElement));
        }
        $doc->appendChild($rootNode);
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $rootNode->setAttribute('xsi:schemaLocation', $deployment->getNamespace() . ' ' . $deployment->getSchemaFilename());

        return $doc;
    }

    public function createReviewFormElementNode(DOMDocument $doc, ReviewFormElement $reviewFormElement): DOMElement
    {
        $deployment = $this->getDeployment();

        $node = $doc->createElementNS($deployment->getNamespace(), 'review_form_element');
        $node->setAttribute('id', (string) $reviewFormElement->getId());
        $node->setAttribute('seq', (string) (int) $reviewFormElement->getSequence());
        $node->setAttribute('element_type', (string) (int) $reviewFormElement->getElementType());
        $node->setAttribute('required', $reviewFormElement->getRequired() ? '1' : '0');
        $node->setAttribute('included', $reviewFormElement->getIncluded() ? '1' : '0');

        $this->createLocalizedNodes($doc, $node, 'question', $reviewFormElement->getQuestion(null));
        $this->createLocalizedNodes($doc, $node, 'description', $reviewFormElement->getDescription(null));

        $possibleResponses = $reviewFormElement->getPossibleResponses(null);
        if (is_array($possibleResponses)) {
            foreach ($possibleResponses as $locale => $values) {
                if (!is_array($values) || empty($values)) {
                    continue;
                }
                $responsesNode = $doc->createElementNS($deployment->getNamespace(), 'possible_responses');
                $responsesNode->setAttribute('locale', $locale);
                foreach ($values as $possibleResponse) {
                    $responsesNode->appendChild($doc->createElementNS(
                        $deployment->getNamespace(),
                        'possible_response',
                        htmlspecialchars((string) $possibleResponse, ENT_COMPAT, 'UTF-8')
                    ));
                }
                $node->appendChild($responsesNode);
            }
        }

        return $node;
    }
}
