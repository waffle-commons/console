<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Helper\Queue;

use RuntimeException;
use Waffle\Commons\Contracts\Queue\Exception\QueueExceptionInterface;

/** A queue or dead-letter store that fails, as a transport outage would. */
final class QueueFailure extends RuntimeException implements QueueExceptionInterface {}
