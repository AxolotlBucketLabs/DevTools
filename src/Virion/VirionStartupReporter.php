<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

use function count;
use function implode;

final class VirionStartupReporter
{
    /**
     * @return list<array{level: 'info'|'warning', message: string, details: list<string>}>
     */
    public function messages(VirionRegistry $registry): array
    {
        /** @var array<string, list<VirionRegistryEntry>> $skippedByName */
        $skippedByName = [];
        $messages = [];
        foreach ($registry->entries() as $entry) {
            if ($entry->status === VirionStatus::LOADED) {
                $messages[] = [
                    'level' => 'info',
                    'message' => $entry->message,
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
            $label = count($entries) === 1 ? 'candidate' : 'candidates';
            $messages[] = [
                'level' => 'warning',
                'message' => "Skipped {$manifest->name} {$label} (" . implode(', ', $versions) . '). See /devtools virions for selection details.',
                'details' => $details,
            ];
        }

        return $messages;
    }
}
