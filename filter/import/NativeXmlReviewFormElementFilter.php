<?php

/**
 * Copyright (c) 2019-2023 Lepidus Tecnologia
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 */
namespace APP\plugins\importexport\fullJournalTransfer\filter\import;

use PKP\plugins\importexport\native\filter\NativeImportFilter;
use PKP\db\DAORegistry;

class NativeXmlReviewFormElementFilter extends NativeImportFilter
{
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

    public function handleElement($node)
    {
        $deployment = $this->getDeployment();
        $reviewForm = $deployment->getReviewForm();

        $reviewFormElementDAO = DAORegistry::getDAO('ReviewFormElementDAO');
        $reviewFormElement = $reviewFormElementDAO->newDataObject();

        $reviewFormElement->setReviewFormId($reviewForm->getId());

        $reviewFormElement->setSequence($node->getAttribute('seq'));
        $reviewFormElement->setElementType($node->getAttribute('element_type'));
        $reviewFormElement->setRequired($node->getAttribute('required'));
        $reviewFormElement->setIncluded($node->getAttribute('included'));

        for ($n = $node->firstChild; $n !== null; $n = $n->nextSibling) {
            if (is_a($n, 'DOMElement')) {
                switch ($n->tagName) {
                    case 'question':
                        $locale = $n->getAttribute('locale');
                        $reviewFormElement->setQuestion($n->textContent, $locale);
                        break;
                    case 'description':
                        $locale = $n->getAttribute('locale');
                        $reviewFormElement->setDescription($n->textContent, $locale);
                        break;
                    case 'possible_responses':
                        $locale = $n->getAttribute('locale');
                        $possibleResponses = [];
                        $possibleResponseNodeList = $n->getElementsByTagNameNS(
                            $deployment->getNamespace(),
                            'possible_response'
                        );
                        foreach ($possibleResponseNodeList as $possibleResponseNode) {
                            $possibleResponses[] = $possibleResponseNode->textContent;
                        }
                        $reviewFormElement->setPossibleResponses($possibleResponses, $locale);
                        break;
                }
            }
        }

        $reviewFormElementDAO->insertObject($reviewFormElement);
        $deployment->setReviewFormElementDBId($node->getAttribute('id'), $reviewFormElement->getId());
        return $reviewFormElement;
    }
}
