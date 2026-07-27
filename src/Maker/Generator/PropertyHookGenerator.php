<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Maker\Generator;

use Waffle\Commons\Console\Exception\InvalidArgumentException;

/**
 * PropertyHookGenerator translates CLI fields definition (e.g., email:string, age:int)
 * into perfectly valid PHP 8.5 class members, constructors, and set hooks validation rules.
 */
final readonly class PropertyHookGenerator
{
    /** A bare PHP identifier: letter/underscore, then letters/digits/underscores. */
    private const string NAME_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    /**
     * A type-hint grammar covering builtins, FQCNs, nullable (`?`), union (`|`),
     * and intersection (`&`) types — e.g. `string`, `?int`, `\App\Foo`, `int|string`.
     */
    private const string TYPE_PATTERN = '/^\??[a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*(?:[|&]\??[a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*)*$/';

    /**
     * Translates a list of CLI field strings into class structure arrays.
     *
     * `$name`/`$type` are validated against a strict grammar before any
     * interpolation into generated PHP source — they originate as raw CLI
     * tokens and are otherwise a codegen-injection vector (SEC-...).
     *
     * @param list<string> $fields Raw strings e.g. ["email:string", "age:int"]
     * @return array{properties: string, constructorParams: string, assignments: string}
     * @throws InvalidArgumentException When a field name or type fails the grammar check.
     */
    public function generate(array $fields): array
    {
        $properties = [];
        $constructorParams = [];
        $assignments = [];

        foreach ($fields as $field) {
            $parts = explode(':', $field, 2);
            $name = mb_trim($parts[0]);
            $type = count($parts) > 1 ? mb_trim($parts[1]) : 'mixed';

            if ($name === '') {
                continue;
            }

            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw new InvalidArgumentException(
                    sprintf('Invalid field name "%s": must be a valid PHP identifier.', $name),
                    argumentName: $name,
                );
            }

            if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
                throw new InvalidArgumentException(
                    sprintf('Invalid type hint "%s" for field "%s".', $type, $name),
                    argumentName: $name,
                );
            }

            // Define property with or without set hook
            if ($type === 'string' && $name === 'email') {
                $properties[] = <<<PHP
                        public string \$email {
                            set(string \$value) {
                                if (!filter_var(\$value, FILTER_VALIDATE_EMAIL)) {
                                    throw new ValidationException(message: sprintf('Invalid email address format: "%s".', \$value), field: 'email');
                                }
                                \$this->email = strtolower(\$value);
                            }
                        }
                    PHP;
            } elseif ($type === 'string') {
                $properties[] = <<<PHP
                        public string \${$name} {
                            set(string \$value) {
                                if (mb_trim(\$value) === '') {
                                    throw new ValidationException(message: 'The field {$name} cannot be empty.', field: '{$name}');
                                }
                                \$this->{$name} = \$value;
                            }
                        }
                    PHP;
            } elseif ($type === 'int') {
                $properties[] = <<<PHP
                        public int \${$name} {
                            set(int \$value) {
                                if (\$value < 0) {
                                    throw new ValidationException(message: 'The value of field {$name} must be a positive integer.', field: '{$name}');
                                }
                                \$this->{$name} = \$value;
                            }
                        }
                    PHP;
            } else {
                // Other types are declared as simple public backed properties
                $properties[] = "    public {$type} \${$name};";
            }

            $constructorParams[] = "{$type} \${$name}";
            $assignments[] = "        \$this->{$name} = \${$name};";
        }

        return [
            'properties' => implode("\n\n", $properties),
            'constructorParams' => implode(', ', $constructorParams),
            'assignments' => implode("\n", $assignments),
        ];
    }
}
