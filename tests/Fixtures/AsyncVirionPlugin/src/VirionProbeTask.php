<?php

declare(strict_types=1);

namespace Fixture\AsyncProbe;

use pocketmine\scheduler\AsyncTask;
use Shared\Virion\Greeting;
use function file_put_contents;

final class VirionProbeTask extends AsyncTask
{
	public function __construct(private readonly string $outputPath) {}

	public function onRun() : void
	{
		file_put_contents($this->outputPath, Greeting::message());
	}
}
