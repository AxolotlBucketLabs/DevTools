<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

final class VirionSelectionPlan
{
	/**
	 * Diagnostics recorded before preflight must stay separate from decisions applied after it.
	 *
	 * @param array<string, array{string, VirionProject}>                                                                                                               $selectedProjects
	 * @param list<array{type: 'selected', location: string, project: VirionProject}|array{type: 'skipped', location: string, project: VirionProject, message: string}> $actions
	 * @param list<VirionRegistryEntry>                                                                                                                                 $beforeLoad
	 * @param list<VirionRegistryEntry>                                                                                                                                 $afterLoad
	 */
	public function __construct(
		public readonly array $selectedProjects,
		public readonly array $actions,
		public readonly array $beforeLoad,
		public readonly array $afterLoad,
		public readonly bool $blocked = false,
	) {}
}
