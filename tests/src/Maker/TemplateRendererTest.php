<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Console\Maker;

use PHPUnit\Framework\TestCase;
use Waffle\Commons\Console\Maker\TemplateRenderer;

final class TemplateRendererTest extends TestCase
{
    public function testRenderSingleLineReplacement(): void
    {
        $renderer = new TemplateRenderer();
        $template = 'class {{ CLASS_NAME }} {}';
        $rendered = $renderer->render($template, ['CLASS_NAME' => 'User']);

        static::assertSame('class User {}', $rendered);
    }

    public function testRenderIndentationPreservedForMultiline(): void
    {
        $renderer = new TemplateRenderer();
        $template = <<<TEMPLATE
            class User
            {
                {{ PROPERTIES }}
            }
            TEMPLATE;

        $properties = <<<PROPERTIES
            public string \$email;
            public int \$age;
            PROPERTIES;

        $rendered = $renderer->render($template, ['PROPERTIES' => $properties]);

        $expected = <<<EXPECTED
            class User
            {
                public string \$email;
                public int \$age;
            }
            EXPECTED;

        static::assertSame($expected, $rendered);
    }
}
