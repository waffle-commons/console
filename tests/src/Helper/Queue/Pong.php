<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Helper\Queue;

use Waffle\Commons\Contracts\Queue\MessageInterface;

/** A message no test handler handles. */
final readonly class Pong implements MessageInterface {}
