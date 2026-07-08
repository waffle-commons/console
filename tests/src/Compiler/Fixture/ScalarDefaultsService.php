<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Compiler\Fixture;

/**
 * Inlinable service whose constructor declares every default-value KIND the
 * compiler must export to a PHP literal: a null default, bool, int, float, string
 * and array. Drives each arm of `ContainerCompiler::exportValue()` so the emitted
 * source round-trips the exact default values Autowire would have used.
 */
final class ScalarDefaultsService
{
    /** @var array<string, int> */
    public array $options;

    public function __construct(
        public LeafService $leaf,
        public ?int $maybe = null,
        public bool $flag = true,
        public int $count = 3,
        public float $ratio = 1.5,
        public string $label = 'scalars',
        array $options = ['a' => 1, 'b' => 2],
    ) {
        $this->options = $options;
    }
}
