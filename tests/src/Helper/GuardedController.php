<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Helper;

use Waffle\Commons\Contracts\Security\Attribute\Voter;
use Waffle\Commons\Contracts\Security\Csrf\Attribute\RequiresCsrfToken;

#[Voter(AllowAllVoter::class)]
final class GuardedController
{
    public function read(): void {}

    #[RequiresCsrfToken(id: 'form:save')]
    public function save(): void {}

    #[Voter(AllowAllVoter::class)]
    public function delete(): void {}
}
