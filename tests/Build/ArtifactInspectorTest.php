<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Build;

use AxolotlBucketLabs\DevTools\Build\ArtifactInspector;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use Phar;
use function dirname;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function hash_file;
use function json_decode;
use function pack;
use function proc_close;
use function proc_open;
use function realpath;
use function stream_get_contents;
use function strlen;
use function substr;
use function substr_replace;
use function unpack;
use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

final class ArtifactInspectorTest extends TestCase
{
	public function test_inspection_returns_the_signed_artifact_without_executing_its_stub() : void
	{
		$path = $this->artifact();
		[$reader] = $this->validationServices();
		$result = (new ArtifactInspector($reader))->inspect($path);

		self::assertSame(realpath($path), $result['artifact']);
		self::assertSame(hash_file('sha256', $path), $result['sha256']);
		self::assertSame('SHA-256', $result['signature']);
		self::assertIsArray($result['plugin']);
		self::assertSame('FixturePlugin', $result['plugin']['name']);
	}

	public function test_unsigned_phar_is_rejected_even_when_php_allows_unsigned_archives() : void
	{
		$path = $this->artifact();
		$phar = new Phar($path);
		$flagsOffset = strlen($phar->getStub()) + 10;
		unset($phar);
		$bytes = file_get_contents($path);
		self::assertIsString($bytes);
		$flags = unpack('Vflags', substr($bytes, $flagsOffset, 4));
		self::assertIsArray($flags);
		self::assertIsInt($flags['flags']);
		$bytes = substr_replace($bytes, pack('V', $flags['flags'] & ~0x10000), $flagsOffset, 4);
		$unsigned = $this->temporaryDirectory . '/unsigned.phar';
		file_put_contents($unsigned, substr($bytes, 0, -40));

		$process = proc_open(
			[PHP_BINARY, '-d', 'phar.require_hash=0', dirname(__DIR__, 2) . '/bin/devtools.php', 'inspect', '--artifact=' . $unsigned, '--json'],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
		);
		self::assertIsResource($process);
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		self::assertSame(1, proc_close($process));
		self::assertSame('', $stderr);
		self::assertIsString($stdout);
		self::assertJson($stdout);
		$result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($result);
		self::assertFalse($result['success']);
		self::assertStringContainsString('Artifact has no PHAR signature', $stdout);
	}

	private function artifact() : string
	{
		$source = $this->copyFixture('ValidPlugin');
		$path = $this->temporaryDirectory . '/signed.phar';
		$phar = new Phar($path);
		$phar->buildFromDirectory($source);
		$phar->setStub('<?php throw new \\RuntimeException("The inspector executed the PHAR stub"); __HALT_COMPILER();');
		$phar->setSignatureAlgorithm(Phar::SHA256);

		return $path;
	}
}
