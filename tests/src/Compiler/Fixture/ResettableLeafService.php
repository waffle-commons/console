<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Compiler\Fixture;

use Waffle\Commons\Contracts\Service\ResettableInterface;

/** Inlinable service that participates in the per-request reset cascade. */
final class ResettableLeafService implements ResettableInterface
{
    public int $touches = 0;

    public function touch(): void
    {
        $this->touches++;
    }

    #[\Override]
    public function reset(): void
    {
        $this->touches = 0;
    }
}
