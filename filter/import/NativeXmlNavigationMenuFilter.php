<?php

/**
 * @file plugins/importexport/fullJournalTransfer/filter/import/NativeXmlNavigationMenuFilter.php
 *
 * Copyright (c) 2014-2024 Lepidus Tecnologia
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NativeXmlNavigationMenuFilter
 *
 * @brief Imports navigation menus and their item assignments.
 */

namespace APP\plugins\importexport\fullJournalTransfer\filter\import;

use APP\plugins\importexport\fullJournalTransfer\classes\FullJournalFilterTrait;
use DOMElement;
use PKP\db\DAORegistry;
use PKP\plugins\importexport\native\filter\NativeImportFilter;

class NativeXmlNavigationMenuFilter extends NativeImportFilter
{
    use FullJournalFilterTrait;

    public function __construct($filterGroup)
    {
        $this->setDisplayName('Native XML navigation menu import');
        parent::__construct($filterGroup);
    }

    public function getPluralElementName()
    {
        return 'navigation_menus';
    }

    public function getSingularElementName()
    {
        return 'navigation_menu';
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

        $navigationMenuDao = DAORegistry::getDAO('NavigationMenuDAO'); /** @var \PKP\navigationMenu\NavigationMenuDAO $navigationMenuDao */
        $navigationMenu = $navigationMenuDao->newDataObject();
        $navigationMenu->setContextId((int) $context->getId());
        $navigationMenu->setTitle('');
        $navigationMenu->setAreaName('');

        foreach ($this->childElements($node) as $childNode) {
            switch ($childNode->tagName) {
                case 'title':
                    $navigationMenu->setTitle($childNode->textContent);
                    break;
                case 'area_name':
                    $navigationMenu->setAreaName($childNode->textContent);
                    break;
            }
        }

        $navigationMenuId = $navigationMenuDao->insertObject($navigationMenu);
        $deployment->incrementCounter('navigation menus');

        foreach ($this->childElements($node, 'navigation_menu_item_assignment') as $assignmentNode) {
            $this->parseNavigationMenuItemAssignment($assignmentNode, $navigationMenuId);
        }

        return $navigationMenu;
    }

    public function parseNavigationMenuItemAssignment(DOMElement $node, int $navigationMenuId): void
    {
        $deployment = $this->getFullJournalDeployment();

        $menuItemId = $deployment->getNavigationMenuItemDBId($node->getAttribute('menu_item_id'));
        if (!$menuItemId) {
            return;
        }
        $parentId = $node->getAttribute('parent_id') !== '' ? $deployment->getNavigationMenuItemDBId($node->getAttribute('parent_id')) : null;

        $assignmentDao = DAORegistry::getDAO('NavigationMenuItemAssignmentDAO'); /** @var \PKP\navigationMenu\NavigationMenuItemAssignmentDAO $assignmentDao */
        $assignment = $assignmentDao->newDataObject();
        $assignment->setMenuId($navigationMenuId);
        $assignment->setMenuItemId($menuItemId);
        $assignment->setParentId($parentId);
        $assignment->setSequence((int) $node->getAttribute('seq'));
        $assignmentDao->insertObject($assignment);

        // Custom titles of the assignment (override the item title)
        $titles = [];
        foreach ($this->childElements($node, 'title') as $titleNode) {
            [$locale, $value] = $this->parseLocalizedContent($titleNode);
            if ($locale) {
                $titles[$locale] = $value;
            }
        }
        if (!empty($titles)) {
            $assignment->setTitle($titles, null);
            $assignmentDao->updateLocaleFields($assignment);
        }
    }
}
