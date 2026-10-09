<?php

/**
 * @file plugins/importexport/fullJournalTransfer/filter/import/NativeXmlJournalFilter.php
 *
 * Copyright (c) 2014-2024 Lepidus Tecnologia
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NativeXmlJournalFilter
 *
 * @brief Converts the full journal XML document into a new journal with all its content.
 */

namespace APP\plugins\importexport\fullJournalTransfer\filter\import;

use APP\core\Application;
use APP\facades\Repo;
use APP\file\LibraryFileManager;
use APP\file\PublicFileManager;
use APP\journal\Journal;
use APP\plugins\importexport\fullJournalTransfer\classes\FullJournalFilterTrait;
use APP\plugins\importexport\fullJournalTransfer\classes\MetricsImporter;
use APP\plugins\importexport\fullJournalTransfer\FullJournalImportExportDeployment;
use DOMDocument;
use DOMElement;
use Exception;
use Illuminate\Support\Facades\DB;
use PKP\db\DAORegistry;
use PKP\file\ContextFileManager;
use PKP\file\FileManager;
use PKP\plugins\importexport\native\filter\NativeImportFilter;
use APP\plugins\importexport\fullJournalTransfer\classes\UserImportExportDeployment;
use PKP\plugins\PluginRegistry;
use PKP\site\Site;

class NativeXmlJournalFilter extends NativeImportFilter
{
    use FullJournalFilterTrait;

    /** Schema properties stored in the journals table (never in journal_settings) */
    public const PRIMARY_TABLE_PROPS = ['id', 'urlPath', 'enabled', 'seq', 'primaryLocale', 'currentIssueId'];

    /** Locale list settings that must be a subset of the locales installed on the site */
    public const LOCALE_LIST_SETTINGS = [
        'supportedLocales',
        'supportedFormLocales',
        'supportedSubmissionLocales',
        'supportedSubmissionMetadataLocales',
        'supportedAddedSubmissionLocales',
    ];

    public function __construct($filterGroup)
    {
        $this->setDisplayName('Native XML journal import');
        parent::__construct($filterGroup);
    }

    public function getPluralElementName()
    {
        return 'journals';
    }

    public function getSingularElementName()
    {
        return 'journal';
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
        $journalDao = Application::getContextDAO();
        $site = Application::get()->getRequest()->getSite();

        echo __('plugins.importexport.fullJournal.importingJournal') . PHP_EOL;

        $path = trim((string) ($deployment->getImportOptions()['journal-path'] ?? $node->getAttribute('url_path')));
        if ($path === '' || !preg_match('/^[a-zA-Z0-9\/._-]+$/', $path)) {
            throw new Exception('Invalid journal path "' . $path . '".');
        }
        if ($journalDao->existsByPath($path)) {
            throw new Exception(__('plugins.importexport.fullJournal.error.journalPathExists', ['path' => $path]));
        }

        $primaryLocale = $node->getAttribute('primary_locale') ?: $site->getPrimaryLocale();
        if (!in_array($primaryLocale, $site->getSupportedLocales())) {
            throw new Exception(__('plugins.importexport.fullJournal.error.localeNotInstalled', ['locale' => $primaryLocale]));
        }

        /** @var Journal $journal */
        $journal = $journalDao->newDataObject();
        $journal->setPath($path);
        $journal->setPrimaryLocale($primaryLocale);
        $journal->setEnabled((bool) (int) $node->getAttribute('enabled'));
        $journal->setSequence((int) ($node->getAttribute('seq') ?: REALLY_BIG_NUMBER));
        $journal->setData('supportedLocales', [$primaryLocale]);
        $journalDao->insertObject($journal);
        $deployment->setContext($journal);
        $deployment->incrementCounter('journals');

        // Settings (verbatim rows of journal_settings), then reload the journal
        $this->importSettings($node, $journal);
        $journal = $journalDao->getById($journal->getId());
        $this->fixLocaleSettings($journal, $site);
        $this->fixTheme($journal);
        $journalDao->updateObject($journal);
        $journal = $journalDao->getById($journal->getId());
        $deployment->setContext($journal);

        $this->createJournalDirs($journal);
        $this->installPluginDefaults($journal);

        $tagMethodMap = [
            'plugins' => 'parsePlugins',
            'navigation_menu_items' => 'parseNavigationMenuItems',
            'navigation_menus' => 'parseNavigationMenus',
            'email_templates' => 'parseEmailTemplates',
            'journal_user_groups' => 'parseUserGroups',
            'PKPUsers' => 'parseUsers',
            'genres' => 'parseGenres',
            'review_forms' => 'parseReviewForms',
            'journal_sections' => 'parseSections',
            'categories' => 'parseCategories',
            'subeditor_groups' => 'parseSubEditorGroups',
            'highlights' => 'parseHighlights',
            'institutions' => 'parseInstitutions',
            'announcement_types' => 'parseAnnouncementTypes',
            'announcements' => 'parseAnnouncements',
            'dois' => 'parseDois',
            'extended_issues' => 'parseIssues',
            'extended_articles' => 'parseArticles',
            'library_files' => 'parseLibraryFiles',
            'metrics' => 'parseMetrics',
            'public_files' => 'parsePublicFiles',
        ];

        foreach ($this->childElements($node) as $childNode) {
            if ($childNode->tagName === 'setting') {
                continue; // already imported
            }
            $method = $tagMethodMap[$childNode->tagName] ?? null;
            if (!$method) {
                $deployment->addWarning(Application::ASSOC_TYPE_JOURNAL, $journal->getId(), __('plugins.importexport.common.error.unknownElement', ['param' => $childNode->tagName]));
                continue;
            }
            $this->$method($childNode, $journal);
        }

        $journalDao->resequence();
        $journal = $journalDao->getById($journal->getId());
        $deployment->setContext($journal);
        $this->writeIdRelationFile($journal);
        $deployment->addImportedRootEntity(Application::ASSOC_TYPE_JOURNAL, $journal);

        return $journal;
    }

    //
    // Journal settings
    //
    protected function importSettings(DOMElement $node, Journal $journal): void
    {
        $rows = [];
        foreach ($this->childElements($node, 'setting') as $settingNode) {
            $name = $settingNode->getAttribute('name');
            if ($name === '' || in_array($name, self::PRIMARY_TABLE_PROPS)) {
                continue;
            }
            $rows[] = [
                'journal_id' => (int) $journal->getId(),
                'locale' => $settingNode->getAttribute('locale') ?: '',
                'setting_name' => $name,
                'setting_value' => $settingNode->textContent,
            ];
        }
        // supportedLocales was written by insertObject(); the exported value wins
        DB::table('journal_settings')->where('journal_id', (int) $journal->getId())->delete();
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('journal_settings')->insert($chunk);
        }
    }

    /**
     * The locales of the journal must be installed on the target site.
     */
    protected function fixLocaleSettings(Journal $journal, Site $site): void
    {
        $deployment = $this->getDeployment();
        $siteLocales = $site->getSupportedLocales();
        $primaryLocale = $journal->getPrimaryLocale();

        foreach (self::LOCALE_LIST_SETTINGS as $prop) {
            $values = array_values(array_unique(array_filter((array) $journal->getData($prop), 'is_string')));
            $filtered = array_values(array_intersect($values, $siteLocales));
            if (in_array($prop, ['supportedLocales', 'supportedFormLocales', 'supportedSubmissionMetadataLocales']) && !in_array($primaryLocale, $filtered)) {
                array_unshift($filtered, $primaryLocale);
            }
            if (empty($filtered)) {
                $filtered = [$primaryLocale];
            }
            if ($filtered !== $values) {
                $removed = array_diff($values, $filtered);
                if (!empty($removed)) {
                    $deployment->addWarning(Application::ASSOC_TYPE_JOURNAL, $journal->getId(), __('plugins.importexport.fullJournal.warning.localesRemoved', [
                        'setting' => $prop,
                        'locales' => implode(', ', $removed),
                    ]));
                }
                $journal->setData($prop, $filtered);
            }
        }

        $defaultSubmissionLocale = $journal->getData('supportedDefaultSubmissionLocale');
        if (!$defaultSubmissionLocale || !in_array($defaultSubmissionLocale, $siteLocales)) {
            $journal->setData('supportedDefaultSubmissionLocale', $primaryLocale);
        }
    }

    /**
     * Fall back to the default theme if the exported theme is not installed.
     */
    protected function fixTheme(Journal $journal): void
    {
        $themePath = (string) $journal->getData('themePluginPath');
        if ($themePath === '' || !PluginRegistry::loadPlugin('themes', $themePath)) {
            if ($themePath !== '' && $themePath !== 'default') {
                $this->getDeployment()->addWarning(Application::ASSOC_TYPE_JOURNAL, $journal->getId(), __('plugins.importexport.fullJournal.warning.themeNotInstalled', ['theme' => $themePath]));
            }
            $journal->setData('themePluginPath', 'default');
        }
    }

    public function createJournalDirs(Journal $journal): void
    {
        $fileManager = new FileManager();
        $contextService = app()->get('context'); /** @var \APP\services\ContextService $contextService */
        foreach ($contextService->installFileDirs as $dir) {
            $journalFileDir = sprintf($dir, $contextService->contextsFileDirName, $journal->getId());
            if (!is_dir($journalFileDir)) {
                $fileManager->mkdirtree($journalFileDir);
            }
        }
    }

    /**
     * Install the context specific default settings of all plugins (what
     * Context::add does for a new journal). The exported plugin settings
     * are imported afterwards and override these defaults.
     */
    protected function installPluginDefaults(Journal $journal): void
    {
        foreach (PluginRegistry::loadAllPlugins() as $plugin) {
            if ($plugin->getContextSpecificPluginSettingsFile()) {
                try {
                    $plugin->installContextSpecificSettings('Context::add', [$journal]);
                } catch (\Throwable $e) {
                    error_log('fullJournalTransfer: could not install default settings of plugin ' . $plugin->getName() . ': ' . $e->getMessage());
                }
            }
        }
    }

    //
    // Plugin settings
    //
    public function parsePlugins(DOMElement $node, Journal $journal): void
    {
        foreach ($this->childElements($node, 'plugin') as $pluginNode) {
            $this->parsePlugin($pluginNode, $journal);
        }
    }

    public function parsePlugin(DOMElement $node, Journal $journal): void
    {
        $pluginName = $node->getAttribute('plugin_name');
        if ($pluginName === '') {
            return;
        }
        foreach ($this->childElements($node, 'plugin_setting') as $settingNode) {
            $settingName = $settingNode->getAttribute('setting_name');
            if ($settingName === '') {
                continue;
            }
            DB::table('plugin_settings')->updateOrInsert(
                [
                    'plugin_name' => $pluginName,
                    'context_id' => (int) $journal->getId(),
                    'setting_name' => $settingName,
                ],
                [
                    'setting_value' => $settingNode->textContent,
                    'setting_type' => $settingNode->getAttribute('setting_type') ?: 'string',
                ]
            );
        }
        $this->getFullJournalDeployment()->incrementCounter('plugin settings (plugins)');
    }

    //
    // Navigation menus
    //
    public function parseNavigationMenuItems(DOMElement $node, Journal $journal): void
    {
        foreach ($this->childElements($node, 'navigation_menu_item') as $itemNode) {
            $this->importNodeWith('native-xml=>navigation-menu-item', $itemNode);
        }
    }

    public function parseNavigationMenus(DOMElement $node, Journal $journal): void
    {
        foreach ($this->childElements($node, 'navigation_menu') as $menuNode) {
            $this->importNodeWith('native-xml=>navigation-menu', $menuNode);
        }
    }

    //
    // E-mail templates
    //
    public function parseEmailTemplates(DOMElement $node, Journal $journal): void
    {
        $deployment = $this->getFullJournalDeployment();
        foreach ($this->childElements($node, 'email_template') as $templateNode) {
            $emailKey = $templateNode->getAttribute('email_key');
            if ($emailKey === '') {
                continue;
            }
            $exists = DB::table('email_templates')
                ->where('context_id', (int) $journal->getId())
                ->where('email_key', $emailKey)
                ->exists();
            if ($exists) {
                continue;
            }
            $emailId = DB::table('email_templates')->insertGetId([
                'email_key' => $emailKey,
                'context_id' => (int) $journal->getId(),
                'alternate_to' => $templateNode->getAttribute('alternate_to') ?: null,
            ], 'email_id');
            foreach ($this->childElements($templateNode, 'setting') as $settingNode) {
                $name = $settingNode->getAttribute('name');
                if ($name === '') {
                    continue;
                }
                DB::table('email_templates_settings')->insert([
                    'email_id' => $emailId,
                    'locale' => $settingNode->getAttribute('locale') ?: '',
                    'setting_name' => $name,
                    'setting_value' => $settingNode->textContent,
                ]);
            }
            $deployment->incrementCounter('email templates');
        }
    }

    //
    // User groups
    //
    /**
     * Create the user groups (roles) of the journal from the raw rows. The
     * native user import (PKPUsers element) finds them by name afterwards.
     */
    public function parseUserGroups(DOMElement $node, Journal $journal): void
    {
        $deployment = $this->getFullJournalDeployment();
        $columns = array_flip(DB::getSchemaBuilder()->getColumnListing('user_groups'));
        $flagColumns = ['is_default', 'show_title', 'permit_self_registration', 'permit_metadata_edit', 'permit_settings', 'masthead'];

        foreach ($this->childElements($node, 'journal_user_group') as $groupNode) {
            $row = [
                'context_id' => (int) $journal->getId(),
                'role_id' => (int) $groupNode->getAttribute('role_id'),
            ];
            foreach ($flagColumns as $column) {
                if (isset($columns[$column]) && $groupNode->hasAttribute($column)) {
                    $row[$column] = (int) $groupNode->getAttribute($column);
                }
            }
            $userGroupId = DB::table('user_groups')->insertGetId($row, 'user_group_id');
            $deployment->setMappedId('userGroup', $groupNode->getAttribute('id'), $userGroupId);

            $settingRows = [];
            foreach ($this->childElements($groupNode, 'setting') as $settingNode) {
                $name = $settingNode->getAttribute('name');
                if ($name === '') {
                    continue;
                }
                $settingRows[] = [
                    'user_group_id' => $userGroupId,
                    'locale' => $settingNode->getAttribute('locale') ?: '',
                    'setting_name' => $name,
                    'setting_value' => $settingNode->textContent,
                ];
            }
            if (!empty($settingRows)) {
                DB::table('user_group_settings')->insert($settingRows);
            }

            $stageIds = array_filter(array_map('intval', explode(':', (string) $groupNode->getAttribute('stages'))));
            foreach ($stageIds as $stageId) {
                if ($stageId >= WORKFLOW_STAGE_ID_SUBMISSION && $stageId <= WORKFLOW_STAGE_ID_PRODUCTION) {
                    DB::table('user_group_stage')->insert([
                        'context_id' => (int) $journal->getId(),
                        'user_group_id' => $userGroupId,
                        'stage_id' => $stageId,
                    ]);
                }
            }
            $deployment->incrementCounter('user groups');
        }
    }

    //
    // DOIs
    //
    /**
     * Create the DOI objects of the journal with their status and settings.
     * The issues, publications and galleys find them by identifier later.
     */
    public function parseDois(DOMElement $node, Journal $journal): void
    {
        $deployment = $this->getFullJournalDeployment();
        $contextId = (int) $journal->getId();

        foreach ($this->childElements($node, 'doi_object') as $doiNode) {
            $identifier = trim($doiNode->getAttribute('doi'));
            if ($identifier === '') {
                continue;
            }
            $exists = DB::table('dois')->where('context_id', $contextId)->where('doi', $identifier)->exists();
            if ($exists) {
                continue;
            }
            $row = ['context_id' => $contextId, 'doi' => $identifier];
            if ($doiNode->hasAttribute('status')) {
                $row['status'] = (int) $doiNode->getAttribute('status');
            }
            $doiId = DB::table('dois')->insertGetId($row, 'doi_id');
            $deployment->setMappedId('doi', $doiNode->getAttribute('id'), $doiId);

            $settingRows = [];
            foreach ($this->childElements($doiNode, 'setting') as $settingNode) {
                $name = $settingNode->getAttribute('name');
                if ($name === '') {
                    continue;
                }
                $settingRows[] = [
                    'doi_id' => $doiId,
                    'locale' => $settingNode->getAttribute('locale') ?: '',
                    'setting_name' => $name,
                    'setting_value' => $settingNode->textContent,
                ];
            }
            if (!empty($settingRows)) {
                DB::table('doi_settings')->insert($settingRows);
            }
            $deployment->incrementCounter('DOIs');
        }
    }

    //
    // Users
    //
    public function parseUsers(DOMElement $node, Journal $journal): void
    {
        echo __('plugins.importexport.fullJournal.importingUsers') . PHP_EOL;

        $filter = $this->getSubFilter('native-xml=>user');
        $filter->setDeployment(new UserImportExportDeployment($journal, $this->getDeployment()->getUser(), $this->getFullJournalDeployment()));

        $usersDoc = new DOMDocument('1.0', 'utf-8');
        $usersDoc->appendChild($usersDoc->importNode($node, true));
        $filter->execute($usersDoc, true);

        // The user filter collects its problems itself
        foreach ($filter->getErrors() as $error) {
            $this->getDeployment()->addWarning(Application::ASSOC_TYPE_JOURNAL, $journal->getId(), $error);
        }
    }

    //
    // Genres
    //
    public function parseGenres(DOMElement $node, Journal $journal): void
    {
        foreach ($this->childElements($node, 'genre') as $genreNode) {
            $this->parseGenre($genreNode, $journal);
        }
    }

    public function parseGenre(DOMElement $node, Journal $journal): void
    {
        $genreDao = DAORegistry::getDAO('GenreDAO'); /** @var \PKP\submission\GenreDAO $genreDao */
        $key = $node->getAttribute('key');
        if ($key !== '' && $genreDao->keyExists($key, (int) $journal->getId())) {
            return;
        }

        $genre = $genreDao->newDataObject();
        $genre->setContextId((int) $journal->getId());
        $genre->setKey($key !== '' ? $key : null);
        $genre->setCategory((int) $node->getAttribute('category'));
        $genre->setDependent((bool) (int) $node->getAttribute('dependent'));
        $genre->setSupplementary((bool) (int) $node->getAttribute('supplementary'));
        $genre->setRequired((bool) (int) $node->getAttribute('required'));
        $genre->setSequence((float) $node->getAttribute('seq'));
        $genre->setEnabled($node->hasAttribute('enabled') ? (bool) (int) $node->getAttribute('enabled') : true);

        foreach ($this->childElements($node, 'name') as $nameNode) {
            [$locale, $value] = $this->parseLocalizedContent($nameNode);
            $genre->setName($value, $locale ?: $journal->getPrimaryLocale());
        }

        $genreDao->insertObject($genre);
        $genreDao->updateObject($genre); // insertObject() does not store "enabled"
        $this->getFullJournalDeployment()->incrementCounter('genres');
    }

    //
    // Review forms
    //
    public function parseReviewForms(DOMElement $node, Journal $journal): void
    {
        foreach ($this->childElements($node, 'review_form') as $reviewFormNode) {
            $this->importNodeWith('native-xml=>review-form', $reviewFormNode);
        }
    }

    //
    // Sections
    //
    public function parseSections(DOMElement $node, Journal $journal): void
    {
        echo __('plugins.importexport.fullJournal.importingSections') . PHP_EOL;
        foreach ($this->childElements($node, 'journal_section') as $sectionNode) {
            $this->parseSection($sectionNode, $journal);
        }
    }

    public function parseSection(DOMElement $node, Journal $journal): void
    {
        $deployment = $this->getFullJournalDeployment();

        $section = Repo::section()->newDataObject();
        $section->setContextId((int) $journal->getId());

        $reviewFormId = null;
        if (($oldReviewFormId = $node->getAttribute('review_form_id')) !== '') {
            $reviewFormId = $deployment->getReviewFormDBId($oldReviewFormId);
            if (!$reviewFormId) {
                $deployment->addWarning(Application::ASSOC_TYPE_SECTION, 0, __('plugins.importexport.fullJournal.warning.reviewFormNotFound', ['id' => $oldReviewFormId]));
            }
        }
        $section->setReviewFormId($reviewFormId);
        $section->setSequence((float) $node->getAttribute('seq'));
        $section->setEditorRestricted((bool) (int) $node->getAttribute('editor_restricted'));
        $section->setMetaIndexed((bool) (int) $node->getAttribute('meta_indexed'));
        $section->setMetaReviewed((bool) (int) $node->getAttribute('meta_reviewed'));
        $section->setAbstractsNotRequired((bool) (int) $node->getAttribute('abstracts_not_required'));
        $section->setHideAuthor((bool) (int) $node->getAttribute('hide_author'));
        $section->setHideTitle((bool) (int) $node->getAttribute('hide_title'));
        $section->setAbstractWordCount((int) $node->getAttribute('abstract_word_count'));
        $section->setIsInactive((bool) (int) $node->getAttribute('is_inactive'));

        $oldSectionId = null;
        $unknownNodes = [];
        foreach ($this->childElements($node) as $childNode) {
            switch ($childNode->tagName) {
                case 'id':
                    if ($childNode->getAttribute('type') === 'internal') {
                        $oldSectionId = trim($childNode->textContent);
                    }
                    break;
                case 'abbrev':
                case 'policy':
                case 'title':
                case 'identify_type':
                    [$locale, $value] = $this->parseLocalizedContent($childNode);
                    $locale = $locale ?: $journal->getPrimaryLocale();
                    $setter = ['abbrev' => 'setAbbrev', 'policy' => 'setPolicy', 'title' => 'setTitle', 'identify_type' => 'setIdentifyType'][$childNode->tagName];
                    $section->$setter($value, $locale);
                    break;
                default:
                    $unknownNodes[] = $childNode->tagName;
            }
        }

        $sectionId = (int) Repo::section()->add($section);
        if ($oldSectionId !== null && $oldSectionId !== '') {
            $deployment->setMappedId('section', $oldSectionId, $sectionId);
        }
        foreach ($unknownNodes as $tagName) {
            $deployment->addWarning(Application::ASSOC_TYPE_SECTION, $sectionId, __('plugins.importexport.common.error.unknownElement', ['param' => $tagName]));
        }
        $deployment->addProcessedObjectId(Application::ASSOC_TYPE_SECTION, $sectionId);
        $deployment->incrementCounter('sections');
    }

    //
    // Categories, section/category editors, highlights, institutions, library files
    //
    public function parseCategories(DOMElement $node, Journal $journal): void
    {
        $deployment = $this->getFullJournalDeployment();
        $contextId = (int) $journal->getId();
        $parents = [];

        foreach ($this->childElements($node, 'journal_category') as $categoryNode) {
            $oldId = $categoryNode->getAttribute('id');
            $row = [
                'context_id' => $contextId,
                'parent_id' => null,
                'seq' => (int) $categoryNode->getAttribute('seq'),
                'path' => $categoryNode->getAttribute('path'),
                'image' => $categoryNode->hasAttribute('image') ? $categoryNode->getAttribute('image') : null,
            ];
            $categoryId = DB::table('categories')->insertGetId($row, 'category_id');
            $deployment->setMappedId('category', $oldId, $categoryId);
            if ($categoryNode->hasAttribute('parent_id') && $categoryNode->getAttribute('parent_id') !== '') {
                $parents[$categoryId] = $categoryNode->getAttribute('parent_id');
            }
            $settingRows = $this->readSettingRows($categoryNode, 'category_id', $categoryId);
            if (!empty($settingRows)) {
                DB::table('category_settings')->insert($settingRows);
            }
            $deployment->incrementCounter('categories');
        }
        // the parents are known once all categories are created
        foreach ($parents as $categoryId => $oldParentId) {
            $parentId = $deployment->getMappedId('category', $oldParentId);
            if ($parentId) {
                DB::table('categories')->where('category_id', $categoryId)->update(['parent_id' => $parentId]);
            }
        }
    }

    public function parseSubEditorGroups(DOMElement $node, Journal $journal): void
    {
        $deployment = $this->getFullJournalDeployment();
        foreach ($this->childElements($node, 'subeditor_group') as $groupNode) {
            $user = $this->getUserByEmail($groupNode->getAttribute('user_email'));
            if (!$user) {
                $deployment->addWarning(Application::ASSOC_TYPE_JOURNAL, $journal->getId(), __('plugins.importexport.fullJournal.error.userNotFound', ['email' => $groupNode->getAttribute('user_email')]));
                continue;
            }
            $assocType = (int) $groupNode->getAttribute('assoc_type');
            $assocId = match ($assocType) {
                Application::ASSOC_TYPE_SECTION => $deployment->getMappedId('section', $groupNode->getAttribute('assoc_id')),
                Application::ASSOC_TYPE_CATEGORY => $deployment->getMappedId('category', $groupNode->getAttribute('assoc_id')),
                default => null,
            };
            $userGroupId = $deployment->getMappedId('userGroup', $groupNode->getAttribute('user_group_id'));
            if (!$assocId || !$userGroupId) {
                continue;
            }
            DB::table('subeditor_submission_group')->insert([
                'context_id' => (int) $journal->getId(),
                'assoc_type' => $assocType,
                'assoc_id' => $assocId,
                'user_id' => (int) $user->getId(),
                'user_group_id' => $userGroupId,
            ]);
            $deployment->incrementCounter('section/category editor assignments');
        }
    }

    public function parseHighlights(DOMElement $node, Journal $journal): void
    {
        $deployment = $this->getFullJournalDeployment();
        if (!DB::getSchemaBuilder()->hasTable('highlights')) {
            return;
        }
        foreach ($this->childElements($node, 'highlight') as $highlightNode) {
            $highlightId = DB::table('highlights')->insertGetId([
                'context_id' => (int) $journal->getId(),
                'sequence' => (int) $highlightNode->getAttribute('sequence'),
                'url' => $highlightNode->getAttribute('url'),
            ], 'highlight_id');
            $deployment->setMappedId('highlight', $highlightNode->getAttribute('id'), $highlightId);
            $settingRows = $this->readSettingRows($highlightNode, 'highlight_id', $highlightId);
            if (!empty($settingRows)) {
                DB::table('highlight_settings')->insert($settingRows);
            }
            $deployment->incrementCounter('highlights');
        }
    }

    public function parseInstitutions(DOMElement $node, Journal $journal): void
    {
        $deployment = $this->getFullJournalDeployment();
        foreach ($this->childElements($node, 'institution') as $institutionNode) {
            $institutionId = DB::table('institutions')->insertGetId([
                'context_id' => (int) $journal->getId(),
                'ror' => $institutionNode->hasAttribute('ror') ? $institutionNode->getAttribute('ror') : null,
                'deleted_at' => $this->parseDateTime($institutionNode->getAttribute('deleted_at')),
            ], 'institution_id');
            $deployment->setMappedId('institution', $institutionNode->getAttribute('id'), $institutionId);
            $settingRows = $this->readSettingRows($institutionNode, 'institution_id', $institutionId);
            if (!empty($settingRows)) {
                DB::table('institution_settings')->insert($settingRows);
            }
            foreach ($this->childElements($institutionNode, 'ip') as $ipNode) {
                DB::table('institution_ip')->insert([
                    'institution_id' => $institutionId,
                    'ip_string' => $ipNode->getAttribute('ip_string'),
                    'ip_start' => $ipNode->hasAttribute('ip_start') ? (int) $ipNode->getAttribute('ip_start') : null,
                    'ip_end' => $ipNode->hasAttribute('ip_end') ? (int) $ipNode->getAttribute('ip_end') : null,
                ]);
            }
            $deployment->incrementCounter('institutions');
        }
    }

    public function parseLibraryFiles(DOMElement $node, Journal $journal): void
    {
        $deployment = $this->getFullJournalDeployment();
        foreach ($this->childElements($node, 'library_file') as $fileNode) {
            $submissionId = null;
            if ($fileNode->hasAttribute('submission_id') && $fileNode->getAttribute('submission_id') !== '') {
                $submissionId = $deployment->getSubmissionDBId($fileNode->getAttribute('submission_id'));
                if (!$submissionId) {
                    continue; // the submission was not imported
                }
            }
            $now = date('Y-m-d H:i:s');
            $fileId = DB::table('library_files')->insertGetId([
                'context_id' => (int) $journal->getId(),
                'file_name' => $fileNode->getAttribute('file_name'),
                'original_file_name' => $fileNode->getAttribute('original_file_name'),
                'file_type' => $fileNode->getAttribute('file_type'),
                'file_size' => (int) $fileNode->getAttribute('file_size'),
                'type' => (int) $fileNode->getAttribute('type'),
                'date_uploaded' => $this->parseDateTime($fileNode->getAttribute('date_uploaded')) ?? $now,
                'date_modified' => $this->parseDateTime($fileNode->getAttribute('date_modified')) ?? $now,
                'submission_id' => $submissionId,
                'public_access' => (int) $fileNode->getAttribute('public_access'),
            ], 'file_id');
            $deployment->setMappedId('libraryFile', $fileNode->getAttribute('id'), $fileId);

            // copy the file from the archive into files_dir/contexts/<id>/library/
            $src = $fileNode->getAttribute('src');
            $sourcePath = $src !== '' && !str_contains($src, '..') ? rtrim($deployment->getImportPath(), '/') . '/' . $src : '';
            if ($sourcePath !== '' && is_file($sourcePath)) {
                $libraryFileManager = new LibraryFileManager((int) $journal->getId());
                $libraryFileManager->mkdirtree($libraryFileManager->getBasePath());
                $libraryFileManager->copyFile($sourcePath, $libraryFileManager->getBasePath() . $fileNode->getAttribute('file_name'));
            } else {
                $deployment->addWarning(Application::ASSOC_TYPE_JOURNAL, $journal->getId(), __('plugins.importexport.fullJournal.warning.libraryFileMissing', ['file' => $src !== '' ? $src : $fileNode->getAttribute('file_name')]));
            }
            $settingRows = $this->readSettingRows($fileNode, 'file_id', $fileId);
            foreach ($settingRows as &$settingRow) {
                $settingRow['setting_type'] = 'string';
            }
            unset($settingRow);
            if (!empty($settingRows)) {
                DB::table('library_file_settings')->insert($settingRows);
            }
            $deployment->incrementCounter('library files');
        }
    }

    //
    // Announcements
    //
    public function parseAnnouncementTypes(DOMElement $node, Journal $journal): void
    {
        foreach ($this->childElements($node, 'announcement_type') as $typeNode) {
            $this->importNodeWith('native-xml=>announcement-type', $typeNode);
        }
    }

    public function parseAnnouncements(DOMElement $node, Journal $journal): void
    {
        foreach ($this->childElements($node, 'announcement') as $announcementNode) {
            $this->importNodeWith('native-xml=>announcement', $announcementNode);
        }
    }

    //
    // Issues and articles
    //
    public function parseIssues(DOMElement $node, Journal $journal): void
    {
        $deployment = $this->getFullJournalDeployment();
        echo __('plugins.importexport.fullJournal.importingIssues') . PHP_EOL;

        foreach ($this->childElements($node) as $childNode) {
            switch ($childNode->tagName) {
                case 'extended_issue':
                    $this->importNodeWith('native-xml=>extended-issue', $childNode);
                    break;
                case 'custom_order':
                    $issueId = $deployment->getIssueDBId($childNode->getAttribute('id'));
                    if ($issueId) {
                        Repo::issue()->dao->moveCustomIssueOrder((int) $journal->getId(), $issueId, (int) $childNode->textContent);
                    }
                    break;
            }
        }
    }

    public function parseArticles(DOMElement $node, Journal $journal): void
    {
        echo __('plugins.importexport.fullJournal.importingArticles') . PHP_EOL;
        // These articles belong to no issue: make sure no issue of the previous loop is used
        $this->getFullJournalDeployment()->setIssue(null);
        foreach ($this->childElements($node, 'extended_article') as $articleNode) {
            $this->importNodeWith('native-xml=>extended-article', $articleNode);
        }
    }

    //
    // Usage statistics
    //
    public function parseMetrics(DOMElement $node, Journal $journal): void
    {
        $deployment = $this->getFullJournalDeployment();
        if ($deployment->hasImportOption('no-metrics')) {
            return;
        }
        $src = $node->getAttribute('src') ?: FullJournalImportExportDeployment::METRICS_DIR;
        $metricsDir = rtrim($deployment->getImportPath(), '/') . '/' . $src;
        if (!is_dir($metricsDir)) {
            $deployment->addWarning(Application::ASSOC_TYPE_JOURNAL, $journal->getId(), __('plugins.importexport.fullJournal.warning.metricsDirMissing', ['dir' => $src]));
            return;
        }
        echo __('plugins.importexport.fullJournal.importingMetrics') . PHP_EOL;
        $counts = (new MetricsImporter($deployment))->import($metricsDir, $journal);
        foreach ($counts as $table => $count) {
            echo "  {$table}: {$count}" . PHP_EOL;
            $deployment->incrementCounter('metrics rows (' . $table . ')', $count);
        }
    }

    //
    // Public files (logos, favicon, stylesheet, covers, announcement images)
    //
    public function parsePublicFiles(DOMElement $node, Journal $journal): void
    {
        $deployment = $this->getFullJournalDeployment();
        if ($deployment->hasImportOption('no-public-files')) {
            return;
        }
        $src = $node->getAttribute('src');
        if ($src === '' || str_contains($src, '..')) {
            return;
        }
        $sourceDir = rtrim($deployment->getImportPath(), '/') . '/' . $src;
        if (!is_dir($sourceDir)) {
            $deployment->addWarning(Application::ASSOC_TYPE_JOURNAL, $journal->getId(), __('plugins.importexport.fullJournal.warning.publicFilesMissing', ['dir' => $src]));
            return;
        }
        $targetDir = (new PublicFileManager())->getContextFilesPath((int) $journal->getId());
        $fileManager = new FileManager();
        $fileManager->mkdirtree($targetDir);
        $copied = $this->copyDirectoryContents($sourceDir, $targetDir);
        $deployment->incrementCounter('public files', $copied);
    }

    /**
     * Copy the contents of a directory recursively; returns the number of copied files.
     */
    protected function copyDirectoryContents(string $sourceDir, string $targetDir): int
    {
        $count = 0;
        $fileManager = new FileManager();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($sourceDir) + 1);
            $target = $targetDir . '/' . $relative;
            if ($item->isDir()) {
                $fileManager->mkdirtree($target);
            } elseif ($item->isFile()) {
                $fileManager->mkdirtree(dirname($target));
                if (copy($item->getPathname(), $target)) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Write a file with the old => new ID relations into the files directory of
     * the journal (useful for redirects of old URLs).
     */
    protected function writeIdRelationFile(Journal $journal): void
    {
        $deployment = $this->getFullJournalDeployment();
        $contextFileManager = new ContextFileManager((int) $journal->getId());
        $filePath = rtrim($contextFileManager->getBasePath(), '/') . '/journal_' . $journal->getId() . '_id_relation.txt';

        $contents = "# Full journal transfer " . date('Y-m-d H:i:s') . "\n";
        $contents .= "# entity\told id\tnew id\n";
        foreach (['submission', 'publication', 'issue', 'issueGalley', 'representation', 'section', 'category', 'userGroup', 'reviewForm', 'navigationMenuItem', 'doi', 'institution', 'libraryFile'] as $entity) {
            foreach ($deployment->getIdMap($entity) as $oldId => $newId) {
                $contents .= "{$entity}\t{$oldId}\t{$newId}\n";
            }
        }
        file_put_contents($filePath, $contents);
    }
}
