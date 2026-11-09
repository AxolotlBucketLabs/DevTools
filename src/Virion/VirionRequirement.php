<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Virion;

use function preg_match;
use function trim;

final class VirionRequirement
{
	public readonly string $name;

	public readonly VirionVersionConstraint $constraint;

	public function __construct(string $name, string $constraintExpression = '*', public readonly string $source = 'unknown', public readonly ?string $composerPackageSha256 = null)
	{
		$name = trim($name);
		if (preg_match('/^[A-Za-z0-9_.-]+$/D', $name) !== 1) {
			throw new VirionException(
				"Invalid virion requirement name \"{$name}\" in {$source}. Use letters, numbers, dot, underscore, or hyphen.",
			);
		}
		$this->name = $name;
		$this->constraint = new VirionVersionConstraint(trim($constraintExpression));
	}

	public function matches(string $version) : bool
	{
		return $this->constraint->matches($version);
	}

	public function describe() : string
	{
		return "{$this->name} {$this->constraint->expression} ({$this->source})";
	}
}
