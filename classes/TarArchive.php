<?php

/**
 * @file plugins/importexport/fullJournalTransfer/classes/TarArchive.php
 *
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class TarArchive
 *
 * @brief Minimal streaming tar.gz writer/reader (ustar + GNU long names) so
 *  that the plugin does not depend on an external tar binary (which is
 *  missing or incompatible on Windows) and never holds a whole file in memory.
 */

namespace APP\plugins\importexport\fullJournalTransfer\classes;

use Exception;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class TarArchive
{
    public const BLOCK_SIZE = 512;
    public const CHUNK_SIZE = 1048576; // 1 MB

    /** @var resource */
    private $handle;

    private function __construct($handle)
    {
        $this->handle = $handle;
    }

    //
    // Writing
    //
    /**
     * Create a gzip compressed tar archive.
     *
     * @param array<string,string> $entries archive path => local path (file or directory; a
     *  directory is added recursively, the archive path being its name in the archive)
     */
    public static function create(string $archivePath, array $entries): void
    {
        $handle = gzopen($archivePath, 'wb6');
        if (!$handle) {
            throw new Exception('Could not create the archive ' . $archivePath);
        }
        $archive = new self($handle);
        try {
            foreach ($entries as $archiveName => $localPath) {
                $archiveName = trim(str_replace('\\', '/', $archiveName), '/');
                if (is_dir($localPath)) {
                    $archive->addDirectory($archiveName, $localPath);
                } elseif (is_file($localPath)) {
                    $archive->addFile($archiveName, $localPath);
                } else {
                    throw new Exception('Cannot add "' . $localPath . '" to the archive: no such file or directory.');
                }
            }
            // end of archive: two zero blocks
            gzwrite($handle, str_repeat("\0", self::BLOCK_SIZE * 2));
        } finally {
            gzclose($handle);
        }
    }

    protected function addDirectory(string $archiveName, string $localPath): void
    {
        $localPath = rtrim($localPath, '/\\');
        $this->writeHeader($archiveName . '/', 0, filemtime($localPath) ?: time(), '5');

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($localPath, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($localPath) + 1));
            $name = $archiveName . '/' . $relative;
            if ($item->isDir()) {
                $this->writeHeader($name . '/', 0, $item->getMTime() ?: time(), '5');
            } elseif ($item->isFile()) {
                $this->addFile($name, $item->getPathname());
            }
        }
    }

    protected function addFile(string $archiveName, string $localPath): void
    {
        $size = filesize($localPath);
        if ($size === false) {
            throw new Exception('Could not read ' . $localPath);
        }
        $this->writeHeader($archiveName, $size, filemtime($localPath) ?: time(), '0');

        $in = fopen($localPath, 'rb');
        if (!$in) {
            throw new Exception('Could not read ' . $localPath);
        }
        $written = 0;
        while (!feof($in)) {
            $chunk = fread($in, self::CHUNK_SIZE);
            if ($chunk === false) {
                fclose($in);
                throw new Exception('Could not read ' . $localPath);
            }
            if ($chunk !== '') {
                gzwrite($this->handle, $chunk);
                $written += strlen($chunk);
            }
        }
        fclose($in);
        if ($written !== $size) {
            throw new Exception('The file ' . $localPath . ' changed while it was archived.');
        }
        $this->writePadding($size);
    }

    protected function writeHeader(string $name, int $size, int $mtime, string $type): void
    {
        if (strlen($name) > 100) {
            // GNU long name: a pseudo entry carrying the full name, then the real header with a truncated name
            $this->writeHeader('././@LongLink', strlen($name) + 1, $mtime, 'L');
            gzwrite($this->handle, $name . "\0");
            $this->writePadding(strlen($name) + 1);
            $name = substr($name, 0, 100);
        }

        $header = str_pad($name, 100, "\0")
            . sprintf("%07o\0", $type === '5' ? 0755 : 0644)
            . sprintf("%07o\0", 0)
            . sprintf("%07o\0", 0)
            . sprintf("%011o\0", $size)
            . sprintf("%011o\0", $mtime)
            . '        ' // checksum placeholder (8 spaces)
            . $type
            . str_repeat("\0", 100) // linkname
            . "ustar\0" . '00'
            . str_pad('', 32, "\0") // uname
            . str_pad('', 32, "\0") // gname
            . sprintf("%07o\0", 0)
            . sprintf("%07o\0", 0)
            . str_repeat("\0", 155) // prefix
            . str_repeat("\0", 12);

        $checksum = 0;
        for ($i = 0; $i < self::BLOCK_SIZE; $i++) {
            $checksum += ord($header[$i]);
        }
        $header = substr_replace($header, sprintf("%06o\0 ", $checksum), 148, 8);
        gzwrite($this->handle, $header);
    }

    protected function writePadding(int $size): void
    {
        $remainder = $size % self::BLOCK_SIZE;
        if ($remainder > 0) {
            gzwrite($this->handle, str_repeat("\0", self::BLOCK_SIZE - $remainder));
        }
    }

    //
    // Reading
    //
    /**
     * Extract a (gzip compressed) tar archive into a directory.
     *
     * @return int number of extracted files
     */
    public static function extract(string $archivePath, string $targetDir): int
    {
        $handle = gzopen($archivePath, 'rb');
        if (!$handle) {
            throw new Exception('Could not open the archive ' . $archivePath);
        }
        $targetDir = rtrim($targetDir, '/\\');
        $count = 0;
        $longName = null;
        try {
            while (!gzeof($handle)) {
                $block = self::readBlock($handle);
                if ($block === null || trim($block, "\0") === '') {
                    continue; // end-of-archive / padding blocks
                }

                $name = rtrim(substr($block, 0, 100), "\0");
                $size = (int) octdec(trim(substr($block, 124, 12), "\0 "));
                $type = substr($block, 156, 1);
                $magic = substr($block, 257, 5);
                $prefix = $magic === 'ustar' ? rtrim(substr($block, 345, 155), "\0") : '';

                if ($type === 'L') {
                    // GNU long name for the next entry
                    $longName = rtrim(self::readData($handle, $size), "\0");
                    continue;
                }
                if ($longName !== null) {
                    $name = $longName;
                    $longName = null;
                } elseif ($prefix !== '') {
                    $name = $prefix . '/' . $name;
                }

                $name = str_replace('\\', '/', $name);
                $segments = array_filter(explode('/', $name), fn ($segment) => $segment !== '' && $segment !== '.');
                if (in_array('..', $segments, true)) {
                    throw new Exception('The archive contains an unsafe path: ' . $name);
                }
                $path = $targetDir . '/' . implode('/', $segments);

                switch ($type) {
                    case '5':
                        if (!is_dir($path) && !mkdir($path, 0770, true)) {
                            throw new Exception('Could not create the directory ' . $path);
                        }
                        break;
                    case '0':
                    case "\0":
                    case '7':
                        $dir = dirname($path);
                        if (!is_dir($dir) && !mkdir($dir, 0770, true)) {
                            throw new Exception('Could not create the directory ' . $dir);
                        }
                        self::readFile($handle, $size, $path);
                        $count++;
                        break;
                    default:
                        // links, extended headers (pax), ...: skip the data
                        self::skipData($handle, $size);
                }
            }
        } finally {
            gzclose($handle);
        }
        return $count;
    }

    /** @param resource $handle */
    protected static function readBlock($handle): ?string
    {
        $block = '';
        while (strlen($block) < self::BLOCK_SIZE && !gzeof($handle)) {
            $part = gzread($handle, self::BLOCK_SIZE - strlen($block));
            if ($part === false || $part === '') {
                break;
            }
            $block .= $part;
        }
        if ($block === '') {
            return null;
        }
        if (strlen($block) < self::BLOCK_SIZE) {
            throw new Exception('Unexpected end of the archive.');
        }
        return $block;
    }

    /** @param resource $handle */
    protected static function readData($handle, int $size): string
    {
        $data = '';
        $padded = (int) (ceil($size / self::BLOCK_SIZE) * self::BLOCK_SIZE);
        while (strlen($data) < $padded) {
            $part = gzread($handle, min(self::CHUNK_SIZE, $padded - strlen($data)));
            if ($part === false || $part === '') {
                throw new Exception('Unexpected end of the archive.');
            }
            $data .= $part;
        }
        return substr($data, 0, $size);
    }

    /** @param resource $handle */
    protected static function readFile($handle, int $size, string $path): void
    {
        $out = fopen($path, 'wb');
        if (!$out) {
            throw new Exception('Could not write ' . $path);
        }
        $remaining = $size;
        while ($remaining > 0) {
            $part = gzread($handle, min(self::CHUNK_SIZE, $remaining));
            if ($part === false || $part === '') {
                fclose($out);
                throw new Exception('Unexpected end of the archive while reading ' . $path);
            }
            fwrite($out, $part);
            $remaining -= strlen($part);
        }
        fclose($out);
        self::skipBytes($handle, (self::BLOCK_SIZE - ($size % self::BLOCK_SIZE)) % self::BLOCK_SIZE);
    }

    /**
     * Skip the data of an entry (including the padding to the block size).
     *
     * @param resource $handle
     */
    protected static function skipData($handle, int $size): void
    {
        self::skipBytes($handle, (int) (ceil($size / self::BLOCK_SIZE) * self::BLOCK_SIZE));
    }

    /** @param resource $handle */
    protected static function skipBytes($handle, int $bytes): void
    {
        $remaining = $bytes;
        while ($remaining > 0) {
            $part = gzread($handle, min(self::CHUNK_SIZE, $remaining));
            if ($part === false || $part === '') {
                throw new Exception('Unexpected end of the archive.');
            }
            $remaining -= strlen($part);
        }
    }
}
