<?php

declare(strict_types=1);

namespace ChatParts\Model;

enum MessageStatus: string
{
    case Submitted = 'submitted';
    case Streaming = 'streaming';
    case Done = 'done';
    case Stopped = 'stopped';
    case Error = 'error';
}
