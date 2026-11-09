<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Support;

use RuntimeException;

final class LocalizedException extends RuntimeException
{
	/** @param array<string, int|string> $parameters */
	public function __construct(
		private readonly string $translationKey,
		private readonly array $parameters = [],
		private readonly bool $usageError = false,
	) {
		parent::__construct($translationKey);
	}

	public function render(Translator $translator) : string
	{
		return $translator->translate($this->translationKey, $this->parameters);
	}

	public function isUsageError() : bool
	{
		return $this->usageError;
	}
}
