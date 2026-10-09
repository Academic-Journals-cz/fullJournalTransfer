<?php

/**
 * @file plugins/importexport/fullJournalTransfer/classes/FilterInstaller.php
 *
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class FilterInstaller
 *
 * @brief Keeps the filter groups and filters of the plugin (stored in the
 *  database tables filter_groups / filters) in sync with filter/filterConfig.xml.
 *
 *  OJS installs the filter configuration of a plugin only through the Installer
 *  (site upgrade, plugin upload via the web UI) and never updates filter groups
 *  that already exist. Plugins deployed by copying the directory, or upgraded
 *  from an older version with different type descriptions, would therefore run
 *  with a missing or stale configuration. This class makes the configuration
 *  self-healing: it is cheap (a handful of queries) and idempotent.
 */

namespace APP\plugins\importexport\fullJournalTransfer\classes;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\DB;
use PKP\core\Core;
use PKP\db\DAORegistry;
use PKP\filter\FilterGroup;
use PKP\filter\FilterGroupDAO;
use PKP\filter\FilterDAO;
use PKP\plugins\Plugin;

class FilterInstaller
{
    public const PLUGIN_FILTER_NAMESPACE = 'APP\\plugins\\importexport\\fullJournalTransfer\\';

    public function __construct(private Plugin $plugin)
    {
    }

    public function getConfigPath(): string
    {
        return Core::getBaseDir() . '/' . $this->plugin->getPluginPath() . '/filter/filterConfig.xml';
    }

    /**
     * Install or refresh all filter groups and filters of the plugin.
     */
    public function install(): void
    {
        $config = $this->parseConfig();
        if (!$config) {
            return;
        }

        /** @var FilterGroupDAO $filterGroupDao */
        $filterGroupDao = DAORegistry::getDAO('FilterGroupDAO');
        /** @var FilterDAO $filterDao */
        $filterDao = DAORegistry::getDAO('FilterDAO');

        foreach ($config['groups'] as $symbolic => $groupData) {
            $filterGroup = $filterGroupDao->getObjectBySymbolic($symbolic);
            if ($filterGroup) {
                // Update stale type descriptions (e.g. class paths of older OJS versions)
                if ($filterGroup->getInputType() !== $groupData['inputType']
                    || $filterGroup->getOutputType() !== $groupData['outputType']
                    || $filterGroup->getDisplayName() !== $groupData['displayName']
                    || $filterGroup->getDescription() !== $groupData['description']
                ) {
                    $filterGroup->setInputType($groupData['inputType']);
                    $filterGroup->setOutputType($groupData['outputType']);
                    $filterGroup->setDisplayName($groupData['displayName']);
                    $filterGroup->setDescription($groupData['description']);
                    $filterGroupDao->updateObject($filterGroup);
                }
            } else {
                $filterGroup = new FilterGroup();
                $filterGroup->setSymbolic($symbolic);
                $filterGroup->setDisplayName($groupData['displayName']);
                $filterGroup->setDescription($groupData['description']);
                $filterGroup->setInputType($groupData['inputType']);
                $filterGroup->setOutputType($groupData['outputType']);
                $filterGroupDao->insertObject($filterGroup);
            }

            // Remove filters of this plugin that are registered in the group but are
            // no longer configured for it (e.g. wrong group in an older version).
            $expectedClasses = $config['filters'][$symbolic] ?? [];
            $rows = DB::table('filters as f')
                ->join('filter_groups as fg', 'f.filter_group_id', '=', 'fg.filter_group_id')
                ->where('fg.symbolic', $symbolic)
                ->select(['f.filter_id', 'f.class_name'])
                ->get();
            foreach ($rows as $row) {
                $className = ltrim($row->class_name, '\\');
                if (str_starts_with($className, self::PLUGIN_FILTER_NAMESPACE) && !in_array($className, $expectedClasses, true)) {
                    $filterDao->deleteObjectById((int) $row->filter_id);
                }
            }

            // Install the configured filters (idempotent)
            foreach ($expectedClasses as $className) {
                $existing = $filterDao->getObjectsByGroupAndClass($symbolic, $className)->toArray();
                if (count($existing) > 0) {
                    continue;
                }
                $filterDao->configureObject($className, $symbolic);
            }
        }
    }

    /**
     * Parse filter/filterConfig.xml
     *
     * @return ?array ['groups' => [symbolic => [...]], 'filters' => [symbolic => [className, ...]]]
     */
    public function parseConfig(): ?array
    {
        $path = $this->getConfigPath();
        if (!is_readable($path)) {
            return null;
        }

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->load($path, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return null;
        }

        $groups = [];
        foreach ($doc->getElementsByTagName('filterGroup') as $node) {
            /** @var DOMElement $node */
            $groups[$node->getAttribute('symbolic')] = [
                'displayName' => $node->getAttribute('displayName'),
                'description' => $node->getAttribute('description'),
                'inputType' => $node->getAttribute('inputType'),
                'outputType' => $node->getAttribute('outputType'),
            ];
        }

        $filters = [];
        foreach ($doc->getElementsByTagName('filter') as $node) {
            /** @var DOMElement $node */
            $filters[$node->getAttribute('inGroup')][] = ltrim($node->getAttribute('class'), '\\');
        }

        return ['groups' => $groups, 'filters' => $filters];
    }
}
