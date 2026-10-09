<?php

/**
 * @file plugins/importexport/fullJournalTransfer/filter/import/NativeXmlReviewFormElementFilter.php
 *
 * Copyright (c) 2019-2024 Lepidus Tecnologia
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NativeXmlReviewFormElementFilter
 *
 * @brief Imports review form elements of the review form being imported.
 */

namespace APP\plugins\importexport\fullJournalTransfer\filter\import;

use APP\plugins\importexport\fullJournalTransfer\classes\FullJournalFilterTrait;
use DOMElement;
use PKP\db\DAORegistry;
use PKP\plugins\importexport\native\filter\NativeImportFilter;

class NativeXmlReviewFormElementFilter extends NativeImportFilter
{
    use FullJournalFilterTrait;

    public function __construct($filterGroup)
    {
        $this->setDisplayName('Native XML review form element import');
        parent::__construct($filterGroup);
    }

    public function getPluralElementName()
    {
        return 'review_form_elements';
    }

    public function getSingularElementName()
    {
        return 'review_form_element';
    }

    public function getClassName(): string
    {
        return static::class;
    }

    /**
     * @param DOMElement $node
     */
    public function handleElement($node)
    {
        $deployment = $this->getFullJournalDeployment();
        $context = $deployment->getContext();
        $reviewForm = $deployment->getReviewForm();
        if (!$reviewForm) {
            throw new \Exception('review_form_element found outside of a review_form');
        }

        $reviewFormElementDao = DAORegistry::getDAO('ReviewFormElementDAO'); /** @var \PKP\reviewForm\ReviewFormElementDAO $reviewFormElementDao */
        $reviewFormElement = $reviewFormElementDao->newDataObject();
        $reviewFormElement->setReviewFormId((int) $reviewForm->getId());
        $reviewFormElement->setSequence((float) $node->getAttribute('seq'));
        $reviewFormElement->setElementType((int) $node->getAttribute('element_type'));
        $reviewFormElement->setRequired((int) $node->getAttribute('required'));
        $reviewFormElement->setIncluded((int) $node->getAttribute('included'));

        foreach ($this->childElements($node) as $childNode) {
            switch ($childNode->tagName) {
                case 'question':
                    [$locale, $value] = $this->parseLocalizedContent($childNode);
                    $reviewFormElement->setQuestion($value, $locale ?: $context->getPrimaryLocale());
                    break;
                case 'description':
                    [$locale, $value] = $this->parseLocalizedContent($childNode);
                    $reviewFormElement->setDescription($value, $locale ?: $context->getPrimaryLocale());
                    break;
                case 'possible_responses':
                    $locale = $childNode->getAttribute('locale') ?: $context->getPrimaryLocale();
                    $possibleResponses = [];
                    foreach ($this->childElements($childNode, 'possible_response') as $responseNode) {
                        $possibleResponses[] = $responseNode->textContent;
                    }
                    $reviewFormElement->setPossibleResponses($possibleResponses, $locale);
                    break;
            }
        }

        $reviewFormElementDao->insertObject($reviewFormElement);
        $deployment->setReviewFormElementDBId($node->getAttribute('id'), (int) $reviewFormElement->getId());

        return $reviewFormElement;
    }
}
