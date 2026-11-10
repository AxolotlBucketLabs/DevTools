<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Support;

/**
 * Canonical project filenames and artifact layout values.
 */
final class ArtifactFile
{
	public const SOURCE_DIRECTORY = 'src';
	public const GENERATED_DIRECTORY = 'generated';
	public const RESOURCE_DIRECTORY = 'resources';
	public const PHAR_EXTENSION = '.phar';
	public const ZIP_EXTENSION = '.zip';
	public const PHP_SOURCE_EXTENSION = '.php';
	public const PLUGIN_MANIFEST = 'plugin.yml';
	public const VIRION_MANIFEST = 'virion.yml';
	public const COMPOSER_MANIFEST = 'composer.json';
	public const COMPOSER_LOCK = 'composer.lock';
	public const COMPOSER_VENDOR_DIRECTORY = 'vendor';
	public const COMPOSER_INSTALLED_METADATA = 'composer/installed.json';
	public const COMPOSER_AUTOLOAD = 'autoload.php';
	public const DEVTOOLS_CONFIG = 'devtools.yml';
	public const VIRION_PROVENANCE_METADATA = 'devtools-provenance.json';
	public const PREPARED_VIRION_METADATA = 'devtools-prepared.json';
	public const PHAR_STUB_ENTRY = '.phar/stub.php';
	public const PHAR_SIGNATURE_ENTRY = '.phar/signature.bin';
}
