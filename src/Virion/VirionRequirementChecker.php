<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

use AxolotlBucketLabs\DevTools\Support\TranslationKeys;
use AxolotlBucketLabs\DevTools\Support\Translator;

final class VirionRequirementChecker
{
	private readonly Translator $translator;

	public function __construct(
		private readonly VirionRegistry $registry,
		private readonly VirionProjectSelector $selector,
		?Translator $translator = null,
	) {
		$this->translator = $translator ?? Translator::fromResources();
	}

	/** @param list<VirionRequirement> $requirements */
	public function failure(array $requirements) : ?string
	{
		foreach ($requirements as $requirement) {
			$entry = $this->registry->findByName($requirement->name);
			if ($entry === null) {
				return $this->translator->translate(
					TranslationKeys::VIRIONS_REQUIREMENT_MISSING,
					['name' => $requirement->name, 'requirements' => $this->selector->describeRequirements($requirements)],
				);
			}

			$manifest = $entry->requireManifest();
			if (!$requirement->matches($manifest->version)) {
				return $this->translator->translate(
					TranslationKeys::VIRIONS_REQUIREMENT_VERSION_MISMATCH,
					[
						'name' => $requirement->name,
						'loaded' => $manifest->version,
						'requirements' => $this->selector->describeRequirements([$requirement]),
					],
				);
			}
		}

		return null;
	}
}
