<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use FilesystemIterator;
use Phar;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;
use function bin2hex;
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
use function random_bytes;
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

	public function write(string $stage, string $path) : void
	{
		$zipPath = dirname($path) . DIRECTORY_SEPARATOR . '.devtools-archive-' . bin2hex(random_bytes(8)) . '.zip';
		$convertedPath = substr($zipPath, 0, -strlen('.zip')) . '.phar';
		$zip = new ZipArchive();
		$opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		if ($opened !== true) {
			throw new BuildException("Cannot create temporary ZIP archive {$zipPath}.");
		}

		try {
			foreach ($this->files($stage) as $relative => $absolute) {
				if (!$zip->addFile($absolute, $relative)
					|| !$zip->setMtimeName($relative, self::ARCHIVE_MTIME)
					|| !$zip->setExternalAttributesName($relative, ZipArchive::OPSYS_UNIX, 0100644 << 16)) {
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
			$phar = $data->convertToExecutable(Phar::ZIP, Phar::NONE, '.phar');
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

		$endOfCentralDirectory = strrpos($data, "PK\x05\x06");
		if ($endOfCentralDirectory === false) {
			throw new BuildException("Cannot locate the ZIP directory in executable PHAR {$path}.");
		}
		$centralDirectoryOffset = $this->uint32($data, $endOfCentralDirectory + 16);
		$entryCount = $this->uint16($data, $endOfCentralDirectory + 10);
		$centralOffset = $centralDirectoryOffset;
		$entries = [];
		for ($index = 0; $index < $entryCount; $index++) {
			if (substr($data, $centralOffset, 4) !== "PK\x01\x02") {
				throw new BuildException("Cannot parse the ZIP directory in executable PHAR {$path}.");
			}
			$nameLength = $this->uint16($data, $centralOffset + 28);
			$extraLength = $this->uint16($data, $centralOffset + 30);
			$commentLength = $this->uint16($data, $centralOffset + 32);
			$name = substr($data, $centralOffset + 46, $nameLength);
			$entries[$name] = [
				'centralOffset' => $centralOffset,
				'localOffset' => $this->uint32($data, $centralOffset + 42),
			];
			$centralOffset += 46 + $nameLength + $extraLength + $commentLength;
		}

		foreach (['.phar/stub.php', '.phar/signature.bin'] as $name) {
			$entry = $entries[$name] ?? null;
			if ($entry === null) {
				throw new BuildException("Executable PHAR {$path} is missing {$name}.");
			}
			$this->setArchiveTimestamp($data, $entry['localOffset'] + 10);
			$this->setArchiveTimestamp($data, $entry['centralOffset'] + 12);
		}

		$signatureEntry = $entries['.phar/signature.bin'];
		$signatureLocalOffset = $signatureEntry['localOffset'];
		$signatureCentralOffset = $signatureEntry['centralOffset'];
		$signatureNameLength = $this->uint16($data, $signatureLocalOffset + 26);
		$signatureExtraLength = $this->uint16($data, $signatureLocalOffset + 28);
		$signatureOffset = $signatureLocalOffset + 30 + $signatureNameLength + $signatureExtraLength;
		$commentLength = $this->uint16($data, $endOfCentralDirectory + 20);
		$signedBytes = substr($data, 0, $signatureLocalOffset)
			. substr($data, $centralDirectoryOffset, $signatureCentralOffset - $centralDirectoryOffset)
			. substr($data, $endOfCentralDirectory + 22, $commentLength);
		$signature = pack('V2', Phar::SHA256, 32) . hash('sha256', $signedBytes, true);
		$signatureLength = strlen($signature);
		if ($this->uint32($data, $signatureLocalOffset + 22) !== $signatureLength
			|| $this->uint32($data, $signatureCentralOffset + 24) !== $signatureLength) {
			throw new BuildException("Executable PHAR {$path} has an unexpected signature size.");
		}
		$data = substr_replace($data, $signature, $signatureOffset, $signatureLength);
		$crc = crc32($signature);
		$data = substr_replace($data, pack('V', $crc), $signatureLocalOffset + 14, 4);
		$data = substr_replace($data, pack('V', $crc), $signatureCentralOffset + 16, 4);
		if (file_put_contents($path, $data, LOCK_EX) !== strlen($data)) {
			throw new BuildException("Cannot write normalized executable PHAR {$path}.");
		}
	}

	private function setArchiveTimestamp(string &$data, int $offset) : void
	{
		$data = substr_replace($data, pack('v', 0), $offset, 2);
		$data = substr_replace($data, pack('v', 0x2801), $offset + 2, 2);
	}

	private function uint16(string $data, int $offset) : int
	{
		$values = unpack('vvalue', substr($data, $offset, 2));
		$value = is_array($values) ? ($values['value'] ?? null) : null;
		if (!is_int($value)) {
			throw new BuildException('Invalid 16-bit value in executable PHAR.');
		}

		return $value;
	}

	private function uint32(string $data, int $offset) : int
	{
		$values = unpack('Vvalue', substr($data, $offset, 4));
		$value = is_array($values) ? ($values['value'] ?? null) : null;
		if (!is_int($value)) {
			throw new BuildException('Invalid 32-bit value in executable PHAR.');
		}

		return $value;
	}
}
