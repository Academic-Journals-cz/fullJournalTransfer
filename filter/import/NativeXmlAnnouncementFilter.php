<?php

/**
 * @file plugins/importexport/fullJournalTransfer/filter/import/NativeXmlAnnouncementFilter.php
 *
 * Copyright (c) 2014-2024 Lepidus Tecnologia
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NativeXmlAnnouncementFilter
 *
 * @brief Imports announcements.
 */

namespace APP\plugins\importexport\fullJournalTransfer\filter\import;

use APP\core\Application;
use APP\plugins\importexport\fullJournalTransfer\classes\FullJournalFilterTrait;
use DOMElement;
use Illuminate\Support\Facades\DB;
use PKP\announcement\Announcement;
use PKP\db\DAORegistry;
use PKP\plugins\importexport\native\filter\NativeImportFilter;

class NativeXmlAnnouncementFilter extends NativeImportFilter
{
    use FullJournalFilterTrait;

    public function __construct($filterGroup)
    {
        $this->setDisplayName('Native XML announcement import');
        parent::__construct($filterGroup);
    }

    public function getPluralElementName()
    {
        return 'announcements';
    }

    public function getSingularElementName()
    {
        return 'announcement';
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
        $context = $deployment->getContext();

        $attributes = [
            'assocType' => Application::get()->getContextAssocType(),
            'assocId' => (int) $context->getId(),
        ];
        $title = $descriptionShort = $description = [];
        $datePosted = null;

        foreach ($this->childElements($node) as $childNode) {
            switch ($childNode->tagName) {
                case 'title':
                    [$locale, $value] = $this->parseLocalizedContent($childNode);
                    $title[$locale ?: $context->getPrimaryLocale()] = $value;
                    break;
                case 'description_short':
                    [$locale, $value] = $this->parseLocalizedContent($childNode);
                    $descriptionShort[$locale ?: $context->getPrimaryLocale()] = $value;
                    break;
                case 'description':
                    [$locale, $value] = $this->parseLocalizedContent($childNode);
                    $description[$locale ?: $context->getPrimaryLocale()] = $value;
                    break;
                case 'date_expire':
                    $attributes['dateExpire'] = $this->parseDate($childNode->textContent);
                    break;
                case 'date_posted':
                    $datePosted = $this->parseDateTime($childNode->textContent);
                    break;
                case 'announcement_type_ref':
                    $announcementTypeDao = DAORegistry::getDAO('AnnouncementTypeDAO'); /** @var \PKP\announcement\AnnouncementTypeDAO $announcementTypeDao */
                    foreach ($announcementTypeDao->getByContextId((int) $context->getId()) as $announcementType) {
                        if (in_array($childNode->textContent, (array) $announcementType->getData('name'), true)) {
                            $attributes['typeId'] = (int) $announcementType->getId();
                            break;
                        }
                    }
                    break;
                case 'image':
                    $image = json_decode($childNode->textContent, true);
                    if (is_array($image) && !empty($image['uploadName'])) {
                        unset($image['temporaryFileId']);
                        $attributes['image'] = $image;
                    }
                    break;
            }
        }

        $attributes['title'] = $title;
        if (!empty($descriptionShort)) {
            $attributes['descriptionShort'] = $descriptionShort;
        }
        if (!empty($description)) {
            $attributes['description'] = $description;
        }
        $announcement = Announcement::create($attributes);

        // date_posted is managed by Eloquent (created at); restore the original value
        if ($datePosted) {
            DB::table('announcements')->where('announcement_id', $announcement->id)->update(['date_posted' => $datePosted]);
        }
        $deployment->incrementCounter('announcements');

        return $announcement->fresh();
    }
}
