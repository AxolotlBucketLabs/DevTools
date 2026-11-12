<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Support;

use Closure;
use Throwable;

final class DebugLogger
{
	/** @var Closure(string): void|null */
	private readonly ?Closure $sink;
	private ?Translator $translator;

	/** @param (Closure(string): void)|null $sink */
	public function __construct(?Closure $sink = null, ?Translator $translator = null)
	{
		$this->sink = $sink;
		$this->translator = $translator;
	}

	public function enabled() : bool
	{
		return $this->sink !== null;
	}

	public function debug(string $message) : void
	{
		if ($this->sink !== null) {
			($this->sink)($message);
		}
	}

	public function setTranslator(Translator $translator) : void
	{
		$this->translator = $translator;
	}

	/** @param array<string, int|string> $parameters */
	public function debugTranslated(string $key, array $parameters = []) : void
	{
		if ($this->sink !== null) {
			$this->debug($this->translator()->translate($key, $parameters));
		}
	}

	/** @param array<string, int|string> $parameters */
	public function debugFailure(string $key, Throwable $error, array $parameters = []) : void
	{
		if ($this->sink !== null) {
			$parameters['error'] = ExceptionMessage::render($error, $this->translator());
			$this->debugTranslated($key, $parameters);
		}
	}

	public function debugThrowable(Throwable $error) : void
	{
		if ($this->sink !== null) {
			$this->debug(ExceptionMessage::render($error, $this->translator()));
		}
	}

	private function translator() : Translator
	{
		return $this->translator ??= Translator::fromResources();
	}
}
