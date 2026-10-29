<?php

namespace App\Backup;

use RuntimeException;

/**
 * Minimal streaming writer for gzip-compressed POSIX ustar archives (regular files only), so that
 * backups need neither the phar extension nor an external tar binary. Entries get mode 0600.
 */
final class TarGzWriter
{
    /** @var resource */
    private $gz;

    public function __construct(string $path)
    {
        $gz = gzopen($path, 'wb6');
        if ($gz === false) {
            throw new RuntimeException('Cannot open archive for writing.');
        }
        $this->gz = $gz;
    }

    public function addString(string $name, string $content, ?int $mtime = null): void
    {
        $this->header($name, strlen($content), $mtime ?? time());
        $this->write($content);
        $this->pad(strlen($content));
    }

    public function addFile(string $name, string $path): void
    {
        $in = @fopen($path, 'rb');
        if ($in === false) {
            throw new RuntimeException("Cannot read file for archive entry [$name].");
        }
        try {
            $size = fstat($in)['size'];
            $this->header($name, $size, (int) filemtime($path));
            $written = 0;
            while ($written < $size && ! feof($in)) {
                $chunk = fread($in, min(1048576, $size - $written));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $this->write($chunk);
                $written += strlen($chunk);
            }
            if ($written !== $size) {
                throw new RuntimeException("File changed while archiving [$name].");
            }
            $this->pad($size);
        } finally {
            fclose($in);
        }
    }

    /** Writes the end-of-archive marker (two zero blocks) and closes the file. */
    public function close(): void
    {
        $this->write(str_repeat("\0", 1024));
        if (! gzclose($this->gz)) {
            throw new RuntimeException('Cannot finish archive.');
        }
    }

    private function header(string $name, int $size, int $mtime): void
    {
        if ($name === '' || strlen($name) > 100 || str_contains($name, '..') || str_starts_with($name, '/')) {
            throw new RuntimeException("Invalid archive entry name [$name].");
        }
        $header = str_pad($name, 100, "\0")
            .sprintf('%07o', 0600)."\0"
            .sprintf('%07o', 0)."\0"
            .sprintf('%07o', 0)."\0"
            .sprintf('%011o', $size)."\0"
            .sprintf('%011o', $mtime)."\0"
            .str_repeat(' ', 8)            // checksum placeholder
            .'0'                           // regular file
            .str_repeat("\0", 100)         // link name
            ."ustar\0".'00'
            .str_pad('root', 32, "\0")
            .str_pad('root', 32, "\0")
            .str_repeat("\0", 8 + 8 + 155);
        $header = str_pad($header, 512, "\0");
        $checksum = array_sum(array_map('ord', str_split($header)));
        $header = substr_replace($header, sprintf('%06o', $checksum)."\0 ", 148, 8);
        $this->write($header);
    }

    private function pad(int $size): void
    {
        if ($size % 512 !== 0) {
            $this->write(str_repeat("\0", 512 - $size % 512));
        }
    }

    private function write(string $data): void
    {
        if (gzwrite($this->gz, $data) !== strlen($data)) {
            throw new RuntimeException('Cannot write archive.');
        }
    }
}
