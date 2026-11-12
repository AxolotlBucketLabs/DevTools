<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Support;

interface LocalizedDiagnostic
{
	public function hasLocalizedMessage() : bool;

	public function render(Translator $translator) : string;
}
