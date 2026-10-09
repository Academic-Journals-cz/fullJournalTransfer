<?php

/**
 * @file plugins/importexport/fullJournalTransfer/classes/ActivityLog.php
 *
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ActivityLog
 *
 * @brief Export / import of the activity log of a submission: the event log
 *  (event_log + event_log_settings) and the e-mail log (email_log + email_log_users).
 *
 *  The rows are transferred verbatim; only the IDs stored in them are remapped
 *  to the IDs of the new installation. Users are matched by e-mail.
 */

namespace APP\plugins\importexport\fullJournalTransfer\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\importexport\fullJournalTransfer\FullJournalImportExportDeployment;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\DB;
use PKP\core\PKPApplication;

class ActivityLog
{
    /** event_log_settings whose value is a user ID */
    public const USER_ID_SETTINGS = ['userId', 'editorId', 'senderId', 'recipientId'];

    /** event_log_settings whose value is a submission ID */
    public const SUBMISSION_ID_SETTINGS = ['submissionId'];

    /** event_log_settings whose value is a submission file ID */
    public const SUBMISSION_FILE_ID_SETTINGS = ['submissionFileId', 'sourceSubmissionFileId'];

    /** event_log_settings whose value is a file (revision) ID */
    public const FILE_ID_SETTINGS = ['fileId'];

    /** event_log_settings whose value is a review assignment ID */
    public const REVIEW_ASSIGNMENT_ID_SETTINGS = ['reviewAssignmentId'];

    //
    // Export
    //

    /**
     * Create the <event_log> node of a submission (null if there are no entries).
     */
    public static function createEventLogNode(DOMDocument $doc, FullJournalImportExportDeployment $deployment, int $submissionId, array $submissionFileIds): ?DOMElement
    {
        $namespace = $deployment->getNamespace();

        $query = DB::table('event_log')
            ->where(function ($q) use ($submissionId, $submissionFileIds) {
                $q->where(function ($q) use ($submissionId) {
                    $q->where('assoc_type', Application::ASSOC_TYPE_SUBMISSION)->where('assoc_id', $submissionId);
                });
                if (!empty($submissionFileIds)) {
                    $q->orWhere(function ($q) use ($submissionFileIds) {
                        $q->where('assoc_type', Application::ASSOC_TYPE_SUBMISSION_FILE)->whereIn('assoc_id', $submissionFileIds);
                    });
                }
            })
            ->orderBy('log_id');

        $rows = $query->get();
        if ($rows->isEmpty()) {
            return null;
        }

        $logNode = $doc->createElementNS($namespace, 'event_log');
        foreach ($rows as $row) {
            $entryNode = $doc->createElementNS($namespace, 'event');
            $entryNode->setAttribute('assoc_type', (string) $row->assoc_type);
            $entryNode->setAttribute('assoc_id', (string) $row->assoc_id);
            $entryNode->setAttribute('date_logged', (string) $row->date_logged);
            if ($row->event_type !== null) {
                $entryNode->setAttribute('event_type', (string) $row->event_type);
            }
            if ($row->is_translated !== null) {
                $entryNode->setAttribute('is_translated', $row->is_translated ? '1' : '0');
            }
            if ($row->user_id && ($email = self::getUserEmail((int) $row->user_id))) {
                $entryNode->setAttribute('user_email', $email);
            }
            if ($row->message !== null) {
                $entryNode->appendChild($doc->createElementNS($namespace, 'message'))
                    ->appendChild($doc->createTextNode(XmlText::sanitize((string) $row->message)));
            }

            $settings = DB::table('event_log_settings')
                ->where('log_id', $row->log_id)
                ->orderBy('setting_name')
                ->orderBy('locale')
                ->get();
            foreach ($settings as $setting) {
                if ($setting->setting_value === null) {
                    continue;
                }
                $settingNode = $doc->createElementNS($namespace, 'setting');
                $settingNode->setAttribute('name', $setting->setting_name);
                if ((string) $setting->locale !== '') {
                    $settingNode->setAttribute('locale', $setting->locale);
                }
                if (in_array($setting->setting_name, self::USER_ID_SETTINGS) && ($email = self::getUserEmail((int) $setting->setting_value))) {
                    $settingNode->setAttribute('user_email', $email);
                }
                $settingNode->appendChild($doc->createTextNode(XmlText::sanitize((string) $setting->setting_value)));
                $entryNode->appendChild($settingNode);
            }
            $logNode->appendChild($entryNode);
        }
        return $logNode;
    }

    /**
     * Create the <email_log> node of a submission (null if there are no entries).
     */
    public static function createEmailLogNode(DOMDocument $doc, FullJournalImportExportDeployment $deployment, int $submissionId): ?DOMElement
    {
        $namespace = $deployment->getNamespace();
        $rows = DB::table('email_log')
            ->where('assoc_type', Application::ASSOC_TYPE_SUBMISSION)
            ->where('assoc_id', $submissionId)
            ->orderBy('log_id')
            ->get();
        if ($rows->isEmpty()) {
            return null;
        }

        $logNode = $doc->createElementNS($namespace, 'email_log');
        foreach ($rows as $row) {
            $entryNode = $doc->createElementNS($namespace, 'email');
            $entryNode->setAttribute('date_sent', (string) $row->date_sent);
            if ($row->event_type !== null) {
                $entryNode->setAttribute('event_type', (string) $row->event_type);
            }
            if ($row->sender_id && ($email = self::getUserEmail((int) $row->sender_id))) {
                $entryNode->setAttribute('sender_email', $email);
            }
            foreach (['from_address', 'recipients', 'cc_recipients', 'bcc_recipients', 'subject', 'body'] as $column) {
                if ($row->{$column} === null) {
                    continue;
                }
                $entryNode->appendChild($doc->createElementNS($namespace, $column))
                    ->appendChild($doc->createTextNode(XmlText::sanitize((string) $row->{$column})));
            }
            $userIds = DB::table('email_log_users')->where('email_log_id', $row->log_id)->pluck('user_id');
            foreach ($userIds as $userId) {
                if ($email = self::getUserEmail((int) $userId)) {
                    $entryNode->appendChild($doc->createElementNS($namespace, 'log_user'))
                        ->appendChild($doc->createTextNode($email));
                }
            }
            $logNode->appendChild($entryNode);
        }
        return $logNode;
    }

    //
    // Import
    //

    /**
     * Delete the log entries that were generated while importing the submission
     * (file uploads, publication edits, ...) so that the original log can be restored.
     */
    public static function deleteGeneratedEventLog(int $submissionId, array $submissionFileIds): int
    {
        $deleted = DB::table('event_log')
            ->where('assoc_type', Application::ASSOC_TYPE_SUBMISSION)
            ->where('assoc_id', $submissionId)
            ->delete();
        if (!empty($submissionFileIds)) {
            $deleted += DB::table('event_log')
                ->where('assoc_type', Application::ASSOC_TYPE_SUBMISSION_FILE)
                ->whereIn('assoc_id', $submissionFileIds)
                ->delete();
        }
        return $deleted;
    }

    /**
     * Import the <event_log> node of a submission.
     *
     * @return int Number of imported entries
     */
    public static function importEventLog(DOMElement $logNode, FullJournalImportExportDeployment $deployment, int $newSubmissionId): int
    {
        $namespace = $deployment->getNamespace();
        $imported = 0;

        for ($entryNode = $logNode->firstChild; $entryNode !== null; $entryNode = $entryNode->nextSibling) {
            if (!$entryNode instanceof DOMElement || $entryNode->tagName !== 'event') {
                continue;
            }

            $assocType = (int) $entryNode->getAttribute('assoc_type');
            $oldAssocId = $entryNode->getAttribute('assoc_id');
            if ($assocType === Application::ASSOC_TYPE_SUBMISSION) {
                $assocId = $newSubmissionId;
            } elseif ($assocType === Application::ASSOC_TYPE_SUBMISSION_FILE) {
                $assocId = $deployment->getSubmissionFileDBId($oldAssocId);
                if (!$assocId) {
                    $deployment->incrementCounter('event log entries skipped (file not imported)');
                    continue;
                }
            } else {
                continue;
            }

            $userId = null;
            if ($email = $entryNode->getAttribute('user_email')) {
                $user = Repo::user()->getByEmail($email, true);
                $userId = $user ? (int) $user->getId() : null;
            }

            $messageNode = null;
            $settingNodes = [];
            foreach ($entryNode->childNodes as $child) {
                if (!$child instanceof DOMElement) {
                    continue;
                }
                if ($child->tagName === 'message') {
                    $messageNode = $child;
                } elseif ($child->tagName === 'setting') {
                    $settingNodes[] = $child;
                }
            }

            $logId = DB::table('event_log')->insertGetId([
                'assoc_type' => $assocType,
                'assoc_id' => $assocId,
                'user_id' => $userId,
                'date_logged' => self::normalizeDateTime($entryNode->getAttribute('date_logged')),
                'event_type' => $entryNode->hasAttribute('event_type') ? (int) $entryNode->getAttribute('event_type') : null,
                'message' => $messageNode ? $messageNode->textContent : null,
                'is_translated' => $entryNode->hasAttribute('is_translated') ? (int) $entryNode->getAttribute('is_translated') : null,
            ], 'log_id');

            foreach ($settingNodes as $settingNode) {
                $name = $settingNode->getAttribute('name');
                $value = $settingNode->textContent;
                $mapped = self::mapSettingValue($name, $value, $settingNode->getAttribute('user_email'), $deployment, $newSubmissionId);
                if ($mapped === false) {
                    continue; // reference that cannot be resolved in the new installation
                }
                DB::table('event_log_settings')->insert([
                    'log_id' => $logId,
                    'locale' => $settingNode->getAttribute('locale') ?: '',
                    'setting_name' => $name,
                    'setting_value' => $mapped,
                ]);
            }
            $imported++;
        }

        return $imported;
    }

    /**
     * Map an event log setting value to the new installation.
     *
     * @return string|false The new value, or false if the referenced object does not exist
     */
    protected static function mapSettingValue(string $name, string $value, string $userEmail, FullJournalImportExportDeployment $deployment, int $newSubmissionId): string|false
    {
        if (in_array($name, self::USER_ID_SETTINGS)) {
            $user = $userEmail ? Repo::user()->getByEmail($userEmail, true) : null;
            return $user ? (string) $user->getId() : false;
        }
        if (in_array($name, self::SUBMISSION_ID_SETTINGS)) {
            return (string) $newSubmissionId;
        }
        if (in_array($name, self::SUBMISSION_FILE_ID_SETTINGS)) {
            $newId = $deployment->getSubmissionFileDBId($value);
            return $newId ? (string) $newId : false;
        }
        if (in_array($name, self::FILE_ID_SETTINGS)) {
            $newId = $deployment->getFileDBId($value);
            return $newId ? (string) $newId : false;
        }
        if (in_array($name, self::REVIEW_ASSIGNMENT_ID_SETTINGS)) {
            $newId = $deployment->getReviewAssignmentDBId($value);
            return $newId ? (string) $newId : false;
        }
        return $value;
    }

    /**
     * Import the <email_log> node of a submission.
     *
     * @return int Number of imported entries
     */
    public static function importEmailLog(DOMElement $logNode, FullJournalImportExportDeployment $deployment, int $newSubmissionId): int
    {
        $imported = 0;
        for ($entryNode = $logNode->firstChild; $entryNode !== null; $entryNode = $entryNode->nextSibling) {
            if (!$entryNode instanceof DOMElement || $entryNode->tagName !== 'email') {
                continue;
            }

            $senderId = null;
            if ($email = $entryNode->getAttribute('sender_email')) {
                $sender = Repo::user()->getByEmail($email, true);
                $senderId = $sender ? (int) $sender->getId() : null;
            }

            $columns = [
                'assoc_type' => Application::ASSOC_TYPE_SUBMISSION,
                'assoc_id' => $newSubmissionId,
                'sender_id' => $senderId,
                'date_sent' => self::normalizeDateTime($entryNode->getAttribute('date_sent')),
                'event_type' => $entryNode->hasAttribute('event_type') ? (int) $entryNode->getAttribute('event_type') : null,
                'from_address' => null,
                'recipients' => null,
                'cc_recipients' => null,
                'bcc_recipients' => null,
                'subject' => null,
                'body' => null,
            ];
            $logUserEmails = [];
            foreach ($entryNode->childNodes as $child) {
                if (!$child instanceof DOMElement) {
                    continue;
                }
                if ($child->tagName === 'log_user') {
                    $logUserEmails[] = trim($child->textContent);
                } elseif (array_key_exists($child->tagName, $columns)) {
                    $columns[$child->tagName] = $child->textContent;
                }
            }
            if ($columns['subject'] !== null) {
                $columns['subject'] = mb_substr($columns['subject'], 0, 255);
            }

            $logId = DB::table('email_log')->insertGetId($columns, 'log_id');

            foreach (array_unique($logUserEmails) as $logUserEmail) {
                $user = $logUserEmail ? Repo::user()->getByEmail($logUserEmail, true) : null;
                if ($user) {
                    DB::table('email_log_users')->updateOrInsert(['email_log_id' => $logId, 'user_id' => (int) $user->getId()]);
                }
            }
            $imported++;
        }
        return $imported;
    }

    protected static function normalizeDateTime(?string $value): string
    {
        $timestamp = $value ? strtotime($value) : false;
        return date('Y-m-d H:i:s', $timestamp === false ? time() : $timestamp);
    }

    /** @var array<int,?string> */
    private static array $userEmails = [];

    protected static function getUserEmail(int $userId): ?string
    {
        if (!$userId) {
            return null;
        }
        if (!array_key_exists($userId, self::$userEmails)) {
            $user = Repo::user()->get($userId, true);
            self::$userEmails[$userId] = $user ? $user->getEmail() : null;
        }
        return self::$userEmails[$userId];
    }
}
