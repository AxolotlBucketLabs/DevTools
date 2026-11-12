<?php

declare(strict_types=1);

namespace RuntimeSmoke\{{PLUGIN}};

use pocketmine\scheduler\AsyncTask;
use RuntimeSmoke\Library\Value;

final class AsyncProbe extends AsyncTask
{
    public function onRun(): void
    {
        Value::$counter = 0;
        $this->setResult(Value::version() . ':' . ++Value::$counter);
    }

    public function onCompletion(): void
    {
        Main::completed($this->getResult());
    }
}
