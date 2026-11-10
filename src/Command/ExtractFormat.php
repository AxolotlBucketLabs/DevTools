<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Command;

enum ExtractFormat : string
{
	case FOLDER = 'folder';
	case ZIP = 'zip';
}
