<?php

/**
 * @file plugins/importexport/fullJournalTransfer/filter/import/NativeXmlExtendedArticleGalleyFilter.php
 *
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NativeXmlExtendedArticleGalleyFilter
 *
 * @brief Native galley import whose DOIs are looked up / created within the
 *  imported journal (the native filter looks them up site-wide).
 */

namespace APP\plugins\importexport\fullJournalTransfer\filter\import;

use APP\plugins\importexport\fullJournalTransfer\classes\FullJournalFilterTrait;
use APP\plugins\importexport\native\filter\NativeXmlArticleGalleyFilter;

class NativeXmlExtendedArticleGalleyFilter extends NativeXmlArticleGalleyFilter
{
    use FullJournalFilterTrait;

    public function getClassName(): string
    {
        return static::class;
    }

    /**
     * @copydoc NativeXmlRepresentationFilter::parseIdentifier()
     */
    public function parseIdentifier($element, $representation)
    {
        if ($element->getAttribute('type') === 'doi' && $element->getAttribute('advice') === 'update') {
            $representation->setData('doiId', $this->findOrCreateDoiId($element->textContent));
            return;
        }
        parent::parseIdentifier($element, $representation);
    }
}
