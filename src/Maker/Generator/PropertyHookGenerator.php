<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Maker\Generator;

/**
 * PropertyHookGenerator translates CLI fields definition (e.g., email:string, age:int)
 * into perfectly valid PHP 8.5 class members, constructors, and set hooks validation rules.
 */
final readonly class PropertyHookGenerator
{
    /**
     * Translates a list of CLI field strings into class structure arrays.
     *
     * @param list<string> $fields Raw strings e.g. ["email:string", "age:int"]
     * @return array{properties: string, constructorParams: string, assignments: string}
     */
    public function generate(array $fields): array
    {
        $properties = [];
        $constructorParams = [];
        $assignments = [];

        foreach ($fields as $field) {
            $parts = explode(':', $field, 2);
            $name = trim($parts[0]);
            $type = count($parts) > 1 ? trim($parts[1]) : 'mixed';

            if ($name === '') {
                continue;
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
                                if (trim(\$value) === '') {
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
