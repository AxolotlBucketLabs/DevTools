<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Validation;

use AxolotlBucketLabs\DevTools\Support\Translator;
use AxolotlBucketLabs\DevTools\Validation\Severity;
use AxolotlBucketLabs\DevTools\Validation\ValidationIssue;
use PHPUnit\Framework\TestCase;

final class ValidationIssueTest extends TestCase
{
	public function test_issue_labels_are_translated_and_technical_details_are_preserved() : void
	{
		$translator = Translator::fromResources('vie');
		foreach ([
			[Severity::ERROR, 'Lỗi'],
			[Severity::WARNING, 'Cảnh báo'],
			[Severity::NOTICE, 'Ghi chú'],
		] as [$severity, $label]) {
			$issue = new ValidationIssue($severity, 'fixture.rule', 'Technical validator detail.', 'plugin.yml', 'Correct the manifest.');
			$message = $issue->format($translator);

			self::assertStringStartsWith($label . ' [fixture.rule]: Technical validator detail.', $message);
			self::assertStringContainsString('File liên quan: plugin.yml.', $message);
			self::assertStringContainsString('Đề xuất xử lý: Correct the manifest.', $message);
		}
	}
}
