<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

use AxolotlBucketLabs\DevTools\Support\TranslationKeys;

use AxolotlBucketLabs\DevTools\Support\Translator;
use function count;
use function implode;

final class VirionStartupReporter
{
	private readonly Translator $translator;

	public function __construct(?Translator $translator = null)
	{
		$this->translator = $translator ?? Translator::fromResources();
	}

	/**
	 * @return list<array{level: 'info'|'warning', message: string, details: list<string>}>
	 */
	public function messages(VirionRegistry $registry) : array
	{
		/** @var array<string, list<VirionRegistryEntry>> $skippedByName */
		$skippedByName = [];
		$messages = [];
		foreach ($registry->entries() as $entry) {
			if ($entry->status === VirionStatus::LOADED) {
				$manifest = $entry->requireManifest();
				$messages[] = [
					'level' => 'info',
					'message' => $this->translator->translate(TranslationKeys::VIRIONS_LOADED, ['name' => $manifest->name, 'version' => $manifest->version, 'antigen' => $manifest->antigen]),
					'details' => [],
				];
				continue;
			}
			if ($entry->status === VirionStatus::SKIPPED && $entry->manifest !== null) {
				$skippedByName[VirionName::key($entry->manifest->name)][] = $entry;
				continue;
			}
			$messages[] = [
				'level' => 'warning',
				'message' => $entry->message,
				'details' => [],
			];
		}

		foreach ($skippedByName as $entries) {
			$manifest = $entries[0]->requireManifest();
			$versions = [];
			$details = [];
			foreach ($entries as $entry) {
				$versions[] = $entry->requireManifest()->version;
				$details[] = $entry->message;
			}
			$messages[] = [
				'level' => 'warning',
				'message' => $this->translator->translate(count($entries) === 1 ? TranslationKeys::VIRIONS_SKIPPED_ONE : TranslationKeys::VIRIONS_SKIPPED_MANY, ['name' => $manifest->name, 'versions' => implode(', ', $versions)]),
				'details' => $details,
			];
		}

		return $messages;
	}
}
