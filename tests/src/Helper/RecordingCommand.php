<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Helper;

use Waffle\Commons\Contracts\Console\CommandInterface;
use Waffle\Commons\Contracts\Console\InputInterface;
use Waffle\Commons\Contracts\Console\OutputInterface;

/** A command that records whether it ran, and exits with the code it was given. */
final class RecordingCommand implements CommandInterface
{
    public private(set) bool $invoked = false;

    public function __construct(
        private readonly string $name,
        private readonly string $description = '',
        private readonly int $exit = 0,
    ) {}

    #[\Override]
    public function getName(): string
    {
        return $this->name;
    }

    #[\Override]
    public function getDescription(): string
    {
        return $this->description;
    }

    #[\Override]
    public function getHelp(): string
    {
        return '';
    }

    #[\Override]
    public function getSynopsis(): string
    {
        return $this->name;
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->invoked = true;

        return $this->exit;
    }
}
