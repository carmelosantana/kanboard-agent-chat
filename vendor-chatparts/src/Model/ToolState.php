<?php

declare(strict_types=1);

namespace ChatParts\Model;

enum ToolState: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Error = 'error';
    case Denied = 'denied';
}
