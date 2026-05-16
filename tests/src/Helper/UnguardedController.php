<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Helper;

/**
 * Intentionally has no `#[Voter]` — the audit command should flag this.
 */
final class UnguardedController
{
    public function unsafe(): void {}
}
