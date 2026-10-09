<?php

/**
 * @file plugins/importexport/fullJournalTransfer/filter/export/JournalNativeXmlFilter.php
 *
 * Copyright (c) 2014-2024 Lepidus Tecnologia
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class JournalNativeXmlFilter
 *
 * @brief Converts a Journal (with all its content) to the full journal XML document.
 */

namespace APP\plugins\importexport\fullJournalTransfer\filter\export;

use APP\core\Application;
use APP\facades\Repo;
use APP\journal\Journal;
use APP\plugins\importexport\fullJournalTransfer\classes\FullJournalFilterTrait;
use APP\plugins\importexport\fullJournalTransfer\classes\XmlText;
use APP\plugins\importexport\fullJournalTransfer\FullJournalImportExportDeployment;
use DOMDocument;
use DOMElement;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use PKP\announcement\Announcement;
use PKP\db\DAORegistry;
use PKP\plugins\importexport\native\filter\NativeExportFilter;
use PKP\plugins\importexport\users\PKPUserImportExportDeployment;
use PKP\site\VersionCheck;
use PKP\user\Collector as UserCollector;
use PKP\userGroup\relationships\enums\UserUserGroupStatus;
use Transliterator;

class JournalNativeXmlFilter extends NativeExportFilter
{
    use FullJournalFilterTrait;

    public function __construct($filterGroup)
    {
        $this->setDisplayName('Native XML journal export');
        parent::__construct($filterGroup);
    }

    public function getClassName(): string
    {
        return static::class;
    }

    /**
     * @param Journal $journal
     */
    public function &process(&$journal)
    {
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        $deployment = $this->getDeployment();

        $rootNode = $this->createJournalNode($doc, $journal);
        $doc->appendChild($rootNode);
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $rootNode->setAttribute('xsi:schemaLocation', $deployment->getNamespace() . ' ' . $deployment->getSchemaFilename());

        return $doc;
    }

    public function createJournalNode(DOMDocument $doc, Journal $journal): DOMElement
    {
        $deployment = $this->getFullJournalDeployment();
        $deployment->setContext($journal);

        echo __('plugins.importexport.fullJournal.exportingJournal', [
            'journalName' => $journal->getLocalizedName(),
        ]) . PHP_EOL;

        $journalNode = $doc->createElementNS($deployment->getNamespace(), 'journal');
        $journalNode->setAttribute('url_path', $journal->getPath());
        $journalNode->setAttribute('primary_locale', $journal->getPrimaryLocale());
        $journalNode->setAttribute('enabled', $journal->getEnabled() ? '1' : '0');
        $journalNode->setAttribute('seq', (string) (int) $journal->getSequence());
        $journalNode->setAttribute('source_id', (string) $journal->getId());
        $journalNode->setAttribute('ojs_version', (string) VersionCheck::getCurrentCodeVersion()->getVersionString(false));
        $journalNode->setAttribute('exported', date('Y-m-d H:i:s'));

        $this->addSettings($doc, $journalNode, $journal);
        $this->addPlugins($doc, $journalNode, $journal);
        $this->addNavigationMenuItems($doc, $journalNode, $journal);
        $this->addNavigationMenus($doc, $journalNode, $journal);
        $this->addEmailTemplates($doc, $journalNode, $journal);
        $this->addUserGroups($doc, $journalNode, $journal);
        $this->addUsers($doc, $journalNode, $journal);
        $this->addGenres($doc, $journalNode, $journal);
        $this->addReviewForms($doc, $journalNode, $journal);
        $this->addSections($doc, $journalNode, $journal);
        $this->addCategories($doc, $journalNode, $journal);
        $this->addSubEditorGroups($doc, $journalNode, $journal);
        $this->addHighlights($doc, $journalNode, $journal);
        $this->addInstitutions($doc, $journalNode, $journal);
        $this->addAnnouncementTypes($doc, $journalNode, $journal);
        $this->addAnnouncements($doc, $journalNode, $journal);
        $this->addDois($doc, $journalNode, $journal);
        $this->addIssues($doc, $journalNode, $journal);
        $this->addArticles($doc, $journalNode, $journal);
        $this->addLibraryFiles($doc, $journalNode, $journal);
        $this->addMetricsMarker($doc, $journalNode);
        $this->addPublicFilesMarker($doc, $journalNode, $journal);

        return $journalNode;
    }

    /**
     * All rows of journal_settings are exported verbatim. This covers every
     * setting of the context schema (including plugin-added ones) without
     * maintaining a list, and the values can be re-inserted as they are.
     */
    public function addSettings(DOMDocument $doc, DOMElement $journalNode, Journal $journal): void
    {
        $deployment = $this->getDeployment();
        $rows = DB::table('journal_settings')
            ->where('journal_id', (int) $journal->getId())
            ->orderBy('setting_name')
            ->orderBy('locale')
            ->get();

        foreach ($rows as $row) {
            if ($row->setting_value === null) {
                continue;
            }
            $node = $doc->createElementNS($deployment->getNamespace(), 'setting');
            $node->setAttribute('name', $row->setting_name);
            if ((string) $row->locale !== '') {
                $node->setAttribute('locale', $row->locale);
            }
            $node->appendChild($doc->createTextNode(XmlText::sanitize((string) $row->setting_value)));
            $journalNode->appendChild($node);
        }
    }

    /**
     * User groups (roles) of the journal with all their columns and settings.
     * The user_groups element of the users XML only carries a subset (e.g. no
     * permit_settings and no locale keys); the native user import finds the
     * groups created from this element by name.
     */
    public function addUserGroups(DOMDocument $doc, DOMElement $journalNode, Journal $journal): void
    {
        $deployment = $this->getDeployment();
        $groups = DB::table('user_groups')
            ->where('context_id', (int) $journal->getId())
            ->orderBy('user_group_id')
            ->get();
        if ($groups->isEmpty()) {
            return;
        }
        $groupIds = $groups->pluck('user_group_id')->all();
        $settings = DB::table('user_group_settings')
            ->whereIn('user_group_id', $groupIds)
            ->orderBy('setting_name')
            ->orderBy('locale')
            ->get()
            ->groupBy('user_group_id');
        $stages = DB::table('user_group_stage')
            ->whereIn('user_group_id', $groupIds)
            ->orderBy('stage_id')
            ->get()
            ->groupBy('user_group_id');

        $groupsNode = $doc->createElementNS($deployment->getNamespace(), 'journal_user_groups');
        foreach ($groups as $group) {
            $node = $doc->createElementNS($deployment->getNamespace(), 'journal_user_group');
            $node->setAttribute('id', (string) $group->user_group_id);
            $node->setAttribute('role_id', (string) $group->role_id);
            foreach (['is_default', 'show_title', 'permit_self_registration', 'permit_metadata_edit', 'permit_settings', 'masthead'] as $column) {
                if (property_exists($group, $column)) {
                    $node->setAttribute($column, (string) (int) $group->$column);
                }
            }
            $stageIds = ($stages[$group->user_group_id] ?? collect())->pluck('stage_id')->all();
            $node->setAttribute('stages', implode(':', $stageIds));
            foreach ($settings[$group->user_group_id] ?? [] as $row) {
                if ($row->setting_value === null) {
                    continue;
                }
                $settingNode = $doc->createElementNS($deployment->getNamespace(), 'setting');
                $settingNode->setAttribute('name', $row->setting_name);
                if ((string) $row->locale !== '') {
                    $settingNode->setAttribute('locale', $row->locale);
                }
                $settingNode->appendChild($doc->createTextNode(XmlText::sanitize((string) $row->setting_value)));
                $node->appendChild($settingNode);
            }
            $groupsNode->appendChild($node);
        }
        $journalNode->appendChild($groupsNode);
    }

    /**
     * Plugin settings of the journal (raw rows of plugin_settings, so that settings
     * of plugins that are not loadable at export time are kept as well).
     */
    public function addPlugins(DOMDocument $doc, DOMElement $journalNode, Journal $journal): void
    {
        $deployment = $this->getDeployment();
        $rows = DB::table('plugin_settings')
            ->where('context_id', (int) $journal->getId())
            ->orderBy('plugin_name')
            ->orderBy('setting_name')
            ->get();

        $pluginsNode = $doc->createElementNS($deployment->getNamespace(), 'plugins');
        $pluginNodes = [];
        foreach ($rows as $row) {
            if (!isset($pluginNodes[$row->plugin_name])) {
                $pluginNode = $doc->createElementNS($deployment->getNamespace(), 'plugin');
                $pluginNode->setAttribute('plugin_name', $row->plugin_name);
                $pluginsNode->appendChild($pluginNode);
                $pluginNodes[$row->plugin_name] = $pluginNode;
            }
            $settingNode = $doc->createElementNS($deployment->getNamespace(), 'plugin_setting');
            $settingNode->setAttribute('setting_name', $row->setting_name);
            $settingNode->setAttribute('setting_type', (string) ($row->setting_type ?: 'string'));
            if ($row->setting_value !== null) {
                $settingNode->appendChild($doc->createTextNode(XmlText::sanitize((string) $row->setting_value)));
            }
            $pluginNodes[$row->plugin_name]->appendChild($settingNode);
        }
        $journalNode->appendChild($pluginsNode);
    }

    public function addNavigationMenuItems(DOMDocument $doc, DOMElement $journalNode, Journal $journal): void
    {
        $navigationMenuItemDao = DAORegistry::getDAO('NavigationMenuItemDAO'); /** @var \PKP\navigationMenu\NavigationMenuItemDAO $navigationMenuItemDao */
        $navigationMenuItems = $navigationMenuItemDao->getByContextId((int) $journal->getId())->toArray();
        if (empty($navigationMenuItems)) {
            return;
        }
        $this->appendExportedNode('navigation-menu-item=>native-xml', $navigationMenuItems, $doc, $journalNode);
    }

    public function addNavigationMenus(DOMDocument $doc, DOMElement $journalNode, Journal $journal): void
    {
        $navigationMenuDao = DAORegistry::getDAO('NavigationMenuDAO'); /** @var \PKP\navigationMenu\NavigationMenuDAO $navigationMenuDao */
        $navigationMenus = $navigationMenuDao->getByContextId((int) $journal->getId())->toArray();
        if (empty($navigationMenus)) {
            return;
        }
        $this->appendExportedNode('navigation-menu=>native-xml', $navigationMenus, $doc, $journalNode);
    }

    /**
     * Customized / alternate e-mail templates of the journal (raw rows).
     */
    public function addEmailTemplates(DOMDocument $doc, DOMElement $journalNode, Journal $journal): void
    {
        $deployment = $this->getDeployment();
        $templates = DB::table('email_templates')
            ->where('context_id', (int) $journal->getId())
            ->orderBy('email_key')
            ->get();
        if ($templates->isEmpty()) {
            return;
        }

        $templatesNode = $doc->createElementNS($deployment->getNamespace(), 'email_templates');
        foreach ($templates as $template) {
            $templateNode = $doc->createElementNS($deployment->getNamespace(), 'email_template');
            $templateNode->setAttribute('email_key', $template->email_key);
            $this->setOptionalAttribute($templateNode, 'alternate_to', $template->alternate_to);

            $settings = DB::table('email_templates_settings')
                ->where('email_id', $template->email_id)
                ->orderBy('setting_name')
                ->orderBy('locale')
                ->get();
            foreach ($settings as $setting) {
                if ($setting->setting_value === null) {
                    continue;
                }
                $settingNode = $doc->createElementNS($deployment->getNamespace(), 'setting');
                $settingNode->setAttribute('name', $setting->setting_name);
                if ((string) $setting->locale !== '') {
                    $settingNode->setAttribute('locale', $setting->locale);
                }
                $settingNode->appendChild($doc->createTextNode(XmlText::sanitize((string) $setting->setting_value)));
                $templateNode->appendChild($settingNode);
            }
            $templatesNode->appendChild($templateNode);
        }
        $journalNode->appendChild($templatesNode);
    }

    /**
     * Users: everybody with a (current or ended) role in the journal, plus users
     * without a role who are referenced by the editorial workflow (e.g. site
     * administrators who recorded decisions, uploaded files or wrote notes).
     */
    public function addUsers(DOMDocument $doc, DOMElement $journalNode, Journal $journal): void
    {
        echo __('plugins.importexport.fullJournal.exportingUsers') . PHP_EOL;
        $contextId = (int) $journal->getId();

        $users = [];
        $contextUsers = Repo::user()->getCollector()
            ->filterByContextIds([$contextId])
            ->filterByStatus(UserCollector::STATUS_ALL)
            ->filterByUserUserGroupStatus(UserUserGroupStatus::STATUS_ALL)
            ->getMany();
        foreach ($contextUsers as $user) {
            $users[$user->getId()] = $user;
        }

        $referencedUserIds = $this->getReferencedUserIds($contextId);
        $missingUserIds = array_values(array_diff($referencedUserIds, array_keys($users)));
        if (!empty($missingUserIds)) {
            foreach (array_chunk($missingUserIds, 500) as $chunk) {
                $extraUsers = Repo::user()->getCollector()
                    ->filterByUserIds($chunk)
                    ->filterByStatus(UserCollector::STATUS_ALL)
                    ->getMany();
                foreach ($extraUsers as $user) {
                    $users[$user->getId()] = $user;
                }
            }
        }

        $usersArray = array_values($users);
        echo '  ' . count($usersArray) . ' users' . PHP_EOL;

        $filter = $this->getSubFilterForGroup('user=>user-xml');
        $filter->setDeployment(new PKPUserImportExportDeployment($journal, null));
        $usersDoc = $filter->execute($usersArray, true);

        if ($usersDoc instanceof DOMDocument && $usersDoc->documentElement instanceof DOMElement) {
            $this->removeDuplicatedInterests($usersDoc);
            $clone = $doc->importNode($usersDoc->documentElement, true);
            $journalNode->appendChild($clone);
        }
    }

    /**
     * IDs of users that are referenced by the workflow of the journal's submissions.
     */
    protected function getReferencedUserIds(int $contextId): array
    {
        $submissionIds = fn (Builder $q) => $q->select('submission_id')->from('submissions')->where('context_id', $contextId);
        $queryIds = fn (Builder $q) => $q->select('query_id')->from('queries')
            ->where('assoc_type', Application::ASSOC_TYPE_SUBMISSION)
            ->whereIn('assoc_id', $submissionIds);

        $ids = collect()
            ->merge(DB::table('stage_assignments')->whereIn('submission_id', $submissionIds)->pluck('user_id'))
            ->merge(DB::table('edit_decisions')->whereIn('submission_id', $submissionIds)->pluck('editor_id'))
            ->merge(DB::table('review_assignments')->whereIn('submission_id', $submissionIds)->pluck('reviewer_id'))
            ->merge(DB::table('submission_files')->whereIn('submission_id', $submissionIds)->pluck('uploader_user_id'))
            ->merge(DB::table('submission_comments')->whereIn('submission_id', $submissionIds)->pluck('author_id'))
            ->merge(DB::table('query_participants')->whereIn('query_id', $queryIds)->pluck('user_id'))
            ->merge(DB::table('notes')->where('assoc_type', Application::ASSOC_TYPE_QUERY)->whereIn('assoc_id', $queryIds)->pluck('user_id'))
            ->merge(DB::table('event_log')->where('assoc_type', Application::ASSOC_TYPE_SUBMISSION)->whereIn('assoc_id', $submissionIds)->pluck('user_id'))
            ->merge(DB::table('email_log')->where('assoc_type', Application::ASSOC_TYPE_SUBMISSION)->whereIn('assoc_id', $submissionIds)->pluck('sender_id'))
            ->merge(
                DB::table('email_log_users')
                    ->whereIn('email_log_id', fn (Builder $q) => $q->select('log_id')->from('email_log')
                        ->where('assoc_type', Application::ASSOC_TYPE_SUBMISSION)
                        ->whereIn('assoc_id', $submissionIds))
                    ->pluck('user_id')
            )
            ->filter(fn ($id) => $id !== null && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        return $ids->all();
    }

    public function addGenres(DOMDocument $doc, DOMElement $journalNode, Journal $journal): void
    {
        $genreDao = DAORegistry::getDAO('GenreDAO'); /** @var \PKP\submission\GenreDAO $genreDao */
        $genres = $genreDao->getByContextId((int) $journal->getId())->toArray();
        $deployment = $this->getDeployment();

        if (!count($genres)) {
            return;
        }

        $genresNode = $doc->createElementNS($deployment->getNamespace(), 'genres');
        foreach ($genres as $genre) {
            $genreNode = $doc->createElementNS($deployment->getNamespace(), 'genre');

            $genreNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'id', (string) $genre->getId()));
            $node->setAttribute('type', 'internal');
            $node->setAttribute('advice', 'ignore');

            $genreNode->setAttribute('key', (string) $genre->getKey());
            $genreNode->setAttribute('category', (string) (int) $genre->getCategory());
            $genreNode->setAttribute('dependent', (string) (int) $genre->getDependent());
            $genreNode->setAttribute('supplementary', (string) (int) $genre->getSupplementary());
            $genreNode->setAttribute('required', (string) (int) $genre->getRequired());
            $genreNode->setAttribute('seq', (string) (int) $genre->getSequence());
            $genreNode->setAttribute('enabled', (string) (int) $genre->getEnabled());

            $this->createLocalizedNodes($doc, $genreNode, 'name', $genre->getName(null));

            $genresNode->appendChild($genreNode);
        }

        $journalNode->appendChild($genresNode);
    }

    public function addSections(DOMDocument $doc, DOMElement $journalNode, Journal $journal): void
    {
        $sections = Repo::section()->getCollector()
            ->filterByContextIds([(int) $journal->getId()])
            ->getMany()
            ->toArray();

        if (empty($sections)) {
            return;
        }

        echo __('plugins.importexport.fullJournal.exportingSections') . PHP_EOL;

        $deployment = $this->getDeployment();
        $sectionsNode = $doc->createElementNS($deployment->getNamespace(), 'journal_sections');

        foreach ($sections as $section) {
            $sectionNode = $doc->createElementNS($deployment->getNamespace(), 'journal_section');

            $idNode = $doc->createElementNS($deployment->getNamespace(), 'id', (string) $section->getId());
            $idNode->setAttribute('type', 'internal');
            $idNode->setAttribute('advice', 'ignore');
            $sectionNode->appendChild($idNode);

            if ($section->getReviewFormId()) {
                $sectionNode->setAttribute('review_form_id', (string) $section->getReviewFormId());
            }

            $sectionNode->setAttribute('ref', (string) $section->getAbbrev($journal->getPrimaryLocale()));
            $sectionNode->setAttribute('seq', (string) (int) $section->getSequence());
            $sectionNode->setAttribute('editor_restricted', (string) (int) $section->getEditorRestricted());
            $sectionNode->setAttribute('meta_indexed', (string) (int) $section->getMetaIndexed());
            $sectionNode->setAttribute('meta_reviewed', (string) (int) $section->getMetaReviewed());
            $sectionNode->setAttribute('abstracts_not_required', (string) (int) $section->getAbstractsNotRequired());
            $sectionNode->setAttribute('hide_title', (string) (int) $section->getHideTitle());
            $sectionNode->setAttribute('hide_author', (string) (int) $section->getHideAuthor());
            $sectionNode->setAttribute('abstract_word_count', (string) (int) $section->getAbstractWordCount());
            $sectionNode->setAttribute('is_inactive', (string) (int) $section->getIsInactive());

            $this->createLocalizedNodes($doc, $sectionNode, 'abbrev', $section->getAbbrev(null));
            $this->createLocalizedNodes($doc, $sectionNode, 'policy', $section->getPolicy(null));
            $this->createLocalizedNodes($doc, $sectionNode, 'title', $section->getTitle(null));
            $this->createLocalizedNodes($doc, $sectionNode, 'identify_type', $section->getIdentifyType(null));

            $sectionsNode->appendChild($sectionNode);
        }

        $journalNode->appendChild($sectionsNode);
    }

    public function addReviewForms(DOMDocument $doc, DOMElement $journalNode, Journal $journal): void
    {
        $reviewFormDao = DAORegistry::getDAO('ReviewFormDAO'); /** @var \PKP\reviewForm\ReviewFormDAO $reviewFormDao */
        $reviewForms = $reviewFormDao->getByAssocId(Application::ASSOC_TYPE_JOURNAL, (int) $journal->getId())->toArray();
        if (empty($reviewForms)) {
            return;
        }
        $this->appendExportedNode('review-form=>native-xml', $reviewForms, $doc, $journalNode);
    }

    //
    // Categories, section/category editors, highlights, institutions, library files
    //
    public function addCategories(DOMDocument $doc, DOMElement $journalNode, Journal $journal): void
    {
        $deployment = $this->getDeployment();
        $categories = DB::table('categories')
            ->where('context_id', (int) $journal->getId())
            ->orderBy('parent_id')
            ->orderBy('seq')
            ->orderBy('category_id')
            ->get();
        if ($categories->isEmpty()) {
            return;
        }
        $settings = DB::table('category_settings')
            ->whereIn('category_id', $categories->pluck('category_id')->all())
            ->orderBy('setting_name')
            ->orderBy('locale')
            ->get()
            ->groupBy('category_id');

        $categoriesNode = $doc->createElementNS($deployment->getNamespace(), 'categories');
        foreach ($categories as $category) {
            $node = $doc->createElementNS($deployment->getNamespace(), 'journal_category');
            $node->setAttribute('id', (string) $category->category_id);
            $this->setOptionalAttribute($node, 'parent_id', $category->parent_id);
            $node->setAttribute('seq', (string) (int) $category->seq);
            $node->setAttribute('path', (string) $category->path);
            $this->setOptionalAttribute($node, 'image', $category->image);
            $this->appendSettingNodes($doc, $node, $settings[$category->category_id] ?? []);
            $categoriesNode->appendChild($node);
        }
        $journalNode->appendChild($categoriesNode);
    }

    public function addSubEditorGroups(DOMDocument $doc, DOMElement $journalNode, Journal $journal): void
    {
        $deployment = $this->getDeployment();
        $rows = DB::table('subeditor_submission_group')
            ->where('context_id', (int) $journal->getId())
            ->orderBy('subeditor_submission_group_id')
            ->get();
        if ($rows->isEmpty()) {
            return;
        }
        $groupsNode = $doc->createElementNS($deployment->getNamespace(), 'subeditor_groups');
        foreach ($rows as $row) {
            $userEmail = $this->getUserEmailById((int) $row->user_id);
            if (!$userEmail) {
                continue;
            }
            $node = $doc->createElementNS($deployment->getNamespace(), 'subeditor_group');
            $node->setAttribute('assoc_type', (string) $row->assoc_type);
            $node->setAttribute('assoc_id', (string) $row->assoc_id);
            $node->setAttribute('user_email', $userEmail);
            $node->setAttribute('user_group_id', (string) $row->user_group_id);
            $groupsNode->appendChild($node);
        }
        $journalNode->appendChild($groupsNode);
    }

    public function addHighlights(DOMDocument $doc, DOMElement $journalNode, Journal $journal): void
    {
        $deployment = $this->getDeployment();
        if (!DB::getSchemaBuilder()->hasTable('highlights')) {
            return;
        }
        $highlights = DB::table('highlights')
            ->where('context_id', (int) $journal->getId())
            ->orderBy('sequence')
            ->orderBy('highlight_id')
            ->get();
        if ($highlights->isEmpty()) {
            return;
        }
        $settings = DB::table('highlight_settings')
            ->whereIn('highlight_id', $highlights->pluck('highlight_id')->all())
            ->orderBy('setting_name')
            ->orderBy('locale')
            ->get()
            ->groupBy('highlight_id');

        $highlightsNode = $doc->createElementNS($deployment->getNamespace(), 'highlights');
        foreach ($highlights as $highlight) {
            $node = $doc->createElementNS($deployment->getNamespace(), 'highlight');
            $node->setAttribute('id', (string) $highlight->highlight_id);
            $node->setAttribute('sequence', (string) (int) $highlight->sequence);
            $this->setOptionalAttribute($node, 'url', $highlight->url);
            $this->appendSettingNodes($doc, $node, $settings[$highlight->highlight_id] ?? []);
            $highlightsNode->appendChild($node);
        }
        $journalNode->appendChild($highlightsNode);
    }

    public function addInstitutions(DOMDocument $doc, DOMElement $journalNode, Journal $journal): void
    {
        $deployment = $this->getDeployment();
        $institutions = DB::table('institutions')
            ->where('context_id', (int) $journal->getId())
            ->orderBy('institution_id')
            ->get();
        if ($institutions->isEmpty()) {
            return;
        }
        $ids = $institutions->pluck('institution_id')->all();
        $settings = DB::table('institution_settings')->whereIn('institution_id', $ids)
            ->orderBy('setting_name')->orderBy('locale')->get()->groupBy('institution_id');
        $ips = DB::table('institution_ip')->whereIn('institution_id', $ids)
            ->orderBy('institution_ip_id')->get()->groupBy('institution_id');

        $institutionsNode = $doc->createElementNS($deployment->getNamespace(), 'institutions');
        foreach ($institutions as $institution) {
            $node = $doc->createElementNS($deployment->getNamespace(), 'institution');
            $node->setAttribute('id', (string) $institution->institution_id);
            $this->setOptionalAttribute($node, 'ror', $institution->ror);
            $this->setOptionalAttribute($node, 'deleted_at', $institution->deleted_at);
            $this->appendSettingNodes($doc, $node, $settings[$institution->institution_id] ?? []);
            foreach ($ips[$institution->institution_id] ?? [] as $ip) {
                $ipNode = $doc->createElementNS($deployment->getNamespace(), 'ip');
                $ipNode->setAttribute('ip_string', (string) $ip->ip_string);
                $this->setOptionalAttribute($ipNode, 'ip_start', $ip->ip_start);
                $this->setOptionalAttribute($ipNode, 'ip_end', $ip->ip_end);
                $node->appendChild($ipNode);
            }
            $institutionsNode->appendChild($node);
        }
        $journalNode->appendChild($institutionsNode);
    }

    /**
     * Publisher library files and submission library files (the files
     * themselves are in the files directory of the journal, which is copied).
     */
    public function addLibraryFiles(DOMDocument $doc, DOMElement $journalNode, Journal $journal): void
    {
        $deployment = $this->getDeployment();
        $files = DB::table('library_files')
            ->where('context_id', (int) $journal->getId())
            ->orderBy('file_id')
            ->get();
        if ($files->isEmpty()) {
            return;
        }
        $settings = DB::table('library_file_settings')
            ->whereIn('file_id', $files->pluck('file_id')->all())
            ->orderBy('setting_name')
            ->orderBy('locale')
            ->get()
            ->groupBy('file_id');

        $filesNode = $doc->createElementNS($deployment->getNamespace(), 'library_files');
        foreach ($files as $file) {
            $node = $doc->createElementNS($deployment->getNamespace(), 'library_file');
            $node->setAttribute('id', (string) $file->file_id);
            $node->setAttribute('file_name', (string) $file->file_name);
            // path of the file inside the archive (see FullJournalImportExportPlugin::createArchive())
            $node->setAttribute('src', FullJournalImportExportDeployment::CONTEXT_FILES_DIR . '/' . (int) $journal->getId() . '/library/' . $file->file_name);
            $this->setOptionalAttribute($node, 'original_file_name', $file->original_file_name);
            $this->setOptionalAttribute($node, 'file_type', $file->file_type);
            $this->setOptionalAttribute($node, 'file_size', $file->file_size);
            $this->setOptionalAttribute($node, 'type', $file->type);
            $this->setOptionalAttribute($node, 'date_uploaded', $file->date_uploaded);
            $this->setOptionalAttribute($node, 'date_modified', $file->date_modified);
            $this->setOptionalAttribute($node, 'submission_id', $file->submission_id);
            $node->setAttribute('public_access', (string) (int) $file->public_access);
            $this->appendSettingNodes($doc, $node, $settings[$file->file_id] ?? []);
            $filesNode->appendChild($node);
        }
        $journalNode->appendChild($filesNode);
    }

    public function addAnnouncementTypes(DOMDocument $doc, DOMElement $journalNode, Journal $journal): void
    {
        $announcementTypeDao = DAORegistry::getDAO('AnnouncementTypeDAO'); /** @var \PKP\announcement\AnnouncementTypeDAO $announcementTypeDao */
        $announcementTypes = iterator_to_array($announcementTypeDao->getByContextId((int) $journal->getId()), false);
        if (empty($announcementTypes)) {
            return;
        }
        $this->appendExportedNode('announcement-type=>native-xml', $announcementTypes, $doc, $journalNode);
    }

    public function addAnnouncements(DOMDocument $doc, DOMElement $journalNode, Journal $journal): void
    {
        $announcements = Announcement::withContextIds([(int) $journal->getId()])
            ->orderBy('date_posted')
            ->get()
            ->all();
        if (empty($announcements)) {
            return;
        }
        $this->appendExportedNode('announcement=>native-xml', $announcements, $doc, $journalNode);
    }

    /**
     * DOIs of the journal: status and settings (registration agency data). The
     * issues, publications and galleys reference them by identifier.
     */
    public function addDois(DOMDocument $doc, DOMElement $journalNode, Journal $journal): void
    {
        $deployment = $this->getDeployment();
        $dois = DB::table('dois')
            ->where('context_id', (int) $journal->getId())
            ->orderBy('doi_id')
            ->get();
        if ($dois->isEmpty()) {
            return;
        }
        $settings = DB::table('doi_settings')
            ->whereIn('doi_id', $dois->pluck('doi_id')->all())
            ->orderBy('setting_name')
            ->orderBy('locale')
            ->get()
            ->groupBy('doi_id');

        $doisNode = $doc->createElementNS($deployment->getNamespace(), 'dois');
        foreach ($dois as $doi) {
            $node = $doc->createElementNS($deployment->getNamespace(), 'doi_object');
            $node->setAttribute('id', (string) $doi->doi_id);
            $node->setAttribute('doi', (string) $doi->doi);
            $node->setAttribute('status', (string) (int) $doi->status);
            foreach ($settings[$doi->doi_id] ?? [] as $row) {
                if ($row->setting_value === null || $row->setting_name === 'resolvingUrl') {
                    continue;
                }
                $settingNode = $doc->createElementNS($deployment->getNamespace(), 'setting');
                $settingNode->setAttribute('name', $row->setting_name);
                if ((string) $row->locale !== '') {
                    $settingNode->setAttribute('locale', $row->locale);
                }
                $settingNode->appendChild($doc->createTextNode(XmlText::sanitize((string) $row->setting_value)));
                $node->appendChild($settingNode);
            }
            $doisNode->appendChild($node);
        }
        $journalNode->appendChild($doisNode);
    }

    public function addIssues(DOMDocument $doc, DOMElement $journalNode, Journal $journal): void
    {
        echo __('plugins.importexport.fullJournal.exportingIssues') . PHP_EOL;

        $issues = Repo::issue()->getCollector()
            ->filterByContextIds([(int) $journal->getId()])
            ->getMany()
            ->toArray();

        if (empty($issues)) {
            return;
        }

        $this->appendExportedNode('extended-issue=>native-xml', array_values($issues), $doc, $journalNode);
    }

    /**
     * Articles that are not assigned to any issue (unpublished, declined, in the workflow).
     * Articles assigned to an issue are exported inside the issue.
     */
    public function addArticles(DOMDocument $doc, DOMElement $journalNode, Journal $journal): void
    {
        echo __('plugins.importexport.fullJournal.exportingArticles') . PHP_EOL;

        $deployment = $this->getFullJournalDeployment();
        $submissions = Repo::submission()->getCollector()
            ->filterByContextIds([(int) $journal->getId()])
            ->getMany();

        $submissionsArray = [];
        foreach ($submissions as $submission) {
            if ($deployment->getSubmissionExportIssueId($submission)) {
                continue; // exported with the issue
            }
            if ($error = $deployment->getSubmissionValidationError($submission)) {
                echo '  ' . __('plugins.importexport.fullJournal.warning.submissionSkipped', [
                    'submissionId' => $submission->getId(),
                    'reason' => $error,
                ]) . PHP_EOL;
                continue;
            }
            $submissionsArray[] = $submission;
        }

        if (empty($submissionsArray)) {
            return;
        }

        $filter = $this->getSubFilter('extended-article=>native-xml');
        $filter->setIncludeSubmissionsNode(true);
        $articlesDoc = $filter->execute($submissionsArray, true);
        if ($articlesDoc instanceof DOMDocument && $articlesDoc->documentElement instanceof DOMElement) {
            $clone = $doc->importNode($articlesDoc->documentElement, true);
            $journalNode->appendChild($clone);
        }
    }

    /**
     * The usage statistics are stored as CSV files in the archive (see
     * classes/MetricsExporter.php); the XML only carries the marker.
     */
    public function addMetricsMarker(DOMDocument $doc, DOMElement $journalNode): void
    {
        if (!empty($this->opts['no-metrics'])) {
            return;
        }
        $node = $doc->createElementNS($this->getDeployment()->getNamespace(), 'metrics');
        $node->setAttribute('src', FullJournalImportExportDeployment::METRICS_DIR);
        $journalNode->appendChild($node);
    }

    /**
     * The public files (logos, favicon, stylesheet, covers, announcement images)
     * are stored under public/journals/<id> in the archive.
     */
    public function addPublicFilesMarker(DOMDocument $doc, DOMElement $journalNode, Journal $journal): void
    {
        if (!empty($this->opts['no-public-files'])) {
            return;
        }
        $node = $doc->createElementNS($this->getDeployment()->getNamespace(), 'public_files');
        $node->setAttribute('src', FullJournalImportExportDeployment::PUBLIC_FILES_DIR . '/journals/' . (int) $journal->getId());
        $journalNode->appendChild($node);
    }

    /**
     * Get a filter of a group that does not belong to this plugin (users plugin).
     */
    protected function getSubFilterForGroup(string $filterGroup)
    {
        return $this->getSubFilter($filterGroup);
    }

    /**
     * Reviewing interests are a controlled vocabulary; entries that differ only in
     * accents/case would be rejected as duplicates by the import.
     */
    private function removeDuplicatedInterests(DOMDocument $usersDoc): void
    {
        $deployment = $this->getDeployment();
        $userNodes = $usersDoc->getElementsByTagNameNS($deployment->getNamespace(), 'user');
        foreach ($userNodes as $userNode) {
            $interestNodeList = $userNode->getElementsByTagNameNS($deployment->getNamespace(), 'review_interests');
            if ($interestNodeList->length == 1) {
                $node = $interestNodeList->item(0);
                if ($node) {
                    $interests = preg_split('/,\s*/', $node->textContent);
                    $uniqueInterests = array_intersect_key(
                        $interests,
                        array_unique(array_map([$this, 'removeAccents'], $interests))
                    );
                    $node->nodeValue = htmlspecialchars(implode(', ', $uniqueInterests), ENT_COMPAT, 'UTF-8');
                }
            }
        }
    }

    public function removeAccents(string $string): string
    {
        $transliterator = Transliterator::createFromRules(
            ':: Any-Latin; :: Latin-ASCII; :: NFD; :: [:Nonspacing Mark:] Remove; :: NFC;',
            Transliterator::FORWARD
        );
        $normalized = $transliterator ? $transliterator->transliterate($string) : $string;
        return mb_strtolower((string) $normalized);
    }
}
