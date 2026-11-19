<?php

namespace Kanboard\Plugin\AgentChat\Model;

use RuntimeException;

/** The asker, or the whole instance, already has as many answers running as allowed; the message is a translated notice. */
final class Busy extends RuntimeException
{
}
