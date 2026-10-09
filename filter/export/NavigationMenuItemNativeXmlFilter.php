<?php

/**
 * @file plugins/importexport/fullJournalTransfer/filter/export/NavigationMenuItemNativeXmlFilter.php
 *
 * Copyright (c) 2014-2024 Lepidus Tecnologia
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NavigationMenuItemNativeXmlFilter
 *
 * @brief Converts navigation menu items to native XML.
 */

namespace APP\plugins\importexport\fullJournalTransfer\filter\export;

use DOMDocument;
use DOMElement;
use PKP\navigationMenu\NavigationMenuItem;
use PKP\plugins\importexport\native\filter\NativeExportFilter;

class NavigationMenuItemNativeXmlFilter extends NativeExportFilter
{
    public function __construct($filterGroup)
    {
        $this->setDisplayName('Native XML navigation menu item export');
        parent::__construct($filterGroup);
    }

    public function getClassName(): string
    {
        return static::class;
    }

    /**
     * @param NavigationMenuItem[] $navigationMenuItems
     */
    public function &process(&$navigationMenuItems)
    {
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        $deployment = $this->getDeployment();

        $rootNode = $doc->createElementNS($deployment->getNamespace(), 'navigation_menu_items');
        foreach ($navigationMenuItems as $navigationMenuItem) {
            $rootNode->appendChild($this->createNavigationMenuItemNode($doc, $navigationMenuItem));
        }
        $doc->appendChild($rootNode);
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $rootNode->setAttribute('xsi:schemaLocation', $deployment->getNamespace() . ' ' . $deployment->getSchemaFilename());

        return $doc;
    }

    public function createNavigationMenuItemNode(DOMDocument $doc, NavigationMenuItem $navigationMenuItem): DOMElement
    {
        $deployment = $this->getDeployment();

        $node = $doc->createElementNS($deployment->getNamespace(), 'navigation_menu_item');
        $node->setAttribute('id', (string) $navigationMenuItem->getId());
        $node->setAttribute('type', (string) $navigationMenuItem->getType());
        if ($navigationMenuItem->getPath()) {
            $node->setAttribute('path', $navigationMenuItem->getPath());
        }
        if ($navigationMenuItem->getTitleLocaleKey()) {
            $node->setAttribute('title_locale_key', $navigationMenuItem->getTitleLocaleKey());
        }

        $this->createLocalizedNodes($doc, $node, 'title', $this->asLocalizedArray($navigationMenuItem->getTitle(null)));
        $this->createLocalizedNodes($doc, $node, 'content', $this->asLocalizedArray($navigationMenuItem->getContent(null)));
        $this->createLocalizedNodes($doc, $node, 'remote_url', $this->asLocalizedArray($navigationMenuItem->getRemoteUrl(null)));

        return $node;
    }

    private function asLocalizedArray($value): ?array
    {
        return is_array($value) ? $value : null;
    }
}
