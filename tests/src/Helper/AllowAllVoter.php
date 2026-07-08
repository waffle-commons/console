<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Helper;

use Waffle\Commons\Contracts\Auth\SecurityContextInterface;
use Waffle\Commons\Contracts\Security\VoterInterface;

final class AllowAllVoter implements VoterInterface
{
    #[\Override]
    public function decide(SecurityContextInterface $ctx, mixed $subject = null): bool
    {
        return true;
    }
}
