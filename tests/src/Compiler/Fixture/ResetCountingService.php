<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Compiler\Fixture;

use Waffle\Commons\Contracts\Service\ResettableInterface;

/**
 * Concrete reset spy: counts how many times the per-request reset() cascade
 * touches the instance. Lets the parity tests assert a service resets EXACTLY
 * once per request — the SEC-04 residual (passthroughs memoised in both the
 * compiled memo and the runtime container) made passthroughs reset twice.
 */
final class ResetCountingService implements ResettableInterface
{
    public int $resets = 0;

    #[\Override]
    public function reset(): void
    {
        $this->resets++;
    }
}
