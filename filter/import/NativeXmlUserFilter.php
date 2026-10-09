<?php

/**
 * @file plugins/importexport/fullJournalTransfer/filter/import/NativeXmlUserFilter.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Copyright (c) 2014-2024 Lepidus Tecnologia
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NativeXmlUserFilter
 *
 * @brief User import for the journal transfer.
 *
 *  Users are identified by their e-mail address: a user that already exists on
 *  the target site (same e-mail) is reused and only gets the roles of the
 *  journal; a new user whose username is already taken gets a new username.
 *  The native users filter only handles the case "username and e-mail both
 *  match the same user", everything else is rejected, hence this wrapper.
 */

namespace APP\plugins\importexport\fullJournalTransfer\filter\import;

use APP\facades\Repo;
use APP\plugins\importexport\fullJournalTransfer\classes\FullJournalFilterTrait;
use APP\plugins\importexport\fullJournalTransfer\classes\UserImportExportDeployment;
use DOMElement;
use PKP\config\Config;
use PKP\plugins\importexport\users\filter\UserXmlPKPUserFilter;

class NativeXmlUserFilter extends UserXmlPKPUserFilter
{
    use FullJournalFilterTrait;

    /** @var bool whether the username of the user being imported differs from the exported one */
    private bool $usernameChanged = false;

    /** @var int number of users processed so far (progress output) */
    private int $importedCount = 0;

    public function __construct($filterGroup)
    {
        $this->setDisplayName('Native XML user import');
        parent::__construct($filterGroup);
    }

    public function getClassName(): string
    {
        return static::class;
    }

    /**
     * @copydoc UserXmlPKPUserFilter::parseUser()
     */
    public function parseUser($node)
    {
        $deployment = $this->getDeployment();
        $usernameNode = $this->firstChildElement($node, 'username');
        $emailNode = $this->firstChildElement($node, 'email');
        $username = $usernameNode ? trim($usernameNode->textContent) : '';
        $email = $emailNode ? trim($emailNode->textContent) : '';

        if ($email === '') {
            $this->addError('User "' . $username . '" has no e-mail address and was skipped.');
            return null;
        }

        $existingUser = Repo::user()->getByEmail($email, true);
        if ($existingUser) {
            // Reuse the existing account: the native filter requires the username to match
            if ($usernameNode && $existingUser->getUsername() !== $username) {
                $usernameNode->textContent = $existingUser->getUsername();
            }
        } else {
            // New account: make sure the username is free
            $baseUsername = $username !== '' ? $username : (strstr($email, '@', true) ?: 'user');
            $uniqueUsername = $this->generateUniqueUsername($baseUsername);
            if ($uniqueUsername !== $username && $usernameNode) {
                $usernameNode->textContent = $uniqueUsername;
            } elseif (!$usernameNode) {
                $usernameNode = $node->ownerDocument->createElementNS($deployment->getNamespace(), 'username');
                $usernameNode->appendChild($node->ownerDocument->createTextNode($uniqueUsername));
                $node->appendChild($usernameNode);
            }
        }

        $this->usernameChanged = $usernameNode ? trim($usernameNode->textContent) !== $username : true;
        $user = parent::parseUser($node);
        if (++$this->importedCount % 100 === 0) {
            echo '  ' . $this->importedCount . ' users...' . PHP_EOL;
        }

        // Remember how the username of the source installation maps to this site
        $journalDeployment = $deployment instanceof UserImportExportDeployment ? $deployment->getJournalDeployment() : null;
        $importedUser = Repo::user()->getByEmail($email, true);
        if ($journalDeployment && $importedUser) {
            if ($username !== '') {
                $journalDeployment->setMappedUsername($username, $importedUser->getUsername());
            }
            $journalDeployment->incrementCounter($existingUser ? 'users (existing accounts reused)' : 'users (new accounts)');
        }
        return $user;
    }

    /**
     * Keep the password hashes of the source installation; never generate a
     * new password (the native filter does that whenever password_needs_rehash()
     * is true - the case for every hash OJS 3.5 creates - and then sends an
     * e-mail for every user, which stalls the import on sites without mail).
     *
     * Hashes made by password_hash() are valid on any site. Legacy hashes
     * (sha1/md5 of username + password) are accepted at login and rehashed as
     * long as the username and the hashing algorithm did not change; otherwise
     * the user has to reset the password ("Forgot your password?"), which is
     * reported as a warning.
     *
     * @copydoc UserXmlPKPUserFilter::importUserPasswordValidation()
     */
    public function importUserPasswordValidation($userToImport, $encryption)
    {
        $passwordHash = (string) $userToImport->getPassword();
        if (!$encryption || $passwordHash === '') {
            return parent::importUserPasswordValidation($userToImport, $encryption);
        }
        if (Repo::user()->getByEmail($userToImport->getEmail(), true)) {
            return null; // existing account: the password is kept anyway
        }
        $userToImport->setPassword($passwordHash);
        if (password_get_info($passwordHash)['algo'] === null) {
            // legacy hash
            if ($this->usernameChanged || $encryption !== Config::getVar('security', 'encryption')) {
                $this->addError('User ' . $userToImport->getUsername() . ' (' . $userToImport->getEmail() . ') has a legacy password hash that is bound to the former user name / hashing algorithm; the user has to reset the password with "Forgot your password?".');
            }
        }
        return null;
    }

    /**
     * Derive a username that does not exist yet (invalid characters are removed).
     */
    public function generateUniqueUsername(string $baseUsername): string
    {
        $base = mb_strtolower(preg_replace('/[^A-Za-z0-9_\-]/', '', $baseUsername));
        if ($base === '' || $base === null) {
            $base = 'user';
        }
        $username = $base;
        $i = 1;
        while (Repo::user()->getByUsername($username, true)) {
            $username = $base . $i;
            if (++$i > 10000) {
                throw new \Exception('Unable to generate a unique username for ' . $baseUsername);
            }
        }
        return $username;
    }
}
