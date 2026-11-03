<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Build;

use function file_get_contents;
use function file_put_contents;

use FilesystemIterator;
use NhanAZ\DevTools\Vendor\PhpParser\NodeTraverser;
use NhanAZ\DevTools\Vendor\PhpParser\NodeVisitor\NameResolver;
use NhanAZ\DevTools\Vendor\PhpParser\NodeVisitor\ParentConnectingVisitor;
use NhanAZ\DevTools\Vendor\PhpParser\Parser;
use NhanAZ\DevTools\Vendor\PhpParser\ParserFactory;
use NhanAZ\DevTools\Vendor\PhpParser\PrettyPrinter\Standard;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function str_ends_with;
use function strtolower;

use Throwable;

final class NamespaceShader
{
    private readonly Parser $parser;

    private readonly Standard $printer;

    public function __construct()
    {
        if (!class_exists(ParserFactory::class)) {
            throw new BuildException('Namespace shading requires nikic/php-parser. Run composer install for DevTools.');
        }
        $this->parser = (new ParserFactory())->createForHostVersion();
        $this->printer = new Standard();
    }

    /** @param array<string, string> $replacements */
    public function shadeDirectory(string $directory, array $replacements, bool $rewriteNamespaceDeclarations = true): void
    {
        if ($replacements === []) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }
            if (!$entry->isFile() || !str_ends_with(strtolower($entry->getFilename()), '.php')) {
                continue;
            }
            $this->shadeFile($entry->getPathname(), $replacements, $rewriteNamespaceDeclarations);
        }
    }

    /**
     * @param list<string> $namespaces
     * @param array<string, string> $shadedNamespaces Original namespace => shaded namespace.
     */
    public function assertNoNamespaceReferences(
        string $directory,
        array $namespaces,
        array $shadedNamespaces = [],
    ): void {
        if ($namespaces === []) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }
            if (!$entry->isFile() || !str_ends_with(strtolower($entry->getFilename()), '.php')) {
                continue;
            }
            $source = file_get_contents($entry->getPathname());
            if ($source === false) {
                throw new BuildException("Cannot read PHP source while checking standalone references: {$entry->getPathname()}");
            }
            try {
                $statements = $this->parser->parse($source);
                if ($statements === null) {
                    throw new BuildException("The parser returned no statements for {$entry->getPathname()}.");
                }
                $traverser = new NodeTraverser();
                $traverser->addVisitor(new ParentConnectingVisitor());
                $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
                $traverser->addVisitor(new NamespaceReferenceGuardVisitor($namespaces, $shadedNamespaces));
                $traverser->traverse($statements);
            } catch (BuildException $error) {
                throw new BuildException("Standalone validation failed for {$entry->getPathname()}: {$error->getMessage()}", 0, $error);
            } catch (Throwable $error) {
                throw new BuildException("Cannot inspect {$entry->getPathname()}: {$error->getMessage()}", 0, $error);
            }
        }
    }

    /** @param array<string, string> $replacements */
    private function shadeFile(string $path, array $replacements, bool $rewriteNamespaceDeclarations): void
    {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new BuildException("Cannot read PHP source while shading: {$path}");
        }
        try {
            $statements = $this->parser->parse($source);
            if ($statements === null) {
                throw new BuildException("The parser returned no statements for {$path}.");
            }
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new ParentConnectingVisitor());
            $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
            $traverser->addVisitor(new NamespaceRewriteVisitor($replacements, $rewriteNamespaceDeclarations));
            $statements = $traverser->traverse($statements);
            $result = $this->printer->prettyPrintFile($statements) . "\n";
        } catch (BuildException $error) {
            throw new BuildException("Cannot shade {$path}: {$error->getMessage()}", 0, $error);
        } catch (Throwable $error) {
            throw new BuildException("Cannot parse or transform {$path}: {$error->getMessage()}", 0, $error);
        }
        if (file_put_contents($path, $result) === false) {
            throw new BuildException("Cannot write shaded PHP source: {$path}");
        }
    }
}
