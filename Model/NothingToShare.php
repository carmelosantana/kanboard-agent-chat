<?php

namespace Kanboard\Plugin\AgentChat\Model;

use RuntimeException;

/** A drawer thread has no finished answer to share; the message is a translated notice. */
final class NothingToShare extends RuntimeException
{
}
