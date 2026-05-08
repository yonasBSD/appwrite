<?php

namespace Appwrite\Platform\Modules\Storage\Config;

use Appwrite\Utopia\Database\Documents\User;
use Utopia\Database\Document;
use Utopia\Http\Route;

final class CacheControl
{
    public const SOURCE_ACTION = 'action';
    public const SOURCE_CACHE = 'cache';

    public function __construct(
        public readonly string $source,
        public readonly Document $project,
        public readonly User $user,
        public readonly Document $bucket,
        public readonly Document $file,
        public readonly Document $resourceToken,
        public readonly int $maxAge,
        public readonly bool $isImageTransformation,
        public readonly ?bool $fileSecurity = null,
        public readonly ?Document $cacheLog = null,
        public readonly ?Route $route = null,
    ) {
    }
}
