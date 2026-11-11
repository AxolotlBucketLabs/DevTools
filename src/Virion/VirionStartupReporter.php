<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

use AxolotlBucketLabs\DevTools\Support\TranslationKeys;
use AxolotlBucketLabs\DevTools\Support\Translator;
use function basename;
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
				'message' => $this->formatDiagnostic($entry),
				'details' => [],
			];
		}

		foreach ($skippedByName as $entries) {
			$manifest = $entries[0]->requireManifest();
			$versions = [];
			$details = [];
			foreach ($entries as $entry) {
				$versions[] = $entry->requireManifest()->version;
				$details[] = $this->formatDiagnostic($entry);
			}
			$messages[] = [
				'level' => 'warning',
				'message' => $this->translator->translate(count($entries) === 1 ? TranslationKeys::VIRIONS_SKIPPED_ONE : TranslationKeys::VIRIONS_SKIPPED_MANY, ['name' => $manifest->name, 'versions' => implode(', ', $versions)]),
				'details' => $details,
			];
		}

		return $messages;
	}

	public function formatDiagnostic(VirionRegistryEntry $entry) : string
	{
		$key = match ($entry->status) {
			VirionStatus::FAILED => TranslationKeys::VIRIONS_LOAD_FAILED_DETAIL,
			VirionStatus::CONFLICT => TranslationKeys::VIRIONS_CONFLICT_DETAIL,
			VirionStatus::SKIPPED => TranslationKeys::VIRIONS_SKIPPED_DETAIL,
			VirionStatus::LOADED => TranslationKeys::VIRIONS_LOADED,
		};
		if ($entry->status === VirionStatus::LOADED) {
			$manifest = $entry->requireManifest();

			return $this->translator->translate($key, [
				'name' => $manifest->name,
				'version' => $manifest->version,
				'antigen' => $manifest->antigen,
			]);
		}

		return $this->translator->translate($key, [
			'name' => $entry->manifest->name ?? basename($entry->location),
			'detail' => $entry->message,
		]);
	}
}
