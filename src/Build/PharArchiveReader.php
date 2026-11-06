<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use Phar;
use PharFileInfo;
use RecursiveIteratorIterator;
use Throwable;

final class PharArchiveReader implements ArchiveReader
{
    private Phar $phar;
    private readonly string $snapshotPath;

    public function __construct(string $path)
    {
        $snapshot = PharSnapshot::create($path);
        try {
            $this->phar = new Phar($snapshot);
            $this->snapshotPath = $snapshot;
        } catch (Throwable $error) {
            PharSnapshot::remove($snapshot);
            throw new BuildException("Cannot open PHAR {$path}: {$error->getMessage()}", 0, $error);
        }
    }

    public function __destruct()
    {
        unset($this->phar);
        PharSnapshot::remove($this->snapshotPath);
    }

    public function entries(): iterable
    {
        $iterator = new RecursiveIteratorIterator($this->phar);
        foreach ($iterator as $entry) {
            if (!$entry instanceof PharFileInfo) {
                continue;
            }
            if (!$entry->isFile()) {
                continue;
            }
            $contents = $entry->getContent();
            $relative = $entry->getPathName();
            $prefix = 'phar://' . $this->phar->getPath() . '/';
            if (str_starts_with($relative, $prefix)) {
                $relative = substr($relative, strlen($prefix));
            }
            yield new ArchiveEntry($relative, $contents, $entry->isLink());
        }
    }
}
