<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Virion;

use AxolotlBucketLabs\DevTools\Support\Translator;
use AxolotlBucketLabs\DevTools\Virion\VirionManifest;
use AxolotlBucketLabs\DevTools\Virion\VirionRegistry;
use AxolotlBucketLabs\DevTools\Virion\VirionRegistryEntry;
use AxolotlBucketLabs\DevTools\Virion\VirionStartupReporter;
use AxolotlBucketLabs\DevTools\Virion\VirionStatus;
use PHPUnit\Framework\TestCase;

final class VirionStartupReporterTest extends TestCase
{
	public function test_skipped_candidates_are_grouped_by_virion_name() : void
	{
		$registry = new VirionRegistry();
		$registry->record($this->entry('1.0.0', 'first path'));
		$registry->record($this->entry('1.1.0', 'second path'));

		$messages = (new VirionStartupReporter())->messages($registry);

		self::assertCount(1, $messages);
		self::assertSame('warning', $messages[0]['level']);
		self::assertSame(
			'Skipped SharedVirion candidates (1.0.0, 1.1.0). See /devtools virions for selection details.',
			$messages[0]['message'],
		);
		self::assertSame([
			'Skipped virion SharedVirion. first path',
			'Skipped virion SharedVirion. second path',
		], $messages[0]['details']);
	}

	private function entry(string $version, string $message) : VirionRegistryEntry
	{
		return new VirionRegistryEntry(
			$version,
			new VirionManifest('SharedVirion', $version, 'Shared\\Virion', [], [], [], $version),
			VirionStatus::SKIPPED,
			$message,
		);
	}

	public function test_loaded_and_skipped_summaries_follow_the_runtime_language() : void
	{
		$registry = new VirionRegistry();
		$manifest = new VirionManifest('ExampleVirion', '1.2.3', 'Example\\Library', [], [], [], 'virion.yml');
		$registry->record(new VirionRegistryEntry('source', $manifest, VirionStatus::LOADED, 'Loaded ExampleVirion 1.2.3 (Example\\Library).'));
		$registry->record($this->entry('1.0.0', 'Technical selection detail'));
		$messages = (new VirionStartupReporter(Translator::fromResources('vie')))->messages($registry);

		self::assertSame('Đã nạp ExampleVirion 1.2.3 (Example\\Library).', $messages[0]['message']);
		self::assertSame('Đã bỏ qua một bản SharedVirion (1.0.0). Xem /devtools virions để biết chi tiết lựa chọn.', $messages[1]['message']);
		self::assertSame(['Đã bỏ qua ứng viên virion SharedVirion. Technical selection detail'], $messages[1]['details']);
	}

	public function test_failure_and_conflict_summaries_are_translated_and_keep_technical_details() : void
	{
		$registry = new VirionRegistry();
		$manifest = new VirionManifest('ExampleVirion', '1.2.3', 'Example\\Library', [], [], [], 'virion.yml');
		$registry->record(new VirionRegistryEntry('failed path', $manifest, VirionStatus::FAILED, 'Technical load failure.'));
		$registry->record(new VirionRegistryEntry('conflict path', $manifest, VirionStatus::CONFLICT, 'Technical conflict detail.'));

		$messages = (new VirionStartupReporter(Translator::fromResources('vie')))->messages($registry);

		self::assertCount(2, $messages);
		self::assertStringStartsWith('Không thể nạp virion ExampleVirion.', $messages[0]['message']);
		self::assertStringContainsString('Technical load failure.', $messages[0]['message']);
		self::assertStringStartsWith('Virion ExampleVirion gặp xung đột.', $messages[1]['message']);
		self::assertStringContainsString('Technical conflict detail.', $messages[1]['message']);
	}
}
