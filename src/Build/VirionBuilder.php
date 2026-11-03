<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Build;

use function bin2hex;
use function file_exists;

use FilesystemIterator;

use function hash;
use function hash_file;
use function ini_get;
use function is_dir;
use function is_file;

use NhanAZ\DevTools\Support\Filesystem;
use NhanAZ\DevTools\Support\Path;
use NhanAZ\DevTools\Virion\VirionClassScanner;
use NhanAZ\DevTools\Virion\VirionManifestReader;
use NhanAZ\DevTools\Virion\VirionProjectFactory;
use NhanAZ\DevTools\Virion\VirionSourceFingerprint;
use Phar;

use function random_bytes;
use function realpath;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function rename;

use SplFileInfo;

use function str_replace;
use function str_starts_with;

use Throwable;

use function unlink;

final class VirionBuilder
{
    /** @var list<string> */
    private const NOTICE_FILES = ['LICENSE', 'LICENSE.md', 'LICENSE.txt', 'COPYING', 'COPYING.md', 'NOTICE', 'NOTICE.md', 'THIRD_PARTY_NOTICES.md'];

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly VirionManifestReader $manifestReader,
        private readonly VirionProjectFactory $projectFactory,
        private readonly VirionClassScanner $classScanner,
    ) {}

    public function build(
        string $projectRoot,
        string $outputDirectory,
        bool $overwrite = false,
        ?string $outputName = null,
    ): VirionBuildResult {
        if ((string) ini_get('phar.readonly') !== '0') {
            throw new BuildException('Cannot build a PHAR because phar.readonly is enabled. Start PHP with -d phar.readonly=0.');
        }
        $project = $this->projectFactory->open($projectRoot);
        if ($project->phar) {
            throw new BuildException("Cannot build a virion from a PHAR. Use the source folder containing virion.yml: {$projectRoot}");
        }
        $classes = $this->classScanner->scan($project);
        if ($classes === []) {
            throw new BuildException("Cannot build virion {$project->manifest->name}: no PHP classes were found.");
        }
        foreach ($classes as $class) {
            if (!str_starts_with($class, $project->manifest->antigen . '\\')) {
                throw new BuildException(
                    "Cannot build virion {$project->manifest->name}: class {$class} is outside antigen {$project->manifest->antigen}.",
                );
            }
        }

        if (Filesystem::isLink($outputDirectory)) {
            throw new BuildException("Refusing symbolic-link build output directory: {$outputDirectory}");
        }
        $this->filesystem->ensureDirectory($outputDirectory);
        $realProject = realpath($projectRoot);
        $realOutput = realpath($outputDirectory);
        if ($realProject === false || $realOutput === false) {
            throw new BuildException('Cannot resolve the virion project or build output directory.');
        }
        if ($realOutput === $realProject
            || Path::isInside($realProject . DIRECTORY_SEPARATOR . 'src', $realOutput)
            || Path::isInside($realProject . DIRECTORY_SEPARATOR . 'resources', $realOutput)) {
            throw new BuildException("Refusing build output inside virion source or resources: {$realOutput}.");
        }

        $safeName = $outputName ?? $project->manifest->name;
        if (preg_match('/^[A-Za-z0-9_.-]+$/D', $safeName) !== 1) {
            throw new BuildException("Cannot derive a safe virion artifact name from {$safeName}.");
        }
        $outputPath = Path::join($outputDirectory, $safeName . '.phar');
        $this->assertOutputDestination($outputPath, $overwrite);

        $nonce = bin2hex(random_bytes(8));
        $stage = Path::join($outputDirectory, '.devtools-virion-stage-' . $nonce);
        $temporaryPhar = Path::join($outputDirectory, '.' . $safeName . '.' . $nonce . '.tmp.phar');
        $this->filesystem->ensureDirectory($stage);
        $failure = null;
        $result = null;
        try {
            $provenance = $this->stage($projectRoot, $stage, $project->manifest->antigen, $project->manifest->name, $project->sourceRoot);
            $this->filesystem->assertPortableTree($stage);
            $fileCount = $this->countFiles($stage);
            $this->writePhar($stage, $temporaryPhar);
            $this->validatePhar($temporaryPhar, $project->manifest->name, $provenance);
            $this->installOutput($temporaryPhar, $outputPath, $overwrite);
            $result = new VirionBuildResult($project->manifest, $outputPath, $fileCount);
        } catch (Throwable $error) {
            $failure = $error instanceof BuildException
                ? $error
                : new BuildException("Virion build failed for {$projectRoot}: {$error->getMessage()}", 0, $error);
        }

        $cleanupFailures = [];
        try {
            $this->filesystem->removeTree($stage);
        } catch (Throwable $error) {
            $cleanupFailures[] = $error->getMessage();
        }
        if (!PharSnapshot::remove($temporaryPhar)) {
            $cleanupFailures[] = "Cannot remove temporary PHAR {$temporaryPhar}.";
        }
        if ($failure !== null) {
            if ($cleanupFailures !== []) {
                throw new BuildException($failure->getMessage() . "\nCleanup also failed: " . implode("\n", $cleanupFailures), 0, $failure);
            }
            throw $failure;
        }
        if ($cleanupFailures !== []) {
            throw new BuildException('Virion output was created, but cleanup failed: ' . implode("\n", $cleanupFailures));
        }

        return $result;
    }

    private function stage(string $projectRoot, string $stage, string $antigen, string $name, string $sourceRoot): ?string
    {
        $this->filesystem->copyFile($projectRoot . DIRECTORY_SEPARATOR . 'virion.yml', $stage . DIRECTORY_SEPARATOR . 'virion.yml');
        $source = $projectRoot . DIRECTORY_SEPARATOR . 'src';
        $antigenSource = $source . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $antigen);
        $sourceDestination = $stage . DIRECTORY_SEPARATOR . 'src';
        if (is_dir($antigenSource)) {
            $this->filesystem->copyTree(
                $antigenSource,
                $sourceDestination . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $antigen),
                static fn(string $relative): bool => !Path::isHiddenOrTemporary($relative),
            );
        } else {
            $this->filesystem->copyTree(
                $source,
                $sourceDestination,
                static fn(string $relative): bool => !Path::isHiddenOrTemporary($relative),
            );
        }
        $resources = $projectRoot . DIRECTORY_SEPARATOR . 'resources';
        if (!is_file($projectRoot . DIRECTORY_SEPARATOR . 'plugin.yml')
            && is_dir($resources)
            && (new FilesystemIterator($resources, FilesystemIterator::SKIP_DOTS))->valid()) {
            throw new BuildException(
                "Virion {$name} has root resources/. Only resources colocated under its source directory retain relative paths when bundled.",
            );
        }
        foreach (self::NOTICE_FILES as $notice) {
            $source = $projectRoot . DIRECTORY_SEPARATOR . $notice;
            if (is_file($source)) {
                $this->filesystem->copyFile($source, $stage . DIRECTORY_SEPARATOR . $notice);
            }
        }
        $provenance = $projectRoot . DIRECTORY_SEPARATOR . 'devtools-provenance.json';
        if (!is_file($provenance)) {
            return null;
        }
        $this->assertProvenance($provenance, $projectRoot . DIRECTORY_SEPARATOR . 'virion.yml', $sourceRoot, $name);
        $this->filesystem->copyFile($provenance, $stage . DIRECTORY_SEPARATOR . 'devtools-provenance.json');

        return $provenance;
    }

    private function writePhar(string $stage, string $path): void
    {
        $phar = new Phar($path);
        $phar->startBuffering();
        $phar->buildFromDirectory($stage);
        $phar->setStub("<?php __HALT_COMPILER();\n");
        $phar->setSignatureAlgorithm(Phar::SHA256);
        $phar->stopBuffering();
        unset($phar);
    }

    private function validatePhar(string $path, string $name, ?string $provenance): void
    {
        $phar = new Phar($path);
        if (!isset($phar['virion.yml']) || !isset($phar['src'])) {
            throw new BuildException("Generated virion {$name} PHAR is missing virion.yml or src/.");
        }
        $manifest = $this->manifestReader->read('phar://' . str_replace('\\', '/', $path) . '/virion.yml');
        if ($manifest->name !== $name) {
            throw new BuildException("Generated virion manifest name {$manifest->name} does not match {$name}.");
        }
        if ($provenance !== null) {
            if (!isset($phar['devtools-provenance.json'])) {
                throw new BuildException("Generated virion {$name} PHAR is missing devtools-provenance.json.");
            }
            $expected = hash_file('sha256', $provenance);
            $actual = hash('sha256', $phar['devtools-provenance.json']->getContent());
            if ($expected === false || !hash_equals($expected, $actual)) {
                throw new BuildException("Generated virion {$name} PHAR has altered provenance metadata.");
            }
        }
        $signature = $phar->getSignature();
        if ($signature['hash_type'] !== 'SHA-256') {
            throw new BuildException("Generated virion {$name} PHAR has no SHA-256 signature.");
        }
        unset($phar);
    }

    private function assertProvenance(string $provenance, string $manifest, string $sourceRoot, string $name): void
    {
        $metadata = (new ComposerVirionPlan())->readJson($provenance);
        $manifestHash = hash_file('sha256', $manifest);
        if ($manifestHash === false || ($metadata['manifestSha256'] ?? null) !== $manifestHash) {
            throw new BuildException("Prepared manifest for {$name} changed or lacks verification metadata. Run prepare into a new directory from the reviewed installed package.");
        }
        if (($metadata['sourceSha256'] ?? null) !== (new VirionSourceFingerprint())->hash($sourceRoot)) {
            throw new BuildException("Prepared source for {$name} changed after preparation. Run prepare into a new directory from the reviewed installed package.");
        }
    }

    private function installOutput(string $temporary, string $output, bool $overwrite): void
    {
        $this->assertOutputDestination($output, $overwrite);
        $backup = null;
        if (file_exists($output)) {
            $backup = $output . '.backup-' . bin2hex(random_bytes(4));
            if (!rename($output, $backup)) {
                throw new BuildException("Cannot move existing output aside before replacement: {$output}");
            }
        }
        if (!rename($temporary, $output)) {
            if ($backup !== null && !rename($backup, $output)) {
                throw new BuildException("Cannot install completed virion PHAR or restore the previous output. The previous output remains at {$backup}.");
            }
            throw new BuildException("Cannot move completed virion PHAR to {$output}.");
        }
        if ($backup !== null && file_exists($backup) && !unlink($backup)) {
            throw new BuildException('Virion build succeeded, but the old backup could not be removed: ' . $backup);
        }
    }

    private function assertOutputDestination(string $output, bool $overwrite): void
    {
        if (Filesystem::isLink($output) || (file_exists($output) && !is_file($output))) {
            throw new BuildException("Build output is not a regular file: {$output}.");
        }
        if (file_exists($output) && !$overwrite) {
            throw new BuildException("Refusing to replace existing build {$output}. Run the build again with overwrite explicitly enabled.");
        }
    }

    private function countFiles(string $directory): int
    {
        $count = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile()) {
                ++$count;
            }
        }

        return $count;
    }
}
