<?php

/**
 * @file plugins/importexport/fullJournalTransfer/FullJournalImportExportPlugin.php
 *
 * Copyright (c) 2014-2024 Lepidus Tecnologia
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class FullJournalImportExportPlugin
 *
 * @brief Full journal transfer plugin for OJS 3.5 (CLI only).
 *
 *  Export: php tools/importExport.php FullJournalImportExportPlugin export <archive.tar.gz> <journal_path> [--no-metrics] [--no-activity-log] [--no-public-files]
 *  Import: php tools/importExport.php FullJournalImportExportPlugin import <archive.tar.gz> <user_name> [--no-metrics] [--no-activity-log] [--no-public-files] [--journal-path <path>]
 */

namespace APP\plugins\importexport\fullJournalTransfer;

use APP\core\Application;
use APP\facades\Repo;
use APP\file\LibraryFileManager;
use APP\file\PublicFileManager;
use APP\journal\Journal;
use APP\plugins\importexport\fullJournalTransfer\classes\FilterInstaller;
use APP\plugins\importexport\fullJournalTransfer\classes\MetricsExporter;
use APP\plugins\importexport\fullJournalTransfer\classes\TarArchive;
use APP\template\TemplateManager;
use Exception;
use Illuminate\Support\Facades\DB;
use PKP\core\Core;
use PKP\core\Registry;
use PKP\db\DAORegistry;
use PKP\facades\Locale;
use PKP\file\ContextFileManager;
use PKP\file\FileManager;
use PKP\file\TemporaryFileManager;
use PKP\plugins\importexport\native\PKPNativeImportExportCLIToolKit;
use PKP\plugins\importexport\native\PKPNativeImportExportPlugin;
use PKP\plugins\importexport\PKPImportExportFilter;
use PKP\plugins\PluginRegistry;
use Throwable;

class FullJournalImportExportPlugin extends PKPNativeImportExportPlugin
{
    /** Options understood on the command line (see usage) */
    public const CLI_OPTIONS = ['no-embed', 'use-file-urls', 'no-metrics', 'no-activity-log', 'no-public-files', 'journal-path:'];

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success) {
            // The parent only loads the locale data when the plugin is "enabled";
            // import/export plugins have no enable switch, so always load it.
            $this->addLocaleData();
        }
        return $success;
    }

    public function getName()
    {
        return 'FullJournalImportExportPlugin';
    }

    public function getDisplayName()
    {
        return __('plugins.importexport.fullJournal.displayName');
    }

    public function getDescription()
    {
        return __('plugins.importexport.fullJournal.description');
    }

    /**
     * @copydoc ImportExportPlugin::getPluginSettingsPrefix()
     */
    public function getPluginSettingsPrefix()
    {
        return 'fullJournalTransfer';
    }

    /**
     * Only the CLI is supported. The web UI displays a notice.
     *
     * @see ImportExportPlugin::display()
     */
    public function display($args, $request)
    {
        // Intentionally do not call PKPNativeImportExportPlugin::display() which
        // sets up the submissions list panel - we only need the breadcrumbs.
        \PKP\plugins\ImportExportPlugin::display($args, $request);

        $templateMgr = TemplateManager::getManager($request);
        switch (array_shift($args)) {
            case '':
            case 'index':
            case 'export':
            case 'import':
                $templateMgr->display($this->getTemplateResource('index.tpl'));
                break;
            default:
                throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
    }

    /**
     * Make sure the filter groups / filters of this plugin are installed and
     * up to date before the parent installs them (upgrade of an older version).
     *
     * @copydoc Plugin::installFilters()
     */
    public function installFilters($hookName, $args)
    {
        try {
            (new FilterInstaller($this))->install();
        } catch (Throwable $e) {
            error_log('fullJournalTransfer: could not refresh the filter configuration: ' . $e->getMessage());
        }
        return parent::installFilters($hookName, $args);
    }

    /**
     * @copydoc ImportExportPlugin::executeCLI()
     */
    public function executeCLI($scriptName, &$args)
    {
        // Files are always referenced by path inside the archive, never embedded.
        $args[] = '--no-embed';
        $opts = $this->parseOpts($args, self::CLI_OPTIONS);
        $command = array_shift($args);
        $archivePath = array_shift($args);

        if (!in_array($command, ['import', 'export'])) {
            if ($command !== null) {
                $this->cliToolkit->echoCLIError('Unknown command "' . $command . '" (expected "export" or "import").');
            }
            $this->usage($scriptName);
            return true;
        }
        if (!$archivePath) {
            $this->cliToolkit->echoCLIError('The archive file name is missing.');
            $this->usage($scriptName);
            return true;
        }

        if (!preg_match('#^([a-zA-Z]:[\\\\/]|[\\\\/])#', $archivePath)) {
            // relative to the current directory (Windows drive letters and UNC paths are absolute)
            $archivePath = PWD . DIRECTORY_SEPARATOR . $archivePath;
        }

        // The filter configuration lives in the database. Plugins deployed by
        // copying the directory (git checkout) never run the installer, so make
        // sure that the groups/filters exist and are current.
        (new FilterInstaller($this))->install();

        // pubIds plugins are needed by the native filters (DOI etc.)
        PluginRegistry::loadCategory('pubIds', true);

        switch ($command) {
            case 'import':
                $userName = array_shift($args);
                if (!$userName) {
                    $this->cliToolkit->echoCLIError('The user name is missing: import [archive.tar.gz] [user_name]');
                    $this->usage($scriptName);
                    return true;
                }
                $user = Repo::user()->getByUsername($userName, true);
                if (!$user) {
                    $this->cliToolkit->echoCLIError(__('plugins.importexport.native.error.unknownUser'));
                    $this->usage($scriptName);
                    return true;
                }
                if (!is_readable($archivePath)) {
                    $this->cliToolkit->echoCLIError(__('plugins.importexport.common.export.error.inputFileNotReadable', ['param' => $archivePath]));
                    return true;
                }
                // Make the user available as the "current user" for all code paths that need one
                Registry::set('user', $user);

                return $this->importJournal($archivePath, $user, $opts);

            case 'export':
                $journalPath = array_shift($args);
                if (!$journalPath) {
                    $this->cliToolkit->echoCLIError('The journal path is missing: export [archive.tar.gz] [journal_path]');
                    $this->usage($scriptName);
                    return true;
                }
                $journal = Application::getContextDAO()->getByPath($journalPath);
                if (!$journal) {
                    $this->cliToolkit->echoCLIError(__('plugins.importexport.common.error.unknownContext', ['contextPath' => (string) $journalPath]));
                    $this->usage($scriptName);
                    return true;
                }
                $outputDir = dirname($archivePath);
                if (!is_dir($outputDir)) {
                    $this->cliToolkit->echoCLIError('The directory "' . $outputDir . '" of the archive does not exist (relative paths are resolved against the current directory ' . PWD . ').');
                    return true;
                }
                if (!is_writable($outputDir) || (file_exists($archivePath) && !is_writable($archivePath))) {
                    $this->cliToolkit->echoCLIError(__('plugins.importexport.common.export.error.outputFileNotWritable', ['param' => $archivePath]) . ' (user ' . (function_exists('posix_geteuid') && function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? posix_geteuid()) : 'running PHP') . ' has no write permission)');
                    return true;
                }
                return $this->exportJournal($journal, $archivePath, $opts);
        }

        $this->usage($scriptName);
        return true;
    }

    /**
     * Export a journal into a tar.gz archive.
     *
     * Archive layout:
     *   <journal_path>.xml                journal XML (this plugin's schema)
     *   journals/<id>/...                 files_dir content of the journal (submission files, issue galleys, ...)
     *   public/journals/<id>/...          public files (logos, favicon, stylesheet, covers, announcement images)
     *   metrics/<table>.csv               usage statistics tables
     */
    public function exportJournal(Journal $journal, string $archivePath, array $opts = []): bool
    {
        $startTime = microtime(true);
        $tempDir = $this->createTempDir('fjt-export-');
        $xmlPath = $tempDir . DIRECTORY_SEPARATOR . $journal->getPath() . '.xml';

        try {
            $deployment = new FullJournalImportExportDeployment($journal, null);
            $filter = $this->getJournalExportFilter($deployment, $opts);

            libxml_use_internal_errors(true);
            libxml_clear_errors();

            $journalDoc = $filter->execute($journal, true);
            $xml = $journalDoc ? $journalDoc->saveXml() : null;

            $validationErrors = array_filter(libxml_get_errors(), fn ($error) => in_array($error->level, [LIBXML_ERR_ERROR, LIBXML_ERR_FATAL]));
            libxml_clear_errors();

            $this->cliToolkit->getCLIProblems($deployment);

            if (empty($xml)) {
                $this->cliToolkit->echoCLIError('The export produced no XML document.');
                return true;
            }
            if (!empty($validationErrors)) {
                $this->printValidationErrors($validationErrors, $xml);
                // Keep the (invalid) XML next to the archive for inspection
                $debugPath = $archivePath . '.invalid.xml';
                file_put_contents($debugPath, $xml);
                $this->cliToolkit->echoCLIError('The exported XML does not validate against fullJournal.xsd. The document was saved to ' . $debugPath);
                return true;
            }

            if (file_put_contents($xmlPath, $xml) === false) {
                throw new Exception('Could not write ' . $xmlPath);
            }
            unset($xml, $journalDoc);

            // Usage statistics
            $metricsDir = null;
            if (empty($opts['no-metrics'])) {
                echo __('plugins.importexport.fullJournal.exportingMetrics') . PHP_EOL;
                $metricsDir = $tempDir . DIRECTORY_SEPARATOR . FullJournalImportExportDeployment::METRICS_DIR;
                $counts = (new MetricsExporter())->export((int) $journal->getId(), $metricsDir);
                foreach ($counts as $table => $count) {
                    echo "  {$table}: {$count}" . PHP_EOL;
                }
            }

            $contextFileManager = new ContextFileManager($journal->getId());
            $journalFilesDir = rtrim($contextFileManager->getBasePath(), '/');

            $publicFilesDir = null;
            if (empty($opts['no-public-files'])) {
                $publicFileManager = new PublicFileManager();
                $publicFilesDir = $publicFileManager->getContextFilesPath($journal->getId());
            }

            // publisher library files: files_dir/contexts/<id>/library
            $contextFilesDir = rtrim(dirname((new LibraryFileManager($journal->getId()))->getBasePath()), '/');

            echo __('plugins.importexport.fullJournal.creatingArchive') . PHP_EOL;
            $this->createArchive($archivePath, $xmlPath, $journalFilesDir, $publicFilesDir, $metricsDir, $contextFilesDir);

            if (!file_exists($archivePath)) {
                throw new Exception('The archive ' . $archivePath . ' was not created.');
            }

            echo __('plugins.importexport.fullJournal.exportCompleted') . PHP_EOL;
            echo '  ' . $archivePath . ' (' . $this->formatBytes(filesize($archivePath)) . ', ' . round(microtime(true) - $startTime) . ' s)' . PHP_EOL;
            return true;
        } catch (Throwable $e) {
            $this->cliToolkit->echoCLIError($e->getMessage());
            if (isset($deployment)) {
                $this->cliToolkit->getCLIProblems($deployment);
            }
            $pendingErrors = array_filter(libxml_get_errors(), fn ($error) => in_array($error->level, [LIBXML_ERR_ERROR, LIBXML_ERR_FATAL]));
            if (!empty($pendingErrors)) {
                echo 'XML validation errors of the last exported element:' . PHP_EOL;
                $this->printValidationErrors($pendingErrors, null);
            }
            fwrite(STDERR, get_class($e) . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
            return true;
        } finally {
            (new FileManager())->rmtree($tempDir);
        }
    }

    /**
     * Import a journal from a tar.gz archive created by exportJournal().
     */
    public function importJournal(string $archivePath, \PKP\user\User $user, array $opts = []): bool
    {
        $startTime = microtime(true);
        $extractDir = $this->createTempDir('fjt-import-');

        try {
            echo __('plugins.importexport.fullJournal.extractingArchive') . PHP_EOL;
            $this->extractArchive($archivePath, $extractDir);

            $xmlFile = null;
            foreach (scandir($extractDir) as $item) {
                if (strtolower(substr($item, -4)) == '.xml') {
                    $xmlFile = $extractDir . DIRECTORY_SEPARATOR . $item;
                    break;
                }
            }
            if (!$xmlFile) {
                throw new Exception('No XML file found in the archive.');
            }
            $xml = file_get_contents($xmlFile);
            if ($xml === false) {
                throw new Exception('Could not read ' . $xmlFile);
            }

            echo __('plugins.importexport.fullJournal.validatingXml') . PHP_EOL;
            if (!$this->validateXml($xml)) {
                $this->cliToolkit->echoCLIError(__('plugins.importexport.fullJournal.importFailed'));
                return true;
            }

            // The native submission file import writes the files to the temporary directory
            // of the files_dir first (falling back to the system temp dir with a notice).
            $temporaryFileManager = new TemporaryFileManager();
            if (!file_exists($temporaryFileManager->getBasePath())) {
                $temporaryFileManager->mkdirtree($temporaryFileManager->getBasePath());
            }

            // Placeholder context; the real journal is created by the journal import filter.
            $context = new Journal();
            $context->setId(0);
            $context->setPath('__import__');
            $context->setPrimaryLocale(Locale::getLocale());

            $deployment = new FullJournalImportExportDeployment($context, $user);
            $deployment->setImportPath($extractDir);
            $deployment->setImportOptions($opts);
            $filter = $this->getJournalImportFilter($deployment);

            libxml_use_internal_errors(true);
            libxml_clear_errors();

            $journal = null;
            $failed = false;
            DB::beginTransaction();
            try {
                $imported = $filter->execute($xml, true);
                $journal = is_array($imported) ? reset($imported) : null;

                $validationErrors = array_filter(libxml_get_errors(), fn ($error) => in_array($error->level, [LIBXML_ERR_ERROR, LIBXML_ERR_FATAL]));
                libxml_clear_errors();

                $foundErrors = !empty($validationErrors);
                foreach (array_keys($this->getReportedObjectTypes()) as $assocType) {
                    if (!empty($deployment->getProcessedObjectsErrors($assocType))) {
                        $foundErrors = true;
                    }
                }
                if (!$journal instanceof Journal) {
                    $foundErrors = true;
                    $this->cliToolkit->echoCLIError('The journal could not be imported (the journal import filter returned no journal).');
                }

                if ($foundErrors) {
                    $failed = true;
                    DB::rollBack();
                    if (!empty($validationErrors)) {
                        $this->printValidationErrors($validationErrors, $xml);
                    }
                } else {
                    DB::commit();
                }
            } catch (Throwable $e) {
                $failed = true;
                DB::rollBack();
                $this->cliToolkit->echoCLIError($e->getMessage());
                fwrite(STDERR, get_class($e) . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
            }

            $this->cliToolkit->getCLIProblems($deployment);

            if ($failed) {
                // Remove the files that were written for the (rolled back) journal
                $newContext = $deployment->getContext();
                if ($newContext && $newContext->getId()) {
                    $this->removeJournalDirectories((int) $newContext->getId());
                }
                $this->cliToolkit->echoCLIError(__('plugins.importexport.fullJournal.importFailed'));
                return true;
            }

            $this->printImportSummary($deployment);
            echo __('plugins.importexport.fullJournal.importCompleted') . PHP_EOL;
            echo '  ' . __('plugins.importexport.fullJournal.importedJournal', [
                'journalPath' => $journal->getPath(),
                'journalId' => $journal->getId(),
                'seconds' => round(microtime(true) - $startTime),
            ]) . PHP_EOL;
            return true;
        } catch (Throwable $e) {
            $this->cliToolkit->echoCLIError($e->getMessage());
            fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
            return true;
        } finally {
            (new FileManager())->rmtree($extractDir);
        }
    }

    /**
     * Validate the journal XML against fullJournal.xsd and print the problems.
     */
    public function validateXml(string $xml): bool
    {
        $schemaPath = Core::getBaseDir() . '/' . $this->getPluginPath() . '/fullJournal.xsd';
        libxml_use_internal_errors(true);
        libxml_clear_errors();

        $doc = new \DOMDocument('1.0', 'utf-8');
        $loaded = $doc->loadXML($xml, LIBXML_PARSEHUGE);
        $valid = $loaded && $doc->schemaValidate($schemaPath);

        $errors = array_filter(libxml_get_errors(), fn ($error) => in_array($error->level, [LIBXML_ERR_ERROR, LIBXML_ERR_FATAL]));
        libxml_clear_errors();

        if (!$valid) {
            $this->printValidationErrors($errors, $loaded ? $doc : null);
        }
        return $valid;
    }

    /**
     * Print XML validation errors, each with the article/issue/element the
     * erroneous line belongs to (found by the line numbers of the elements).
     *
     * @param \LibXMLError[] $errors
     * @param \DOMDocument|string|null $xml The validated document (for the context)
     */
    public function printValidationErrors(array $errors, $xml): void
    {
        echo __('plugins.importexport.common.validationErrors') . PHP_EOL;

        // Start lines of the elements that give context: top-level journal children, issues, articles
        $boundaries = [];
        $doc = null;
        if ($xml instanceof \DOMDocument) {
            $doc = $xml;
        } elseif (is_string($xml) && $xml !== '') {
            $doc = new \DOMDocument('1.0', 'utf-8');
            $previous = libxml_use_internal_errors(true);
            if (!$doc->loadXML($xml, LIBXML_PARSEHUGE)) {
                $doc = null;
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($doc && $doc->documentElement) {
            foreach ($doc->documentElement->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $boundaries[$child->getLineNo()] = 'element <' . $child->tagName . '>';
                }
            }
            foreach (['extended_issue' => 'issue', 'extended_article' => 'article'] as $tagName => $label) {
                foreach ($doc->getElementsByTagName($tagName) as $element) {
                    $id = '';
                    $title = '';
                    foreach ($element->childNodes as $child) {
                        if (!$child instanceof \DOMElement) {
                            continue;
                        }
                        if ($child->tagName === 'id' && $child->getAttribute('type') === 'internal') {
                            $id = trim($child->textContent);
                        } elseif ($child->tagName === 'issue_identification' || ($child->tagName === 'publication' && $title === '')) {
                            $titleNode = $child->getElementsByTagName('title')->item(0);
                            $title = $titleNode ? trim($titleNode->textContent) : '';
                        }
                    }
                    $boundaries[$element->getLineNo()] = $label . ($id !== '' ? ' ' . $id : '') . ($title !== '' ? ' "' . mb_substr($title, 0, 60) . '"' : '');
                }
            }
            ksort($boundaries);
        }
        $lines = array_keys($boundaries);

        foreach (array_values($errors) as $i => $error) {
            $context = '';
            if (!empty($lines)) {
                $found = null;
                foreach ($lines as $line) {
                    if ($line > $error->line) {
                        break;
                    }
                    $found = $line;
                }
                if ($found !== null) {
                    $context = ' [' . $boundaries[$found] . ']';
                }
            }
            echo ($i + 1) . '. Line ' . $error->line . ' Column ' . $error->column . ': ' . trim($error->message) . $context . PHP_EOL;
        }
    }

    /**
     * Object types whose errors abort the import.
     */
    protected function getReportedObjectTypes(): array
    {
        return [
            Application::ASSOC_TYPE_NONE => 'any',
            Application::ASSOC_TYPE_JOURNAL => 'journal',
            Application::ASSOC_TYPE_ISSUE => 'issue',
            Application::ASSOC_TYPE_SUBMISSION => 'submission',
            Application::ASSOC_TYPE_PUBLICATION => 'publication',
            Application::ASSOC_TYPE_SECTION => 'section',
            Application::ASSOC_TYPE_SUBMISSION_FILE => 'submission file',
            Application::ASSOC_TYPE_AUTHOR => 'author',
        ];
    }

    protected function printImportSummary(FullJournalImportExportDeployment $deployment): void
    {
        $counters = $deployment->getCounters();
        if (empty($counters)) {
            return;
        }
        echo __('plugins.importexport.fullJournal.importSummary') . PHP_EOL;
        ksort($counters);
        foreach ($counters as $name => $count) {
            echo "  {$name}: {$count}" . PHP_EOL;
        }
    }

    /**
     * Get the journal export filter, bound to the deployment.
     */
    public function getJournalExportFilter(FullJournalImportExportDeployment $deployment, array $opts = [])
    {
        return PKPImportExportFilter::getFilter('journal=>native-xml', $deployment, $opts);
    }

    /**
     * Get the journal import filter, bound to the deployment.
     */
    public function getJournalImportFilter(FullJournalImportExportDeployment $deployment)
    {
        $filter = PKPImportExportFilter::getFilter('native-xml=>journal', $deployment);
        // The document is validated explicitly by validateXml() before the import
        $inputType = $filter->getInputType();
        if ($inputType instanceof \PKP\xslt\XMLTypeDescription) {
            $inputType->setValidationStrategy(\PKP\xslt\XMLTypeDescription::XML_TYPE_DESCRIPTION_VALIDATE_NONE);
        }
        return $filter;
    }

    //
    // Archive handling
    //

    /**
     * Create the tar.gz archive (pure PHP, see classes/TarArchive.php).
     *
     * Archive layout:
     *   <journal_path>.xml, journals/<id>/..., contexts/<id>/library/..., public/journals/<id>/..., metrics/<table>.csv
     */
    public function createArchive(string $archivePath, string $xmlPath, string $journalFilesDir, ?string $publicFilesDir, ?string $metricsDir, ?string $contextFilesDir = null): void
    {
        if (file_exists($archivePath)) {
            unlink($archivePath);
        }
        $journalId = basename($journalFilesDir);

        $entries = [basename($xmlPath) => $xmlPath];
        if (is_dir($journalFilesDir)) {
            // e.g. /var/files/journals/5 => journals/5
            $entries[basename(dirname($journalFilesDir)) . '/' . $journalId] = $journalFilesDir;
        }
        if ($contextFilesDir && is_dir($contextFilesDir)) {
            // e.g. /var/files/contexts/5 (publisher library) => contexts/5
            $entries[FullJournalImportExportDeployment::CONTEXT_FILES_DIR . '/' . basename($contextFilesDir)] = $contextFilesDir;
        }
        if ($publicFilesDir && is_dir($publicFilesDir)) {
            // e.g. /var/www/public/journals/5 => public/journals/5
            $entries[FullJournalImportExportDeployment::PUBLIC_FILES_DIR . '/' . basename(dirname($publicFilesDir)) . '/' . basename($publicFilesDir)] = $publicFilesDir;
        }
        if ($metricsDir && is_dir($metricsDir)) {
            $entries[FullJournalImportExportDeployment::METRICS_DIR] = $metricsDir;
        }

        TarArchive::create($archivePath, $entries);
    }

    public function extractArchive(string $archivePath, string $extractDir): void
    {
        TarArchive::extract($archivePath, $extractDir);
    }

    protected function createTempDir(string $prefix): string
    {
        $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $dir = $base . DIRECTORY_SEPARATOR . uniqid($prefix, true);
        if (!mkdir($dir, 0770, true)) {
            throw new Exception('Could not create the temporary directory ' . $dir);
        }
        return $dir;
    }

    /**
     * Remove the files/public directories of a journal whose import was rolled back.
     */
    protected function removeJournalDirectories(int $journalId): void
    {
        $fileManager = new FileManager();
        $contextFileManager = new ContextFileManager($journalId);
        $filesDir = $contextFileManager->getBasePath();
        if (is_dir($filesDir)) {
            $fileManager->rmtree($filesDir);
        }
        $publicDir = (new PublicFileManager())->getContextFilesPath($journalId);
        if (is_dir($publicDir)) {
            $fileManager->rmtree($publicDir);
        }
        $libraryDir = dirname(rtrim((new LibraryFileManager($journalId))->getBasePath(), '/'));
        if (is_dir($libraryDir)) {
            $fileManager->rmtree($libraryDir);
        }
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'kB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        return round($value, $i ? 1 : 0) . ' ' . $units[$i];
    }

    /**
     * Pull out getopt style long options.
     */
    public function parseOpts(&$args, $optCodes)
    {
        $newArgs = [];
        $opts = [];
        $sticky = null;
        foreach ($args as $arg) {
            if ($sticky) {
                $opts[$sticky] = $arg;
                $sticky = null;
                continue;
            }
            if (substr($arg, 0, 2) != '--') {
                $newArgs[] = $arg;
                continue;
            }
            $opt = substr($arg, 2);
            if (in_array($opt, $optCodes)) {
                $opts[$opt] = true;
                continue;
            }
            // --option=value
            if (str_contains($opt, '=')) {
                [$name, $value] = explode('=', $opt, 2);
                if (in_array($name . ':', $optCodes)) {
                    $opts[$name] = $value;
                    continue;
                }
            }
            if (in_array($opt . ':', $optCodes)) {
                $sticky = $opt;
                continue;
            }
            $this->cliToolkit->echoCLIError('Unknown option --' . $opt);
        }
        $args = $newArgs;
        return $opts;
    }

    public function usage($scriptName)
    {
        echo __('plugins.importexport.fullJournal.cliUsage', [
            'scriptName' => $scriptName,
            'pluginName' => $this->getName(),
        ]) . PHP_EOL;
    }
}
