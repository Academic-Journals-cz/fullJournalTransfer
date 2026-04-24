<?php

/**
 * @file plugins/importexport/fullJournalTransfer/filter/import/NativeXmlUserFilter.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Copyright (c) 2014-2024 Lepidus Tecnologia
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NativeXmlUserFilter
 * @ingroup plugins_importexport_fullJournalTransfer
 *
 * @brief Class that converts a Native XML document to an user.
 */

namespace APP\plugins\importexport\fullJournalTransfer\filter\import;

use PKP\plugins\importexport\users\filter\UserXmlPKPUserFilter;
use PKP\db\DAORegistry;
use APP\facades\Repo;
use PKP\user\User;

class NativeXmlUserFilter extends UserXmlPKPUserFilter {

    public function __construct($filterGroup) {
        $this->setDisplayName('Native XML user import');
        parent::__construct($filterGroup);
    }

    public function getClassName(): string {
        return static::class;
    }

    public function parseUser($node) {
        $user = parent::parseUser($node);
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();

        $userByEmail = Repo::user()->getByEmail($user->getEmail(), true);

        if ($userByEmail) {
            $userGroups = Repo::userGroup()
                    ->getCollector()
                    ->filterByContextIds([(int) $context->getId()])
                    ->getMany();

            $userGroups = is_array($userGroups) ? $userGroups : $userGroups->all();

            $existingGroupIds = [];
            $existingGroups = Repo::userGroup()
                    ->getCollector()
                    ->filterByUserIds([(int) $userByEmail->getId()])
                    ->filterByContextIds([(int) $context->getId()])
                    ->getMany();

            foreach ($existingGroups as $existingGroup) {
                $existingGroupIds[(int) $existingGroup->getId()] = true;
            }

            $assignedInThisRun = [];

            $userGroupNodeList = $node->getElementsByTagNameNS(
                    $deployment->getNamespace(),
                    'user_group_ref'
            );

            if ($userGroupNodeList->length > 0) {
                for ($i = 0; $i < $userGroupNodeList->length; $i++) {
                    $n = $userGroupNodeList->item($i);
                    $groupRef = trim((string) $n->textContent);

                    foreach ($userGroups as $userGroup) {
                        $groupNames = $userGroup->getName(null) ?? [];

                        if (!in_array($groupRef, $groupNames, true)) {
                            continue;
                        }

                        $groupId = (int) $userGroup->getId();

                        if (isset($existingGroupIds[$groupId])) {
                            continue;
                        }

                        if (isset($assignedInThisRun[$groupId])) {
                            continue;
                        }

                        Repo::userGroup()->assignUserToGroup(
                                (int) $userByEmail->getId(),
                                $groupId
                        );

                        $assignedInThisRun[$groupId] = true;
                        $existingGroupIds[$groupId] = true;
                    }
                }
            }
        }

        return $user;
    }

    public function importUserPasswordValidation($userToImport, $encryption) {
        $password = parent::importUserPasswordValidation($userToImport, $encryption);

        $this->generateUsername($userToImport);

        return $password;
    }

    public function generateUsername($user) {
        $baseUsername = preg_replace('/[^A-Z0-9]/i', '', (string) $user->getUsername());

        if (!$baseUsername) {
            $baseUsername = strstr($user->getEmail(), '@', true) ?: 'user';
        }

        $username = $baseUsername;
        $i = 1;

        while (true) {
            $existingUser = Repo::user()->getByUsername($username, true);

            if (!$existingUser) {
                break;
            }
            if ($user->getId() && $existingUser->getId() == $user->getId()) {
                break;
            }

            $username = $baseUsername . $i;
            $i++;

            if ($i > 1000) {
                throw new \Exception(
                                'Unable to generate unique username for: ' . $baseUsername
                        );
            }
        }

        $user->setUsername($username);
    }
}
