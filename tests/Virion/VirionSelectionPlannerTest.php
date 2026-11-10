<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Virion;

use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use AxolotlBucketLabs\DevTools\Virion\VirionException;
use AxolotlBucketLabs\DevTools\Virion\VirionManifest;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionName;
use AxolotlBucketLabs\DevTools\Virion\VirionPlatformRequirements;
use AxolotlBucketLabs\DevTools\Virion\VirionProject;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectFactory;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectSelector;
use AxolotlBucketLabs\DevTools\Virion\VirionRequirement;
use AxolotlBucketLabs\DevTools\Virion\VirionSelectionPlanner;
use AxolotlBucketLabs\DevTools\Virion\VirionStatus;
use Phar;
use pocketmine\plugin\ApiVersion;
use function array_column;
use function array_keys;
use function array_map;
use function file_put_contents;
use function implode;

final class VirionSelectionPlannerTest extends TestCase
{
	public function test_unordered_candidates_select_highest_versions_and_defer_missing_requirements() : void
	{
		$older = $this->project('Zed', '1.0.0', 'Older');
		$newer = $this->project('Zed', '2.0.0', 'Newer');
		$first = $this->project('Alpha');
		$plan = $this->planner()->plan(
			$this->catalog($older, $newer, $first),
			[new VirionRequirement('Missing', '^1.0.0', 'Plugin')],
			$this->temporaryDirectory,
		);

		self::assertFalse($plan->blocked);
		self::assertSame([VirionName::key('Alpha'), VirionName::key('Zed')], array_keys($plan->selectedProjects));
		self::assertSame($newer, $plan->selectedProjects[VirionName::key('Zed')][1]);
		self::assertSame([], $plan->beforeLoad);
		self::assertSame(['selected', 'skipped', 'selected'], array_column($plan->actions, 'type'));
		self::assertSame([
			'type' => 'skipped', 'location' => $older->location, 'project' => $older,
			'message' => "Skipped Zed 1.0.0 at {$older->location}: a higher compatible version 2.0.0 was selected.",
		], $plan->actions[1]);
		self::assertCount(1, $plan->afterLoad);
		self::assertNull($plan->afterLoad[0]->manifest);
		self::assertSame("Required virion Missing is missing. The project requests ^1.0.0 from Plugin. Add a compatible package to {$this->temporaryDirectory}.", $plan->afterLoad[0]->message);
	}

	public function test_incompatible_folder_blocks_same_name_packaged_fallback() : void
	{
		$folder = $this->project('Library', api: '99.0.0');
		$phar = $this->packaged($this->project('Library', destination: 'Packaged'));
		$plan = $this->planner()->plan($this->catalog($phar, $folder), [], $this->temporaryDirectory);

		self::assertFalse($plan->blocked);
		self::assertSame([], $plan->selectedProjects);
		self::assertSame([], $plan->actions);
		self::assertSame([], $plan->afterLoad);
		self::assertSame([VirionStatus::FAILED, VirionStatus::SKIPPED], array_column($plan->beforeLoad, 'status'));
		self::assertSame('Cannot load Library: it declares API 99.0.0, but this server provides 5.44.0.', $plan->beforeLoad[0]->message);
		self::assertSame("Skipped Library 1.0.0 at {$phar->location}: a development folder for this virion name is preferred over a packaged PHAR.", $plan->beforeLoad[1]->message);
	}

	public function test_unreadable_folder_prevents_packaged_fallback_without_a_false_missing_diagnostic() : void
	{
		$phar = $this->packaged($this->project('Library'));
		$plan = $this->planner()->plan(
			$this->catalog($phar),
			[new VirionRequirement('Library', '*', 'Plugin')],
			$this->temporaryDirectory,
			[VirionName::key('Library') => 'Invalid source path.'],
		);

		self::assertSame([], $plan->selectedProjects);
		self::assertSame([], $plan->afterLoad);
		self::assertCount(1, $plan->beforeLoad);
		self::assertSame(VirionStatus::SKIPPED, $plan->beforeLoad[0]->status);
		self::assertSame("Skipped Library 1.0.0 at {$phar->location}: a development folder with the same name could not be loaded. Invalid source path.", $plan->beforeLoad[0]->message);
	}

	public function test_duplicate_versions_do_not_block_an_independent_package() : void
	{
		$left = $this->project('Duplicate', destination: 'Left');
		$right = $this->project('Duplicate', destination: 'Right');
		$healthy = $this->project('Healthy');
		$plan = $this->planner()->plan($this->catalog($left, $right, $healthy), [], $this->temporaryDirectory);

		self::assertFalse($plan->blocked);
		self::assertSame([VirionName::key('Healthy') => [$healthy->location, $healthy]], $plan->selectedProjects);
		self::assertSame([VirionStatus::CONFLICT, VirionStatus::CONFLICT], array_column($plan->beforeLoad, 'status'));
		self::assertSame("Virion Duplicate version 1.0.0 exists in multiple locations: {$left->location}, {$right->location}. Remove the duplicate copy.", $plan->beforeLoad[0]->message);
		self::assertSame($plan->beforeLoad[0]->message, $plan->beforeLoad[1]->message);
	}

	public function test_transitive_graph_failure_blocks_the_entire_selection() : void
	{
		$consumer = $this->project('Consumer', requirements: 'virions: [Missing]');
		$healthy = $this->project('Healthy');
		$plan = $this->planner()->plan($this->catalog($healthy, $consumer), [], $this->temporaryDirectory);

		self::assertTrue($plan->blocked);
		self::assertSame([], $plan->selectedProjects);
		self::assertSame([], $plan->actions);
		self::assertSame([], $plan->afterLoad);
		self::assertSame([VirionStatus::CONFLICT, VirionStatus::CONFLICT], array_column($plan->beforeLoad, 'status'));
		self::assertSame(['Consumer', 'Healthy'], array_map(static fn($entry) : ?string => $entry->manifest?->name, $plan->beforeLoad));
		self::assertStringContainsString('Missing', $plan->beforeLoad[0]->message);
		self::assertSame($plan->beforeLoad[0]->message, $plan->beforeLoad[1]->message);
	}

	public function test_alias_resolution_keeps_empty_graph_failure_before_loading() : void
	{
		$plan = $this->planner()->plan([], [new VirionRequirement('Missing', '*', 'Plugin')], $this->temporaryDirectory, hasRequirementAliases: true);

		self::assertTrue($plan->blocked);
		self::assertSame([], $plan->afterLoad);
		self::assertCount(1, $plan->beforeLoad);
		self::assertNull($plan->beforeLoad[0]->manifest);
		self::assertSame($this->temporaryDirectory, $plan->beforeLoad[0]->location);
		self::assertSame(VirionStatus::FAILED, $plan->beforeLoad[0]->status);
		self::assertStringContainsString('Missing', $plan->beforeLoad[0]->message);
	}

	private function planner() : VirionSelectionPlanner
	{
		return new VirionSelectionPlanner(new VirionProjectSelector(), static function (VirionManifest $manifest) : void {
			(new VirionPlatformRequirements())->validateManifest($manifest);
			if ($manifest->api !== [] && !ApiVersion::isCompatible('5.44.0', $manifest->api)) {
				throw new VirionException("Cannot load {$manifest->name}: it declares API " . implode(', ', $manifest->api) . ', but this server provides 5.44.0.');
			}
		});
	}

	private function project(string $name, string $version = '1.0.0', ?string $destination = null, string $api = '5.0.0', string $requirements = '') : VirionProject
	{
		$path = $this->copyFixture('SharedVirion', $destination ?? $name);
		file_put_contents($path . '/virion.yml', "name: {$name}\nversion: {$version}\nantigen: Shared\\Virion\napi: {$api}\n{$requirements}\n");
		return (new VirionProjectFactory(new VirionManifestReader(new YamlReader())))->open($path);
	}

	private function packaged(VirionProject $project) : VirionProject
	{
		$path = $project->location . '.phar';
		$phar = new Phar($path);
		$phar->buildFromDirectory($project->packageRoot);
		$phar->setStub('<?php __HALT_COMPILER();');
		unset($phar);
		return (new VirionProjectFactory(new VirionManifestReader(new YamlReader())))->open($path);
	}

	/** @return array<string, list<array{string, VirionProject}>> */
	private function catalog(VirionProject ...$projects) : array
	{
		$catalog = [];
		foreach ($projects as $project) {
			$catalog[VirionName::key($project->manifest->name)][] = [$project->location, $project];
		}
		return $catalog;
	}
}
