<?php

/**
 * @file plugins/importexport/fullJournalTransfer/classes/FullJournalFilterTrait.php
 *
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @trait FullJournalFilterTrait
 *
 * @brief Helpers shared by the import and export filters of the plugin.
 */

namespace APP\plugins\importexport\fullJournalTransfer\classes;

use APP\facades\Repo;
use APP\plugins\importexport\fullJournalTransfer\FullJournalImportExportDeployment;
use DOMDocument;
use DOMElement;
use DOMNode;
use Generator;
use PKP\plugins\importexport\native\filter\NativeExportFilter;
use PKP\plugins\importexport\PKPImportExportFilter;
use PKP\user\User;
use PKP\xslt\XMLTypeDescription;

trait FullJournalFilterTrait
{
    /** @var array<string,?User> cache of users looked up by e-mail */
    private array $usersByEmail = [];

    /**
     * Get a filter of the given group bound to the current deployment.
     *
     * The XML validation of the intermediate documents is switched off: every
     * sub document would otherwise be validated against the (large) schema,
     * which slows down the export/import of big journals considerably. The
     * complete journal document is validated once by the plugin.
     */
    protected function getSubFilter(string $filterGroup)
    {
        $filter = PKPImportExportFilter::getFilter($filterGroup, $this->getDeployment(), $this->opts ?? []);
        if ($filter instanceof NativeExportFilter) {
            $filter->setNoValidation(true);
        }
        $inputType = $filter->getInputType();
        if ($inputType instanceof XMLTypeDescription) {
            $inputType->setValidationStrategy(XMLTypeDescription::XML_TYPE_DESCRIPTION_VALIDATE_NONE);
        }
        $outputType = $filter->getOutputType();
        if ($outputType instanceof XMLTypeDescription) {
            $outputType->setValidationStrategy(XMLTypeDescription::XML_TYPE_DESCRIPTION_VALIDATE_NONE);
        }
        return $filter;
    }

    /**
     * Run an import filter on a single node (wrapped in its own document).
     *
     * @return array The imported objects
     */
    protected function importNodeWith(string $filterGroup, DOMElement $node): array
    {
        $filter = $this->getSubFilter($filterGroup);
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->appendChild($doc->importNode($node, true));
        $result = $filter->execute($doc, true);
        return is_array($result) ? $result : [];
    }

    /**
     * Run an export filter and append the resulting root element to $parentNode.
     */
    protected function appendExportedNode(string $filterGroup, $objects, DOMDocument $doc, DOMElement $parentNode): ?DOMElement
    {
        $filter = $this->getSubFilter($filterGroup);
        $resultDoc = $filter->execute($objects, true);
        if ($resultDoc instanceof DOMDocument && $resultDoc->documentElement instanceof DOMElement) {
            $clone = $doc->importNode($resultDoc->documentElement, true);
            // the schema location belongs to the root element only
            $clone->removeAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'schemaLocation');
            $clone->removeAttributeNS('http://www.w3.org/2000/xmlns/', 'xsi');
            $parentNode->appendChild($clone);
            return $clone;
        }
        return null;
    }

    /**
     * Append raw settings rows (objects with setting_name, locale, setting_value)
     * as <setting name="" locale="">value</setting> elements.
     *
     * @param iterable<object> $rows
     * @param string[] $skip setting names that are not exported
     */
    protected function appendSettingNodes(DOMDocument $doc, DOMElement $parentNode, iterable $rows, array $skip = []): void
    {
        foreach ($rows as $row) {
            if ($row->setting_value === null || in_array($row->setting_name, $skip, true)) {
                continue;
            }
            $settingNode = $doc->createElementNS($this->getDeployment()->getNamespace(), 'setting');
            $settingNode->setAttribute('name', $row->setting_name);
            if ((string) ($row->locale ?? '') !== '') {
                $settingNode->setAttribute('locale', $row->locale);
            }
            $settingNode->appendChild($doc->createTextNode(XmlText::sanitize((string) $row->setting_value)));
            $parentNode->appendChild($settingNode);
        }
    }

    /**
     * Read the <setting> children of a node as rows for a settings table.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function readSettingRows(DOMElement $node, string $idColumn, int $id): array
    {
        $rows = [];
        foreach ($this->childElements($node, 'setting') as $settingNode) {
            $name = $settingNode->getAttribute('name');
            if ($name === '') {
                continue;
            }
            $rows[] = [
                $idColumn => $id,
                'locale' => $settingNode->getAttribute('locale') ?: '',
                'setting_name' => $name,
                'setting_value' => $settingNode->textContent,
            ];
        }
        return $rows;
    }

    /**
     * Iterate over the element children of a node.
     *
     * @return Generator<DOMElement>
     */
    protected function childElements(DOMNode $node, ?string $tagName = null): Generator
    {
        for ($n = $node->firstChild; $n !== null; $n = $n->nextSibling) {
            if ($n instanceof DOMElement && ($tagName === null || $n->tagName === $tagName)) {
                yield $n;
            }
        }
    }

    /**
     * Get the first element child with the given name.
     */
    protected function firstChildElement(DOMNode $node, string $tagName): ?DOMElement
    {
        foreach ($this->childElements($node, $tagName) as $child) {
            return $child;
        }
        return null;
    }

    /**
     * Create an element in the PKP namespace with an (escaped) text value.
     */
    protected function createTextNode(DOMDocument $doc, string $name, ?string $value): DOMElement
    {
        $node = $doc->createElementNS($this->getDeployment()->getNamespace(), $name);
        if ($value !== null && $value !== '') {
            $node->appendChild($doc->createTextNode($value));
        }
        return $node;
    }

    /**
     * Set an attribute only when the value is not null/empty-string.
     */
    protected function setOptionalAttribute(DOMElement $node, string $name, $value): void
    {
        if ($value === null || $value === '' || $value === false) {
            return;
        }
        $node->setAttribute($name, (string) $value);
    }

    /**
     * Normalize a date/datetime value from the XML to "Y-m-d H:i:s" (or null).
     */
    protected function parseDateTime(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Normalize a date value from the XML to "Y-m-d" (or null).
     */
    protected function parseDate(?string $value): ?string
    {
        $dateTime = $this->parseDateTime($value);
        return $dateTime ? substr($dateTime, 0, 10) : null;
    }

    /**
     * Look up a user by e-mail (users are matched between installations by e-mail).
     */
    protected function getUserByEmail(?string $email): ?User
    {
        $email = trim((string) $email);
        if ($email === '') {
            return null;
        }
        $key = mb_strtolower($email);
        if (!array_key_exists($key, $this->usersByEmail)) {
            $this->usersByEmail[$key] = Repo::user()->getByEmail($email, true);
        }
        return $this->usersByEmail[$key];
    }

    /**
     * Get the e-mail of a user by ID (export side), or null if the user does not exist.
     */
    protected function getUserEmailById(?int $userId): ?string
    {
        if (!$userId) {
            return null;
        }
        static $emailsById = [];
        if (!array_key_exists($userId, $emailsById)) {
            $user = Repo::user()->get($userId, true);
            $emailsById[$userId] = $user ? $user->getEmail() : null;
        }
        return $emailsById[$userId];
    }

    /**
     * Get the ID of the DOI object with the given identifier in the journal
     * being imported, creating it when it does not exist yet. The native
     * import looks the identifier up site-wide, which would link the object to
     * a DOI of another journal when the same DOI exists there.
     */
    protected function findOrCreateDoiId(?string $doi): ?int
    {
        $doi = trim((string) $doi);
        if ($doi === '') {
            return null;
        }
        $contextId = (int) $this->getDeployment()->getContext()->getId();
        $found = Repo::doi()->getCollector()
            ->filterByContextIds([$contextId])
            ->filterByIdentifier($doi)
            ->getMany()
            ->first();
        if ($found) {
            return (int) $found->getId();
        }
        $newDoi = Repo::doi()->newDataObject(['doi' => $doi, 'contextId' => $contextId]);
        return (int) Repo::doi()->add($newDoi);
    }

    /**
     * Typed access to the deployment.
     */
    protected function getFullJournalDeployment(): FullJournalImportExportDeployment
    {
        return $this->getDeployment();
    }
}
