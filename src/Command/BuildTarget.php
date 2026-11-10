<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Command;

enum BuildTarget : string
{
	case PLUGIN = 'plugin';
	case VIRION = 'virion';
	case BOTH = 'both';
}
