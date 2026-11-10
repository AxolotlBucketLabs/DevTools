<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Support\ArtifactFile;
use AxolotlBucketLabs\DevTools\Support\HashAlgorithm;
use AxolotlBucketLabs\DevTools\Support\RandomToken;
use FilesystemIterator;
use Phar;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;
use function copy;
use function crc32;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function hash;
use function is_array;
use function is_int;
use function ksort;
use function pack;
use function realpath;
use function str_replace;
use function strlen;
use function strrpos;
use function substr;
use function substr_replace;
use function unpack;
use const DIRECTORY_SEPARATOR;
use const LOCK_EX;
use const SORT_STRING;

/**
 * Writes executable ZIP-based PHARs with stable file metadata and ordering.
 */
final class PharArchiveWriter
{
	private const ARCHIVE_MTIME = 946684800;
	private const ZIP_END_OF_CENTRAL_DIRECTORY_SIGNATURE = "PK\x05\x06";
	private const ZIP_CENTRAL_DIRECTORY_SIGNATURE = "PK\x01\x02";
	private const ZIP_END_OF_CENTRAL_DIRECTORY_ENTRY_COUNT_OFFSET = 10;
	private const ZIP_END_OF_CENTRAL_DIRECTORY_OFFSET_OFFSET = 16;
	private const ZIP_END_OF_CENTRAL_DIRECTORY_COMMENT_LENGTH_OFFSET = 20;
	private const ZIP_END_OF_CENTRAL_DIRECTORY_COMMENT_OFFSET = 22;
	private const ZIP_CENTRAL_DIRECTORY_ENTRY_SIZE = 46;
	private const ZIP_CENTRAL_DIRECTORY_FILENAME_LENGTH_OFFSET = 28;
	private const ZIP_CENTRAL_DIRECTORY_EXTRA_LENGTH_OFFSET = 30;
	private const ZIP_CENTRAL_DIRECTORY_COMMENT_LENGTH_OFFSET = 32;
	private const ZIP_CENTRAL_DIRECTORY_LOCAL_HEADER_OFFSET = 42;
	private const ZIP_CENTRAL_DIRECTORY_FILENAME_OFFSET = 46;
	private const ZIP_LOCAL_FILE_HEADER_TIME_OFFSET = 10;
	private const ZIP_LOCAL_FILE_HEADER_CRC_OFFSET = 14;
	private const ZIP_LOCAL_FILE_HEADER_UNCOMPRESSED_SIZE_OFFSET = 22;
	private const ZIP_LOCAL_FILE_HEADER_FILENAME_LENGTH_OFFSET = 26;
	private const ZIP_LOCAL_FILE_HEADER_EXTRA_LENGTH_OFFSET = 28;
	private const ZIP_LOCAL_FILE_HEADER_DATA_OFFSET = 30;
	private const ZIP_CENTRAL_DIRECTORY_TIME_OFFSET = 12;
	private const ZIP_CENTRAL_DIRECTORY_CRC_OFFSET = 16;
	private const ZIP_CENTRAL_DIRECTORY_UNCOMPRESSED_SIZE_OFFSET = 24;
	private const ZIP_UINT16_SIZE = 2;
	private const ZIP_UINT32_SIZE = 4;
	private const ZIP_CRC32_SIZE = self::ZIP_UINT32_SIZE;
	private const DOS_TIME_MIDNIGHT = 0;
	private const DOS_DATE_ARCHIVE_VALUE = 0x2801;
	private const SHA256_DIGEST_LENGTH = 32;
	private const SHA256_SIGNATURE_FORMAT = 'V2';
	private const UINT16_PACK_FORMAT = 'v';
	private const UINT32_PACK_FORMAT = 'V';
	private const UINT16_UNPACK_FORMAT = 'vvalue';
	private const UINT32_UNPACK_FORMAT = 'Vvalue';
	private const UNIX_FILE_MODE = 0100644;
	private const UNIX_FILE_MODE_SHIFT = 16;

	public function write(string $stage, string $path) : void
	{
		$zipPath = dirname($path) . DIRECTORY_SEPARATOR . '.devtools-archive-' . RandomToken::pathSuffix() . ArtifactFile::ZIP_EXTENSION;
		$convertedPath = substr($zipPath, 0, -strlen(ArtifactFile::ZIP_EXTENSION)) . ArtifactFile::PHAR_EXTENSION;
		$zip = new ZipArchive();
		$opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		if ($opened !== true) {
			throw new BuildException("Cannot create temporary ZIP archive {$zipPath}.");
		}

		try {
			foreach ($this->files($stage) as $relative => $absolute) {
				if (!$zip->addFile($absolute, $relative)
					|| !$zip->setMtimeName($relative, self::ARCHIVE_MTIME)
					|| !$zip->setExternalAttributesName($relative, ZipArchive::OPSYS_UNIX, self::UNIX_FILE_MODE << self::UNIX_FILE_MODE_SHIFT)) {
					throw new BuildException("Cannot add {$relative} to temporary ZIP archive.");
				}
			}
			if (!$zip->close()) {
				throw new BuildException("Cannot finalize temporary ZIP archive {$zipPath}.");
			}
		} catch (\Throwable $error) {
			$zip->close();
			throw $error;
		}

		$data = null;
		$phar = null;
		try {
			$data = new PharData($zipPath);
			$phar = $data->convertToExecutable(Phar::ZIP, Phar::NONE, ArtifactFile::PHAR_EXTENSION);
			$phar->setStub("<?php __HALT_COMPILER();\n");
			$phar->setSignatureAlgorithm(Phar::SHA256);
			unset($phar, $data);
			$this->normalizeArchive($convertedPath);
			if (!copy($convertedPath, $path)) {
				throw new BuildException("Cannot install deterministic PHAR at {$path}.");
			}
		} finally {
			unset($phar, $data);
			PharSnapshot::remove($zipPath);
			PharSnapshot::remove($convertedPath);
		}
	}

	/** @return array<string, string> */
	private function files(string $stage) : array
	{
		$root = realpath($stage);
		if ($root === false) {
			throw new BuildException("Cannot resolve PHAR staging directory {$stage}.");
		}

		$files = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
		);
		foreach ($iterator as $file) {
			if (!$file instanceof SplFileInfo || !$file->isFile()) {
				continue;
			}
			$absolute = $file->getRealPath();
			if ($absolute === false) {
				throw new BuildException("Cannot resolve staged PHAR file {$file->getPathname()}.");
			}
			$relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($root) + 1));
			$files[$relative] = $absolute;
		}
		ksort($files, SORT_STRING);

		return $files;
	}

	private function normalizeArchive(string $path) : void
	{
		$data = file_get_contents($path);
		if ($data === false) {
			throw new BuildException("Cannot read executable PHAR {$path} for metadata normalization.");
		}

		$endOfCentralDirectory = strrpos($data, self::ZIP_END_OF_CENTRAL_DIRECTORY_SIGNATURE);
		if ($endOfCentralDirectory === false) {
			throw new BuildException("Cannot locate the ZIP directory in executable PHAR {$path}.");
		}
		$centralDirectoryOffset = $this->uint32($data, $endOfCentralDirectory + self::ZIP_END_OF_CENTRAL_DIRECTORY_OFFSET_OFFSET);
		$entryCount = $this->uint16($data, $endOfCentralDirectory + self::ZIP_END_OF_CENTRAL_DIRECTORY_ENTRY_COUNT_OFFSET);
		$centralOffset = $centralDirectoryOffset;
		$entries = [];
		for ($index = 0; $index < $entryCount; $index++) {
			if (substr($data, $centralOffset, self::ZIP_UINT32_SIZE) !== self::ZIP_CENTRAL_DIRECTORY_SIGNATURE) {
				throw new BuildException("Cannot parse the ZIP directory in executable PHAR {$path}.");
			}
			$nameLength = $this->uint16($data, $centralOffset + self::ZIP_CENTRAL_DIRECTORY_FILENAME_LENGTH_OFFSET);
			$extraLength = $this->uint16($data, $centralOffset + self::ZIP_CENTRAL_DIRECTORY_EXTRA_LENGTH_OFFSET);
			$commentLength = $this->uint16($data, $centralOffset + self::ZIP_CENTRAL_DIRECTORY_COMMENT_LENGTH_OFFSET);
			$name = substr($data, $centralOffset + self::ZIP_CENTRAL_DIRECTORY_FILENAME_OFFSET, $nameLength);
			$entries[$name] = [
				'centralOffset' => $centralOffset,
				'localOffset' => $this->uint32($data, $centralOffset + self::ZIP_CENTRAL_DIRECTORY_LOCAL_HEADER_OFFSET),
			];
			$centralOffset += self::ZIP_CENTRAL_DIRECTORY_ENTRY_SIZE + $nameLength + $extraLength + $commentLength;
		}

		foreach ([ArtifactFile::PHAR_STUB_ENTRY, ArtifactFile::PHAR_SIGNATURE_ENTRY] as $name) {
			$entry = $entries[$name] ?? null;
			if ($entry === null) {
				throw new BuildException("Executable PHAR {$path} is missing {$name}.");
			}
			$this->setArchiveTimestamp($data, $entry['localOffset'] + self::ZIP_LOCAL_FILE_HEADER_TIME_OFFSET);
			$this->setArchiveTimestamp($data, $entry['centralOffset'] + self::ZIP_CENTRAL_DIRECTORY_TIME_OFFSET);
		}

		$signatureEntry = $entries[ArtifactFile::PHAR_SIGNATURE_ENTRY];
		$signatureLocalOffset = $signatureEntry['localOffset'];
		$signatureCentralOffset = $signatureEntry['centralOffset'];
		$signatureNameLength = $this->uint16($data, $signatureLocalOffset + self::ZIP_LOCAL_FILE_HEADER_FILENAME_LENGTH_OFFSET);
		$signatureExtraLength = $this->uint16($data, $signatureLocalOffset + self::ZIP_LOCAL_FILE_HEADER_EXTRA_LENGTH_OFFSET);
		$signatureOffset = $signatureLocalOffset + self::ZIP_LOCAL_FILE_HEADER_DATA_OFFSET + $signatureNameLength + $signatureExtraLength;
		$commentLength = $this->uint16($data, $endOfCentralDirectory + self::ZIP_END_OF_CENTRAL_DIRECTORY_COMMENT_LENGTH_OFFSET);
		$signedBytes = substr($data, 0, $signatureLocalOffset)
			. substr($data, $centralDirectoryOffset, $signatureCentralOffset - $centralDirectoryOffset)
			. substr($data, $endOfCentralDirectory + self::ZIP_END_OF_CENTRAL_DIRECTORY_COMMENT_OFFSET, $commentLength);
		$signature = pack(self::SHA256_SIGNATURE_FORMAT, Phar::SHA256, self::SHA256_DIGEST_LENGTH) . hash(HashAlgorithm::SHA256, $signedBytes, true);
		$signatureLength = strlen($signature);
		if ($this->uint32($data, $signatureLocalOffset + self::ZIP_LOCAL_FILE_HEADER_UNCOMPRESSED_SIZE_OFFSET) !== $signatureLength
			|| $this->uint32($data, $signatureCentralOffset + self::ZIP_CENTRAL_DIRECTORY_UNCOMPRESSED_SIZE_OFFSET) !== $signatureLength) {
			throw new BuildException("Executable PHAR {$path} has an unexpected signature size.");
		}
		$data = substr_replace($data, $signature, $signatureOffset, $signatureLength);
		$crc = crc32($signature);
		$data = substr_replace($data, pack(self::UINT32_PACK_FORMAT, $crc), $signatureLocalOffset + self::ZIP_LOCAL_FILE_HEADER_CRC_OFFSET, self::ZIP_CRC32_SIZE);
		$data = substr_replace($data, pack(self::UINT32_PACK_FORMAT, $crc), $signatureCentralOffset + self::ZIP_CENTRAL_DIRECTORY_CRC_OFFSET, self::ZIP_CRC32_SIZE);
		if (file_put_contents($path, $data, LOCK_EX) !== strlen($data)) {
			throw new BuildException("Cannot write normalized executable PHAR {$path}.");
		}
	}

	private function setArchiveTimestamp(string &$data, int $offset) : void
	{
		$data = substr_replace($data, pack(self::UINT16_PACK_FORMAT, self::DOS_TIME_MIDNIGHT), $offset, self::ZIP_UINT16_SIZE);
		$data = substr_replace($data, pack(self::UINT16_PACK_FORMAT, self::DOS_DATE_ARCHIVE_VALUE), $offset + self::ZIP_UINT16_SIZE, self::ZIP_UINT16_SIZE);
	}

	private function uint16(string $data, int $offset) : int
	{
		$values = unpack(self::UINT16_UNPACK_FORMAT, substr($data, $offset, self::ZIP_UINT16_SIZE));
		$value = is_array($values) ? ($values['value'] ?? null) : null;
		if (!is_int($value)) {
			throw new BuildException('Invalid 16-bit value in executable PHAR.');
		}

		return $value;
	}

	private function uint32(string $data, int $offset) : int
	{
		$values = unpack(self::UINT32_UNPACK_FORMAT, substr($data, $offset, self::ZIP_UINT32_SIZE));
		$value = is_array($values) ? ($values['value'] ?? null) : null;
		if (!is_int($value)) {
			throw new BuildException('Invalid 32-bit value in executable PHAR.');
		}

		return $value;
	}
}
