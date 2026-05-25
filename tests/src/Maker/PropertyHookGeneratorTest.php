<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Maker;

use PHPUnit\Framework\TestCase;
use Waffle\Commons\Console\Maker\Generator\PropertyHookGenerator;

final class PropertyHookGeneratorTest extends TestCase
{
    public function testGenerateTranslatesTypesCorrectly(): void
    {
        $generator = new PropertyHookGenerator();
        $generated = $generator->generate([
            'email:string',
            'age:int',
            'active:bool',
            'name:string',
            ':string',
            'title:mixed',
        ]);

        static::assertStringContainsString('public string $email {', $generated['properties']);
        static::assertStringContainsString('filter_var($value, FILTER_VALIDATE_EMAIL)', $generated['properties']);
        static::assertStringContainsString('throw new ValidationException', $generated['properties']);

        static::assertStringContainsString('public int $age {', $generated['properties']);
        static::assertStringContainsString('if ($value < 0)', $generated['properties']);

        static::assertStringContainsString('public string $name {', $generated['properties']);
        static::assertStringContainsString('if (trim($value) === \'\')', $generated['properties']);

        static::assertStringContainsString('public bool $active;', $generated['properties']);
        static::assertStringContainsString('public mixed $title;', $generated['properties']);

        static::assertSame(
            'string $email, int $age, bool $active, string $name, mixed $title',
            $generated['constructorParams'],
        );

        static::assertStringContainsString('$this->email = $email;', $generated['assignments']);
        static::assertStringContainsString('$this->age = $age;', $generated['assignments']);
        static::assertStringContainsString('$this->active = $active;', $generated['assignments']);
        static::assertStringContainsString('$this->name = $name;', $generated['assignments']);
        static::assertStringContainsString('$this->title = $title;', $generated['assignments']);
    }
}
