<?php

namespace Kanboard\Plugin\AgentChat\Model;

use RuntimeException;

/** The asker has used up their questions for the hour; the message is a translated notice. */
final class RateLimited extends RuntimeException
{
}
