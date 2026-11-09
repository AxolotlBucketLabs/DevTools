<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Validation;

enum Severity : string
{
	case ERROR = 'error';
	case WARNING = 'warning';
	case NOTICE = 'notice';
}
