<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Validation;

use AxolotlBucketLabs\DevTools\Support\TranslationKeys;
use AxolotlBucketLabs\DevTools\Support\Translator;

final class ValidationIssue
{
	public function __construct(
		public readonly Severity $severity,
		public readonly string $code,
		public readonly string $message,
		public readonly ?string $path = null,
		public readonly ?string $suggestion = null,
	) {}

	public function format(?Translator $translator = null) : string
	{
		$translator ??= Translator::fromResources();
		$severityKey = match ($this->severity) {
			Severity::ERROR => TranslationKeys::VALIDATION_ERROR,
			Severity::WARNING => TranslationKeys::VALIDATION_WARNING,
			Severity::NOTICE => TranslationKeys::VALIDATION_NOTICE,
		};
		$text = $translator->translate($severityKey, ['code' => $this->code, 'message' => $this->message]);
		if ($this->path !== null) {
			$text .= "\n" . $translator->translate(TranslationKeys::VALIDATION_RELATED_FILE, ['path' => $this->path]);
		}
		if ($this->suggestion !== null) {
			$text .= "\n" . $translator->translate(TranslationKeys::VALIDATION_SUGGESTION, ['suggestion' => $this->suggestion]);
		}

		return $text;
	}
}
