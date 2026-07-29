<?php

namespace App\Support;

class RichTextSanitizer
{
    private const ALLOWED_TAGS = '<p><br><strong><b><em><i><u><ol><ul><li><sup><sub><span>';

    public static function sanitize(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $html = trim($html);
        if ($html === '') {
            return null;
        }

        if (! str_contains($html, '<')) {
            return nl2br(e($html), false);
        }

        $html = preg_replace('/<(script|style|iframe|object|embed|link|meta)[^>]*>.*?<\/\1>/is', '', $html) ?? '';
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

            return $safe ? ' style="' . e($safe) . '"' : '';
        }, $html) ?? '';

        return trim($html) ?: null;
    }
}
