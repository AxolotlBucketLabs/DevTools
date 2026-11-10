<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Tests\Virion;

use AxolotlBucketLabs\DevTools\Support\PhpSourceInspector;
use AxolotlBucketLabs\DevTools\Support\YamlReader;
use AxolotlBucketLabs\DevTools\Tests\TestCase;
use AxolotlBucketLabs\DevTools\Virion\VirionClassScanner;
use AxolotlBucketLabs\DevTools\Virion\VirionException;
use AxolotlBucketLabs\DevTools\Virion\VirionGraphPreflight;
use AxolotlBucketLabs\DevTools\Virion\VirionManifestReader;
use AxolotlBucketLabs\DevTools\Virion\VirionName;
use AxolotlBucketLabs\DevTools\Virion\VirionProject;
use AxolotlBucketLabs\DevTools\Virion\VirionProjectFactory;
use AxolotlBucketLabs\DevTools\Virion\VirionRegistryEntry;
use AxolotlBucketLabs\DevTools\Virion\VirionSourceSymbols;
use AxolotlBucketLabs\DevTools\Virion\VirionStatus;
use function array_fill_keys;
use function array_keys;
use function array_map;
use function array_merge;
use function file_put_contents;
use function json_encode;
use function str_replace;
use const JSON_THROW_ON_ERROR;

final class VirionGraphPreflightTest extends TestCase
{
	public function test_shared_dependencies_and_self_cycles_are_inspected_once_across_calls() : void
	{
		$projects = $this->selected(
			$this->project('A', ['B', 'C']),
			$this->project('B', ['D']),
			$this->project('C', ['D']),
			$this->project('D'),
			$this->project('Self', ['Self']),
		);
		$inspected = [];
		$preflight = new VirionGraphPreflight(function (VirionProject $project) use (&$inspected) : VirionSourceSymbols {
			$inspected[] = $project->manifest->name;

			return $this->inspect($project);
		});

		$first = $preflight->check($projects, [], []);
		$second = $preflight->check($projects, [], []);

		self::assertSame(['D', 'B', 'C', 'A', 'Self'], $inspected);
		self::assertSame(array_fill_keys(array_keys($projects), true), $first->readyProjects);
		self::assertSame($first->readyProjects, $second->readyProjects);
		self::assertSame([], $first->failures);
		self::assertSame([], $second->failures);
	}

	public function test_missing_selected_dependency_is_cached_without_inspecting_the_consumer() : void
	{
		$a = $this->project('A', ['Missing']);
		$inspected = [];
		$preflight = new VirionGraphPreflight(function (VirionProject $project) use (&$inspected) : VirionSourceSymbols {
			$inspected[] = $project->manifest->name;

			return $this->inspect($project);
		});
		$first = $preflight->check($this->selected($a), [], []);
		$second = $preflight->check($this->selected($a, $this->project('Missing')), $first->failures, []);

		self::assertSame([], $first->readyProjects);
		self::assertSame([['A', 'Required virion Missing was not selected for A.']], $this->diagnostics($first->failures));
		self::assertSame([VirionName::key('Missing') => true], $second->readyProjects);
		self::assertSame([], $second->failures);
		self::assertSame(['Missing'], $inspected);
	}

	public function test_inspection_failure_propagates_to_ready_cycle_members_and_their_consumers() : void
	{
		$a = $this->project('A', ['B']);
		$b = $this->project('B', ['A']);
		$c = $this->project('C', ['B']);
		file_put_contents($a->sourceRoot . '/Greeting.php', '<?php namespace GraphPreflight\\A;');
		$preflight = new VirionGraphPreflight($this->inspect(...));
		$result = $preflight->check($this->selected($a, $b, $c), [], []);

		$failure = "Cannot load A: no PHP classes were found in {$a->sourceRoot}.";
		$dependencyFailure = "Cannot load B: dependency A failed. {$failure}";
		self::assertSame([], $result->readyProjects);
		self::assertSame([
			['A', $failure],
			['B', $dependencyFailure],
			['C', "Cannot load C: dependency B failed. {$dependencyFailure}"],
		], $this->diagnostics($result->failures));
		self::assertSame([], $preflight->check($this->selected($a, $b, $c), $result->failures, [])->failures);
	}

	public function test_pending_failures_use_first_canonical_diagnostics_without_mutating_input_entries() : void
	{
		$a = $this->project('A', ['B']);
		$b = $this->project('B', ['C']);
		$c = $this->project('C');
		file_put_contents($c->sourceRoot . '/Greeting.php', '<?php namespace GraphPreflight\\C;');
		$first = new VirionRegistryEntry(str_replace('\\', '/', $c->location), $c->manifest, VirionStatus::FAILED, 'First diagnostic.');
		$later = new VirionRegistryEntry($c->location, $c->manifest, VirionStatus::FAILED, 'Later diagnostic.');
		$entries = [$first, $later];
		$result = (new VirionGraphPreflight($this->inspect(...)))->check($this->selected($a, $b, $c), $entries, []);

		self::assertSame([$first, $later], $entries);
		self::assertSame([
			['C', "Cannot load C: no PHP classes were found in {$c->sourceRoot}."],
			['B', 'Cannot load B: dependency C failed. First diagnostic.'],
			['A', 'Cannot load A: dependency B failed. Cannot load B: dependency C failed. First diagnostic.'],
		], $this->diagnostics($result->failures));
	}

	public function test_cycle_conflict_with_selected_project_leaves_that_project_ready() : void
	{
		$external = $this->project('External', [], 'GraphPreflight\\Shared');
		$a = $this->project('A', ['B'], 'GraphPreflight\\Shared\\Nested');
		$b = $this->project('B', ['A']);
		$result = (new VirionGraphPreflight($this->inspect(...)))->check($this->selected($external, $a, $b), [], []);

		$conflict = 'dependency cycle with External has a antigen GraphPreflight\\Shared\\Nested conflicts with GraphPreflight\\Shared.';
		self::assertSame([VirionName::key('External') => true], $result->readyProjects);
		self::assertSame([
			['B', 'Cannot load B: ' . $conflict],
			['A', 'Cannot load A: ' . $conflict],
		], $this->diagnostics($result->failures));
	}

	public function test_self_cycle_conflict_uses_loaded_registry_snapshot() : void
	{
		$loaded = $this->project('Loaded', [], 'GraphPreflight\\Loaded');
		$self = $this->project('Self', ['Self'], 'GraphPreflight\\Loaded\\Nested');
		$symbols = $this->inspect($loaded);
		$entry = new VirionRegistryEntry($loaded->location, $loaded->manifest, VirionStatus::LOADED, 'Already loaded.', $symbols->classes);
		$result = (new VirionGraphPreflight($this->inspect(...)))->check($this->selected($self), [$entry], [$entry]);

		self::assertSame([], $result->readyProjects);
		self::assertSame([
			['Self', 'Cannot load Self: dependency cycle with Loaded has a antigen GraphPreflight\\Loaded\\Nested conflicts with GraphPreflight\\Loaded.'],
		], $this->diagnostics($result->failures));
	}

	/** @dataProvider symbolConflictProvider */
	public function test_conflict_decisions_preserve_inspected_symbol_case_rules(string $kind, string $left, string $right, ?string $conflict) : void
	{
		$a = $this->project('A', ['B']);
		$b = $this->project('B', ['A']);
		$preflight = new VirionGraphPreflight(function (VirionProject $project) use ($kind, $left, $right) : VirionSourceSymbols {
			$symbols = $this->inspect($project);
			$extra = [$project->manifest->name === 'A' ? $left : $right];

			return new VirionSourceSymbols(
				$kind === 'class' ? array_merge($symbols->classes, $extra) : $symbols->classes,
				$kind === 'function' || $kind === 'guarded' ? $extra : [],
				$kind === 'constant' ? $extra : [],
				$kind === 'function' ? $extra : [],
			);
		});
		$projects = $this->selected($a, $b);
		$result = $preflight->check($projects, [], []);

		if ($conflict === null) {
			self::assertSame(array_fill_keys(array_keys($projects), true), $result->readyProjects);
			self::assertSame([], $result->failures);

			return;
		}

		self::assertSame([], $result->readyProjects);
		self::assertSame([
			['B', "Cannot load B: dependency cycle with A has a {$conflict}."],
			['A', "Cannot load A: dependency cycle with A has a {$conflict}."],
		], $this->diagnostics($result->failures));
	}

	/** @return iterable<string, array{string, string, string, ?string}> */
	public static function symbolConflictProvider() : iterable
	{
		yield 'classes ignore case' => ['class', 'Shared\\Value', 'shared\\value', 'class shared\\value conflicts with Shared\\Value'];
		yield 'unconditional functions ignore case' => ['function', 'Shared\\helper', 'shared\\HELPER', 'function shared\\HELPER conflicts with Shared\\helper'];
		yield 'identical constants conflict' => ['constant', 'Shared\\TOKEN', 'Shared\\TOKEN', 'constant Shared\\TOKEN conflicts with Shared\\TOKEN'];
		yield 'constant case remains distinct' => ['constant', 'Shared\\TOKEN', 'Shared\\token', null];
		yield 'guarded functions are permitted' => ['guarded', 'Shared\\helper', 'shared\\HELPER', null];
	}

	/** @param list<string> $dependencies */
	private function project(string $name, array $dependencies = [], ?string $antigen = null) : VirionProject
	{
		$location = $this->copyFixture('SharedVirion', $name);
		$antigen ??= 'GraphPreflight\\' . $name;
		$this->filesystem->removeTree($location . '/src');
		$source = $location . '/src/' . str_replace('\\', '/', $antigen);
		$this->filesystem->ensureDirectory($source);
		file_put_contents($source . '/Greeting.php', "<?php\nnamespace {$antigen}; final class Greeting {}\n");
		file_put_contents($location . '/virion.yml', "name: {$name}\nversion: 1.0.0\nantigen: {$antigen}\napi: 5.0.0\nvirions: " . json_encode($dependencies, JSON_THROW_ON_ERROR) . "\n");

		return (new VirionProjectFactory(new VirionManifestReader(new YamlReader())))->open($location);
	}

	private function inspect(VirionProject $project) : VirionSourceSymbols
	{
		$symbols = (new VirionClassScanner(new PhpSourceInspector()))->scanSymbols($project);
		if ($symbols->classes === []) {
			throw new VirionException("Cannot load {$project->manifest->name}: no PHP classes were found in {$project->sourceRoot}.");
		}

		return $symbols;
	}

	/** @return array<string, array{string, VirionProject}> */
	private function selected(VirionProject ...$projects) : array
	{
		$selected = [];
		foreach ($projects as $project) {
			$selected[VirionName::key($project->manifest->name)] = [$project->location, $project];
		}

		return $selected;
	}

	/**
	 * @param list<VirionRegistryEntry> $entries
	 * @return list<array{string, string}>
	 */
	private function diagnostics(array $entries) : array
	{
		return array_map(static fn(VirionRegistryEntry $entry) : array => [$entry->requireManifest()->name, $entry->message], $entries);
	}
}
