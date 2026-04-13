<?php

namespace Appwrite\GraphQL;

use Swoole\Coroutine\Channel;

final class ResolverLock
{
    public Channel $channel;
    public ?int $owner = null;
    public int $depth = 0;

    public function __construct()
    {
        $this->channel = new Channel(1);
    }
}
