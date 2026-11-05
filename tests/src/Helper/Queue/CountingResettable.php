<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Helper\Queue;

use Waffle\Commons\Contracts\Service\ResettableInterface;

/** Stands for the application container: counts the resets. */
final class CountingResettable implements ResettableInterface
{
    public private(set) int $resets = 0;

    #[\Override]
    public function reset(): void
    {
        $this->resets++;
    }
}
