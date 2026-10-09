<?php

/**
 * @file plugins/importexport/fullJournalTransfer/filter/export/AnnouncementNativeXmlFilter.php
 *
 * Copyright (c) 2014-2024 Lepidus Tecnologia
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AnnouncementNativeXmlFilter
 *
 * @brief Converts announcements to native XML.
 */

namespace APP\plugins\importexport\fullJournalTransfer\filter\export;

use APP\plugins\importexport\fullJournalTransfer\classes\XmlText;
use DOMDocument;
use DOMElement;
use PKP\announcement\Announcement;
use PKP\db\DAORegistry;
use PKP\plugins\importexport\native\filter\NativeExportFilter;

class AnnouncementNativeXmlFilter extends NativeExportFilter
{
    public function __construct($filterGroup)
    {
        $this->setDisplayName('Native XML announcement export');
        parent::__construct($filterGroup);
    }

    public function getClassName(): string
    {
        return static::class;
    }

    /**
     * @param Announcement[] $announcements
     */
    public function &process(&$announcements)
    {
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        $deployment = $this->getDeployment();

        $rootNode = $doc->createElementNS($deployment->getNamespace(), 'announcements');
        foreach ($announcements as $announcement) {
            $rootNode->appendChild($this->createAnnouncementNode($doc, $announcement));
        }
        $doc->appendChild($rootNode);
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $rootNode->setAttribute('xsi:schemaLocation', $deployment->getNamespace() . ' ' . $deployment->getSchemaFilename());

        return $doc;
    }

    public function createAnnouncementNode(DOMDocument $doc, Announcement $announcement): DOMElement
    {
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();

        $node = $doc->createElementNS($deployment->getNamespace(), 'announcement');

        $idNode = $doc->createElementNS($deployment->getNamespace(), 'id', (string) $announcement->id);
        $idNode->setAttribute('type', 'internal');
        $idNode->setAttribute('advice', 'ignore');
        $node->appendChild($idNode);

        if ($announcement->dateExpire) {
            $node->appendChild($doc->createElementNS($deployment->getNamespace(), 'date_expire', date('Y-m-d', strtotime((string) $announcement->dateExpire))));
        }
        if ($announcement->datePosted) {
            $node->appendChild($doc->createElementNS($deployment->getNamespace(), 'date_posted', date('Y-m-d H:i:s', strtotime((string) $announcement->datePosted))));
        }

        $this->createLocalizedNodes($doc, $node, 'title', $this->sanitizeLocalized($announcement->title));
        $this->createLocalizedNodes($doc, $node, 'description_short', $this->sanitizeLocalized($announcement->descriptionShort));
        $this->createLocalizedNodes($doc, $node, 'description', $this->sanitizeLocalized($announcement->description));

        if ($announcement->typeId) {
            $announcementTypeDao = DAORegistry::getDAO('AnnouncementTypeDAO'); /** @var \PKP\announcement\AnnouncementTypeDAO $announcementTypeDao */
            $announcementType = $announcementTypeDao->getById((int) $announcement->typeId);
            if ($announcementType) {
                $typeName = $announcementType->getName($context->getPrimaryLocale()) ?: $announcementType->getLocalizedTypeName();
                $node->appendChild($doc->createElementNS(
                    $deployment->getNamespace(),
                    'announcement_type_ref',
                    htmlspecialchars((string) $typeName, ENT_COMPAT, 'UTF-8')
                ));
            }
        }

        // The image data (file name, alt text) refers to a file in the public files directory
        $image = $announcement->image;
        if (is_array($image) && !empty($image['uploadName'])) {
            $imageNode = $doc->createElementNS($deployment->getNamespace(), 'image');
            $imageNode->appendChild($doc->createTextNode(json_encode($image)));
            $node->appendChild($imageNode);
        }

        return $node;
    }

    private function sanitizeLocalized($values): ?array
    {
        if (!is_array($values)) {
            return null;
        }
        return array_map(fn ($value) => XmlText::sanitize((string) $value), $values);
    }
}
