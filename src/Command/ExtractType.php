<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Command;

enum ExtractType : string
{
	case AUTO = 'auto';
	case PLUGIN = 'plugin';
	case VIRION = 'virion';
}
