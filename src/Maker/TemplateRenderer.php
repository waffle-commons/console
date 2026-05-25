<?php

declare(strict_types=1);

namespace Waffle\Commons\Console\Maker;

/**
 * Stateless template rendering engine for Waffle Maker (RFC-020).
 * Implements the Indentation Preservation Algorithm to ensure zero-debt Mago code formatting.
 */
final readonly class TemplateRenderer
{
    /**
     * Renders a template content string by replacing token placehoders
     * and preserving surrounding code block indentations.
     *
     * @param string $template The raw template content with double braces (e.g. {{ KEY }}).
     * @param array<string, string> $variables Tokens to replace (keys must match the token name, e.g. ['CLASS_NAME' => 'User']).
     */
    public function render(string $template, array $variables): string
    {
        $lines = explode("\n", $template);
        $output = [];

        foreach ($lines as $line) {
            foreach ($variables as $key => $value) {
                $token = '{{ ' . $key . ' }}';

                if (str_contains($line, $token)) {
                    // Step 3: Extract leading indentation (spaces and tabs) of the current line
                    $matches = [];
                    preg_match('/^([ \t]*)/', $line, $matches);
                    $indent = $matches[1] ?? '';

                    // Step 4: Propagate indentation to every subsequent line if the replacement is multiline
                    $valStr = (string) $value;
                    if (str_contains($valStr, "\n")) {
                        $replacementLines = explode("\n", $valStr);
                        $firstLine = array_shift($replacementLines);
                        $indentedLines = [$firstLine];

                        foreach ($replacementLines as $replLine) {
                            $indentedLines[] = $indent . $replLine;
                        }

                        $injectedValue = implode("\n", $indentedLines);
                    } else {
                        $injectedValue = $valStr;
                    }

                    $line = str_replace($token, $injectedValue, $line);
                }
            }
            $output[] = $line;
        }

        return implode("\n", $output);
    }
}
