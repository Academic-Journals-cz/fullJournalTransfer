<?php

/**
 * @file plugins/importexport/fullJournalTransfer/classes/XmlText.php
 *
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class XmlText
 *
 * @brief Helpers for text values written into the XML document.
 */

namespace APP\plugins\importexport\fullJournalTransfer\classes;

class XmlText
{
    /**
     * Remove characters that are not allowed in XML 1.0 documents (control
     * characters, unpaired surrogates) and make sure the string is valid UTF-8.
     * Database content occasionally contains such characters (pasted text);
     * they would make the exported document unparseable.
     */
    public static function sanitize(string $value): string
    {
        if ($value === '') {
            return $value;
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        $clean = preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
        return $clean === null ? '' : $clean;
    }

    /**
     * Encode an array value (e.g. a list of locales) as a string attribute.
     */
    public static function joinList(?array $values): string
    {
        return implode(':', array_map('strval', $values ?? []));
    }

    public static function splitList(?string $value): array
    {
        $value = trim((string) $value);
        return $value === '' ? [] : explode(':', $value);
    }
}
