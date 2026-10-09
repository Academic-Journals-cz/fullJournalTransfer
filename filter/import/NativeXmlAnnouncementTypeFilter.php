<?php

/**
 * @file plugins/importexport/fullJournalTransfer/filter/import/NativeXmlAnnouncementTypeFilter.php
 *
 * Copyright (c) 2014-2024 Lepidus Tecnologia
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NativeXmlAnnouncementTypeFilter
 *
 * @brief Imports announcement types.
 */

namespace APP\plugins\importexport\fullJournalTransfer\filter\import;

use APP\core\Application;
use APP\plugins\importexport\fullJournalTransfer\classes\FullJournalFilterTrait;
use DOMElement;
use PKP\db\DAORegistry;
use PKP\plugins\importexport\native\filter\NativeImportFilter;

class NativeXmlAnnouncementTypeFilter extends NativeImportFilter
{
    use FullJournalFilterTrait;

    public function __construct($filterGroup)
    {
        $this->setDisplayName('Native XML announcement type import');
        parent::__construct($filterGroup);
    }

    public function getPluralElementName()
    {
        return 'announcement_types';
    }

    public function getSingularElementName()
    {
        return 'announcement_type';
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

        $announcementTypeDao = DAORegistry::getDAO('AnnouncementTypeDAO'); /** @var \PKP\announcement\AnnouncementTypeDAO $announcementTypeDao */
        $announcementType = $announcementTypeDao->newDataObject();
        $announcementType->setContextId((int) $context->getId());

        foreach ($this->childElements($node, 'name') as $nameNode) {
            [$locale, $value] = $this->parseLocalizedContent($nameNode);
            $announcementType->setName($value, $locale ?: $context->getPrimaryLocale());
        }

        $announcementTypeDao->insertObject($announcementType);
        $deployment->incrementCounter('announcement types');

        return $announcementType;
    }
}
