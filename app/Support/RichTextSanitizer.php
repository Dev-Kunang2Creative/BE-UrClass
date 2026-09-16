<?php

namespace App\Support;

class RichTextSanitizer
{
    private const ALLOWED_TAGS = '<p><br><strong><b><em><i><u><ol><ul><li><sup><sub><span>';

    private const TAG_PATTERN = '/<\/?(p|br|strong|b|em|i|u|ol|ul|li|sup|sub|span)(\s[^>]*)?>/i';

    public static function sanitize(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $html = trim($html);
        if ($html === '') {
            return null;
        }

        // Un-double encode any accidental double-encoded HTML entities (e.g. &amp;lt; -> &lt;)
        $html = str_replace(
            ['&amp;lt;', '&amp;gt;', '&amp;quot;', '&amp;#039;'],
            ['&lt;', '&gt;', '&quot;', '&#039;'],
            $html
        );

        // Remove dangerous elements before checking tags
        $html = preg_replace('/<(script|style|iframe|object|embed|link|meta)[^>]*>.*?<\/\1>/is', '', $html) ?? '';
        $html = trim($html);
        if ($html === '') {
            return null;
        }

        // If the string contains no recognized HTML tags, treat as plain text:
        // escape characters safely (without double-encoding existing entities) and preserve newlines.
        if (! preg_match(self::TAG_PATTERN, $html)) {
            return nl2br(e($html, false), false);
        }

        $html = strip_tags($html, self::ALLOWED_TAGS);
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s+(href|src)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace_callback('/\sstyle\s*=\s*("([^"]*)"|\'([^\']*)\')/i', function (array $matches) {
            $style = $matches[2] ?: $matches[3];
            $safe = collect(explode(';', $style))
                ->map(fn (string $rule) => trim($rule))
                ->filter(function (string $rule) {
                    $property = strtolower(trim(strtok($rule, ':') ?: ''));
                    return in_array($property, ['font-weight', 'font-style', 'text-decoration'], true);
                })
                ->implode('; ');

            return $safe ? ' style="' . e($safe, false) . '"' : '';
        }, $html) ?? '';

        return trim($html) ?: null;
    }
}
