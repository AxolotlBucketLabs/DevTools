<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Support\ArtifactFile;
use AxolotlBucketLabs\DevTools\Support\RandomToken;
use Closure;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use function array_pop;
use function dirname;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_dir;
use function is_file;
use function mkdir;
use function preg_match_all;
use function register_shutdown_function;
use function rmdir;
use function rtrim;
use function spl_autoload_register;
use function spl_autoload_unregister;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function trim;
use function unlink;
use const DIRECTORY_SEPARATOR;

final class PhpParserNamespaceLoader
{
	private const TEMPORARY_PATH_ATTEMPTS = 5;
	private const TEMPORARY_PATH_PREFIX = 'devtools-parser-';
	private const PRIVATE_DIRECTORY_PERMISSIONS = 0700;

	private const PREFIX = 'AxolotlBucketLabs\\DevTools\\Vendor\\PhpParser\\';

	/** @var (Closure(string): void)|null */
	private static ?Closure $autoloader = null;

	private static ?string $sourceRoot = null;

	private static ?string $temporaryRoot = null;

	private static bool $shutdownRegistered = false;

	public static function register(string $sourceRoot) : void
	{
		$sourceRoot = rtrim($sourceRoot, '/\\');
		if (self::$autoloader !== null && self::$sourceRoot === $sourceRoot) {
			return;
		}
		self::unregister();
		$loader = static function (string $class) use ($sourceRoot) : void {
			if (!str_starts_with($class, self::PREFIX)) {
				return;
			}
			$relative = substr($class, strlen(self::PREFIX));
			if ($relative === '') {
				return;
			}
			$relative = str_replace('\\', '/', $relative);
			if (!is_file($sourceRoot . '/' . $relative . ArtifactFile::PHP_SOURCE_EXTENSION)) {
				return;
			}
			$temporaryRoot = self::temporaryRoot();
			self::prepareSource($sourceRoot, $temporaryRoot, $relative);
			require($temporaryRoot . DIRECTORY_SEPARATOR . $relative . ArtifactFile::PHP_SOURCE_EXTENSION);
		};
		self::$sourceRoot = $sourceRoot;
		self::$autoloader = $loader;
		spl_autoload_register($loader, true, true);
		if (!self::$shutdownRegistered) {
			register_shutdown_function([self::class, 'unregister']);
			self::$shutdownRegistered = true;
		}
	}

	public static function unregister() : void
	{
		if (self::$autoloader !== null) {
			spl_autoload_unregister(self::$autoloader);
			self::$autoloader = null;
		}
		self::$sourceRoot = null;
		if (self::$temporaryRoot !== null) {
			self::removeTree(self::$temporaryRoot);
			self::$temporaryRoot = null;
		}
	}

	private static function temporaryRoot() : string
	{
		if (self::$temporaryRoot !== null) {
			return self::$temporaryRoot;
		}

		for ($attempt = 0; $attempt < self::TEMPORARY_PATH_ATTEMPTS; ++$attempt) {
			$candidate = sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::TEMPORARY_PATH_PREFIX . RandomToken::snapshotSuffix();
			if (mkdir($candidate, self::PRIVATE_DIRECTORY_PERMISSIONS)) {
				return self::$temporaryRoot = $candidate;
			}
		}

		throw new RuntimeException('Cannot create an isolated PHP-Parser directory.');
	}

	private static function prepareSource(string $sourceRoot, string $temporaryRoot, string $relative) : void
	{
		$sourcePath = $sourceRoot . '/' . $relative . ArtifactFile::PHP_SOURCE_EXTENSION;
		$temporaryPath = $temporaryRoot . '/' . $relative . ArtifactFile::PHP_SOURCE_EXTENSION;
		if (is_file($temporaryPath)) {
			return;
		}
		if (!is_file($sourcePath)) {
			return;
		}
		$source = file_get_contents($sourcePath);
		if ($source === false) {
			throw new RuntimeException("Cannot read isolated PHP-Parser source {$sourcePath}.");
		}
		$requires = [];
		if (preg_match_all('~require(?:_once)?\\s+__DIR__\\s*\\.\\s*[\\x27\\x22]([^\\x27\\x22]+)[\\x27\\x22]~', $source, $matches) !== false) {
			$base = str_replace('\\', '/', dirname($relative));
			foreach ($matches[1] as $required) {
				$required = str_replace('\\', '/', (string) $required);
				if (str_ends_with($required, ArtifactFile::PHP_SOURCE_EXTENSION)) {
					$required = substr($required, 0, -strlen(ArtifactFile::PHP_SOURCE_EXTENSION));
				}
				$requires[] = self::resolveRelative($base, $required);
			}
		}
		foreach ($requires as $required) {
			self::prepareSource($sourceRoot, $temporaryRoot, $required);
		}
		$source = str_replace(
			['namespace PhpParser;', 'use PhpParser;', 'PhpParser\\'],
			['namespace ' . rtrim(self::PREFIX, '\\') . ';', 'use ' . rtrim(self::PREFIX, '\\') . ';', self::PREFIX],
			$source,
		);
		$parent = dirname($temporaryPath);
		if (!is_dir($parent) && !mkdir($parent, self::PRIVATE_DIRECTORY_PERMISSIONS, true)) {
			throw new RuntimeException("Cannot create isolated PHP-Parser directory {$parent}.");
		}
		if (file_put_contents($temporaryPath, $source) === false) {
			throw new RuntimeException("Cannot prepare isolated PHP-Parser source {$sourcePath}.");
		}
	}

	private static function resolveRelative(string $base, string $path) : string
	{
		$parts = [];
		foreach (explode('/', trim($base . '/' . str_replace('\\', '/', $path), '/')) as $part) {
			if ($part === '' || $part === '.') {
				continue;
			}
			if ($part === '..') {
				array_pop($parts);
				continue;
			}
			$parts[] = $part;
		}

		return implode('/', $parts);
	}

	private static function removeTree(string $directory) : void
	{
		if (!is_dir($directory)) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($iterator as $entry) {
			if (!$entry instanceof SplFileInfo) {
				continue;
			}
			if ($entry->isDir()) {
				rmdir($entry->getPathname());
			} else {
				unlink($entry->getPathname());
			}
		}
		rmdir($directory);
	}
}
