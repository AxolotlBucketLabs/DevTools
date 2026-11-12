<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Support;

use Throwable;

final class ExceptionMessage
{
	public static function render(Throwable $error, Translator $translator) : string
	{
		if ($error instanceof LocalizedDiagnostic && $error->hasLocalizedMessage()) {
			return $error->render($translator);
		}

		return $error->getMessage();
	}

	public static function summary(Throwable $error, Translator $translator, string $fallbackKey) : string
	{
		if ($error instanceof LocalizedDiagnostic && $error->hasLocalizedMessage()) {
			return $error->render($translator);
		}

		return $translator->translate($fallbackKey, ['error' => $error->getMessage()]);
	}
}
