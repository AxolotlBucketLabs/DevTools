<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Validation;

final class ValidationResult
{
	/** @var list<ValidationIssue> */
	private array $issues = [];

	public function add(ValidationIssue $issue) : void
	{
		$this->issues[] = $issue;
	}

	public function error(string $code, string $message, ?string $path = null, ?string $suggestion = null) : void
	{
		$this->add(new ValidationIssue(Severity::ERROR, $code, $message, $path, $suggestion));
	}

	public function warning(string $code, string $message, ?string $path = null, ?string $suggestion = null) : void
	{
		$this->add(new ValidationIssue(Severity::WARNING, $code, $message, $path, $suggestion));
	}

	public function notice(string $code, string $message, ?string $path = null, ?string $suggestion = null) : void
	{
		$this->add(new ValidationIssue(Severity::NOTICE, $code, $message, $path, $suggestion));
	}

	/** @return list<ValidationIssue> */
	public function issues() : array
	{
		return $this->issues;
	}

	public function hasErrors() : bool
	{
		foreach ($this->issues as $issue) {
			if ($issue->severity === Severity::ERROR) {
				return true;
			}
		}

		return false;
	}

	public function merge(self $other) : void
	{
		foreach ($other->issues() as $issue) {
			$this->add($issue);
		}
	}
}
