<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Build;

use AxolotlBucketLabs\DevTools\Support\LocalizedDiagnostic;
use AxolotlBucketLabs\DevTools\Support\Translator;
use RuntimeException;

final class BuildException extends RuntimeException implements LocalizedDiagnostic
{
	private ?string $translationKey = null;

	/** @var array<string, int|string> */
	private array $translationParameters = [];

	/** @param array<string, int|string> $parameters */
	public static function fromTranslation(Translator $translator, string $key, array $parameters = []) : self
	{
		$exception = new self($translator->translate($key, $parameters));
		$exception->translationKey = $key;
		$exception->translationParameters = $parameters;

		return $exception;
	}

	public function hasLocalizedMessage() : bool
	{
		return $this->translationKey !== null;
	}

	public function render(Translator $translator) : string
	{
		return $this->translationKey === null
			? $this->getMessage()
			: $translator->translate($this->translationKey, $this->translationParameters);
	}
}
