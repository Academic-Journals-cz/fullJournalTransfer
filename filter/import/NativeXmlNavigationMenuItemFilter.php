<?php

/**
 * @file plugins/importexport/fullJournalTransfer/filter/import/NativeXmlNavigationMenuItemFilter.php
 *
 * Copyright (c) 2014-2024 Lepidus Tecnologia
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NativeXmlNavigationMenuItemFilter
 *
 * @brief Imports navigation menu items.
 */

namespace APP\plugins\importexport\fullJournalTransfer\filter\import;

use APP\plugins\importexport\fullJournalTransfer\classes\FullJournalFilterTrait;
use DOMElement;
use PKP\db\DAORegistry;
use PKP\plugins\importexport\native\filter\NativeImportFilter;

class NativeXmlNavigationMenuItemFilter extends NativeImportFilter
{
    use FullJournalFilterTrait;

    public function __construct($filterGroup)
    {
        $this->setDisplayName('Native XML navigation menu item import');
        parent::__construct($filterGroup);
    }

    public function getPluralElementName()
    {
        return 'navigation_menu_items';
    }

    public function getSingularElementName()
    {
        return 'navigation_menu_item';
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

        $navigationMenuItemDao = DAORegistry::getDAO('NavigationMenuItemDAO'); /** @var \PKP\navigationMenu\NavigationMenuItemDAO $navigationMenuItemDao */
        $navigationMenuItem = $navigationMenuItemDao->newDataObject();
        $navigationMenuItem->setContextId((int) $context->getId());
        $navigationMenuItem->setType((string) $node->getAttribute('type'));
        $navigationMenuItem->setPath($node->getAttribute('path') !== '' ? $node->getAttribute('path') : null);
        if ($node->getAttribute('title_locale_key') !== '') {
            $navigationMenuItem->setTitleLocaleKey($node->getAttribute('title_locale_key'));
        }

        foreach ($this->childElements($node) as $childNode) {
            [$locale, $value] = $this->parseLocalizedContent($childNode);
            $locale = $locale ?: $context->getPrimaryLocale();
            switch ($childNode->tagName) {
                case 'title':
                    $navigationMenuItem->setTitle($value, $locale);
                    break;
                case 'content':
                    $navigationMenuItem->setContent($value, $locale);
                    break;
                case 'remote_url':
                    $navigationMenuItem->setRemoteUrl($value, $locale);
                    break;
            }
        }

        $navigationMenuItemDao->insertObject($navigationMenuItem);
        $deployment->setNavigationMenuItemDBId($node->getAttribute('id'), (int) $navigationMenuItem->getId());
        $deployment->incrementCounter('navigation menu items');

        return $navigationMenuItem;
    }
}
