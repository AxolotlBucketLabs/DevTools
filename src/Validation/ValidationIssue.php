<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Validation;

use function ucfirst;

final class ValidationIssue
{
	public function __construct(
		public readonly Severity $severity,
		public readonly string $code,
		public readonly string $message,
		public readonly ?string $path = null,
		public readonly ?string $suggestion = null,
	) {}

	public function format() : string
	{
		$text = ucfirst($this->severity->value) . " [{$this->code}] {$this->message}";
		if ($this->path !== null) {
			$text .= "\nThe related file is {$this->path}.";
		}
		if ($this->suggestion !== null) {
			$text .= "\n{$this->suggestion}";
		}

		return $text;
	}
}
