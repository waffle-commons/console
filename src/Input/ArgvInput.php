<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Input;

use Waffle\Commons\Contracts\Console\Constant;
use Waffle\Commons\Contracts\Console\InputInterface;

/**
 * Parses `$argv`-style command-line input into named arguments + options.
 *
 * Recognized shapes:
 *   - `--flag`                    → option "flag" = true
 *   - `--key=value`               → option "key"  = "value"
 *   - `-v`                        → option "v"    = true (short flag)
 *   - positional values           → arguments, bound by `bindArgumentNames()`.
 *
 * Argument-name binding is deferred so callers can construct an `ArgvInput`
 * with raw argv and then bind the command's expected argument names.
 */
final class ArgvInput implements InputInterface
{
    /** @var list<string> Tokens still treated as positional (in original order). */
    private array $positionals = [];

    /** @var array<string, string|bool> */
    private array $options = [];

    /** @var array<string, string> */
    private array $arguments = [];

    /** @var list<string> */
    private array $raw;

    /**
     * @param list<string> $argv Raw tokens, EXCLUDING the script name and the
     *                           command name (the application strips those before
     *                           passing the rest here).
     */
    public function __construct(array $argv)
    {
        $this->raw = array_values($argv);
        foreach ($this->raw as $token) {
            if (str_starts_with($token, Constant::OPTION_PREFIX_LONG)) {
                $body = substr($token, strlen(Constant::OPTION_PREFIX_LONG));
                $eq = strpos($body, '=');
                if ($eq === false) {
                    $this->options[$body] = true;
                } else {
                    $this->options[substr($body, 0, $eq)] = substr($body, $eq + 1);
                }
                continue;
            }
            if (str_starts_with($token, Constant::OPTION_PREFIX_SHORT) && strlen($token) > 1) {
                $this->options[substr($token, 1)] = true;
                continue;
            }
            $this->positionals[] = $token;
        }
    }

    /**
     * Binds positional values to the supplied argument names, in order.
     *
     * @param list<string> $names Argument names declared by the command.
     */
    public function bindArgumentNames(array $names): void
    {
        $this->arguments = [];
        foreach ($names as $index => $name) {
            if (!array_key_exists($index, $this->positionals)) {
                continue;
            }
            $this->arguments[$name] = $this->positionals[$index];
        }
    }

    #[\Override]
    public function getArgument(string $name, ?string $default = null): ?string
    {
        return $this->arguments[$name] ?? $default;
    }

    #[\Override]
    public function getOption(string $name, ?string $default = null): ?string
    {
        if (!array_key_exists($name, $this->options)) {
            return $default;
        }
        $value = $this->options[$name];
        return is_string($value) ? $value : $default;
    }

    #[\Override]
    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    #[\Override]
    public function getArguments(): array
    {
        return $this->arguments;
    }

    #[\Override]
    public function getOptions(): array
    {
        return $this->options;
    }

    #[\Override]
    public function getRawArguments(): array
    {
        return $this->raw;
    }
}
