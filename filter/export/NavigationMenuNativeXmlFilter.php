<?php

/**
 * @file plugins/importexport/fullJournalTransfer/filter/export/NavigationMenuNativeXmlFilter.php
 *
 * Copyright (c) 2014-2024 Lepidus Tecnologia
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NavigationMenuNativeXmlFilter
 *
 * @brief Converts navigation menus (with their item assignments) to native XML.
 */

namespace APP\plugins\importexport\fullJournalTransfer\filter\export;

use DOMDocument;
use DOMElement;
use PKP\db\DAORegistry;
use PKP\navigationMenu\NavigationMenu;
use PKP\plugins\importexport\native\filter\NativeExportFilter;

class NavigationMenuNativeXmlFilter extends NativeExportFilter
{
    public function __construct($filterGroup)
    {
        $this->setDisplayName('Native XML navigation menu export');
        parent::__construct($filterGroup);
    }

    public function getClassName(): string
    {
        return static::class;
    }

    /**
     * @param NavigationMenu[] $navigationMenus
     */
    public function &process(&$navigationMenus)
    {
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        $deployment = $this->getDeployment();

        $rootNode = $doc->createElementNS($deployment->getNamespace(), 'navigation_menus');
        foreach ($navigationMenus as $navigationMenu) {
            $rootNode->appendChild($this->createNavigationMenuNode($doc, $navigationMenu));
        }
        $doc->appendChild($rootNode);
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $rootNode->setAttribute('xsi:schemaLocation', $deployment->getNamespace() . ' ' . $deployment->getSchemaFilename());

        return $doc;
    }

    public function createNavigationMenuNode(DOMDocument $doc, NavigationMenu $navigationMenu): DOMElement
    {
        $deployment = $this->getDeployment();

        $node = $doc->createElementNS($deployment->getNamespace(), 'navigation_menu');
        $node->appendChild($doc->createElementNS($deployment->getNamespace(), 'title', htmlspecialchars((string) $navigationMenu->getTitle(), ENT_COMPAT, 'UTF-8')));
        $node->appendChild($doc->createElementNS($deployment->getNamespace(), 'area_name', htmlspecialchars((string) $navigationMenu->getAreaName(), ENT_COMPAT, 'UTF-8')));

        $this->addNavigationMenuAssignments($doc, $node, $navigationMenu);

        return $node;
    }

    public function addNavigationMenuAssignments(DOMDocument $doc, DOMElement $navigationMenuNode, NavigationMenu $navigationMenu): void
    {
        $deployment = $this->getDeployment();

        $assignmentDao = DAORegistry::getDAO('NavigationMenuItemAssignmentDAO'); /** @var \PKP\navigationMenu\NavigationMenuItemAssignmentDAO $assignmentDao */
        $assignments = $assignmentDao->getByMenuId((int) $navigationMenu->getId())->toArray();

        foreach ($assignments as $assignment) {
            $node = $doc->createElementNS($deployment->getNamespace(), 'navigation_menu_item_assignment');
            $node->setAttribute('menu_item_id', (string) $assignment->getMenuItemId());
            if ($assignment->getParentId()) {
                $node->setAttribute('parent_id', (string) $assignment->getParentId());
            }
            $node->setAttribute('seq', (string) (int) $assignment->getSequence());
            $title = $assignment->getTitle(null);
            if (is_array($title)) {
                $this->createLocalizedNodes($doc, $node, 'title', $title);
            }
            $navigationMenuNode->appendChild($node);
        }
    }
}
