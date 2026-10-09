<?php

/**
 * @file plugins/importexport/fullJournalTransfer/filter/export/AnnouncementTypeNativeXmlFilter.php
 *
 * Copyright (c) 2014-2024 Lepidus Tecnologia
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AnnouncementTypeNativeXmlFilter
 *
 * @brief Converts announcement types to native XML.
 */

namespace APP\plugins\importexport\fullJournalTransfer\filter\export;

use DOMDocument;
use DOMElement;
use PKP\announcement\AnnouncementType;
use PKP\plugins\importexport\native\filter\NativeExportFilter;

class AnnouncementTypeNativeXmlFilter extends NativeExportFilter
{
    public function __construct($filterGroup)
    {
        $this->setDisplayName('Native XML announcement type export');
        parent::__construct($filterGroup);
    }

    public function getClassName(): string
    {
        return static::class;
    }

    /**
     * @param AnnouncementType[] $announcementTypes
     */
    public function &process(&$announcementTypes)
    {
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        $deployment = $this->getDeployment();

        $rootNode = $doc->createElementNS($deployment->getNamespace(), 'announcement_types');
        foreach ($announcementTypes as $announcementType) {
            $rootNode->appendChild($this->createAnnouncementTypeNode($doc, $announcementType));
        }
        $doc->appendChild($rootNode);
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $rootNode->setAttribute('xsi:schemaLocation', $deployment->getNamespace() . ' ' . $deployment->getSchemaFilename());

        return $doc;
    }

    public function createAnnouncementTypeNode(DOMDocument $doc, AnnouncementType $announcementType): DOMElement
    {
        $deployment = $this->getDeployment();

        $node = $doc->createElementNS($deployment->getNamespace(), 'announcement_type');
        $node->setAttribute('id', (string) $announcementType->getId());
        $this->createLocalizedNodes($doc, $node, 'name', $announcementType->getName(null));

        return $node;
    }
}
