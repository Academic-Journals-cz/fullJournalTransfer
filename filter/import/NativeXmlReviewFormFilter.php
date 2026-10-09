<?php

/**
 * @file plugins/importexport/fullJournalTransfer/filter/import/NativeXmlReviewFormFilter.php
 *
 * Copyright (c) 2014-2024 Lepidus Tecnologia
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NativeXmlReviewFormFilter
 *
 * @brief Imports review forms with their elements.
 */

namespace APP\plugins\importexport\fullJournalTransfer\filter\import;

use APP\core\Application;
use APP\plugins\importexport\fullJournalTransfer\classes\FullJournalFilterTrait;
use DOMElement;
use PKP\db\DAORegistry;
use PKP\plugins\importexport\native\filter\NativeImportFilter;

class NativeXmlReviewFormFilter extends NativeImportFilter
{
    use FullJournalFilterTrait;

    public function __construct($filterGroup)
    {
        $this->setDisplayName('Native XML review form import');
        parent::__construct($filterGroup);
    }

    public function getPluralElementName()
    {
        return 'review_forms';
    }

    public function getSingularElementName()
    {
        return 'review_form';
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

        $reviewFormDao = DAORegistry::getDAO('ReviewFormDAO'); /** @var \PKP\reviewForm\ReviewFormDAO $reviewFormDao */
        $reviewForm = $reviewFormDao->newDataObject();
        $reviewForm->setAssocType(Application::ASSOC_TYPE_JOURNAL);
        $reviewForm->setAssocId((int) $context->getId());
        $reviewForm->setActive((int) $node->getAttribute('is_active'));
        $reviewForm->setSequence((float) $node->getAttribute('seq'));

        $reviewFormElementsNode = null;
        foreach ($this->childElements($node) as $childNode) {
            switch ($childNode->tagName) {
                case 'title':
                    [$locale, $value] = $this->parseLocalizedContent($childNode);
                    $reviewForm->setTitle($value, $locale ?: $context->getPrimaryLocale());
                    break;
                case 'description':
                    [$locale, $value] = $this->parseLocalizedContent($childNode);
                    $reviewForm->setDescription($value, $locale ?: $context->getPrimaryLocale());
                    break;
                case 'review_form_elements':
                    $reviewFormElementsNode = $childNode;
                    break;
            }
        }

        $reviewFormDao->insertObject($reviewForm);
        $deployment->setReviewForm($reviewForm);
        $deployment->setReviewFormDBId($node->getAttribute('id'), (int) $reviewForm->getId());
        $deployment->incrementCounter('review forms');

        if ($reviewFormElementsNode) {
            foreach ($this->childElements($reviewFormElementsNode, 'review_form_element') as $elementNode) {
                $this->importNodeWith('native-xml=>review-form-element', $elementNode);
            }
        }
        $deployment->setReviewForm(null);

        return $reviewForm;
    }
}
