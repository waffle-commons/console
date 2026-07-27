<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Maker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waffle\Commons\Console\Exception\InvalidArgumentException;
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
        static::assertStringContainsString('if (mb_trim($value) === \'\')', $generated['properties']);

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

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileFieldProvider(): array
    {
        return [
            'breaks out via closing brace' => ['name:string} public function pwn() { system($_GET[0]); } //'],
            'semicolon injection in type' => ['name:string; system($_GET[0]); //'],
            'quote breakout in name' => ['na"me:string'],
            'space in type' => ['name:int extends Evil'],
            'parens in type' => ['name:string()'],
        ];
    }

    #[DataProvider('hostileFieldProvider')]
    public function testGenerateRejectsHostileFieldDefinitions(string $hostileField): void
    {
        $generator = new PropertyHookGenerator();

        $this->expectException(InvalidArgumentException::class);
        $generator->generate([$hostileField]);
    }

    public function testGenerateAcceptsUnionAndFqcnTypeHints(): void
    {
        $generator = new PropertyHookGenerator();
        $generated = $generator->generate([
            'status:int|string',
            'owner:\\App\\Entity\\User',
            'flag:?bool',
        ]);

        static::assertStringContainsString('public int|string $status;', $generated['properties']);
        static::assertStringContainsString('public \\App\\Entity\\User $owner;', $generated['properties']);
        static::assertStringContainsString('public ?bool $flag;', $generated['properties']);
    }
}
