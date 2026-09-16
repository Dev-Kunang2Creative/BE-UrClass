<?php

namespace Tests\Unit;

use App\Support\RichTextSanitizer;
use Tests\TestCase;

class RichTextSanitizerTest extends TestCase
{
    public function test_plain_text_with_comparison_operators_is_not_double_encoded(): void
    {
        $input = '2A < 3B';
        $sanitized = RichTextSanitizer::sanitize($input);
        $this->assertSame('2A &lt; 3B', $sanitized);

        // Idempotency: sanitizing already escaped string does not produce &amp;lt;
        $this->assertSame('2A &lt; 3B', RichTextSanitizer::sanitize($sanitized));
    }

    public function test_plain_text_greater_than_is_not_double_encoded(): void
    {
        $input = 'A > B';
        $sanitized = RichTextSanitizer::sanitize($input);
        $this->assertSame('A &gt; B', $sanitized);

        $this->assertSame('A &gt; B', RichTextSanitizer::sanitize($sanitized));
    }

    public function test_normalizes_accidental_double_encoded_entities(): void
    {
        $this->assertSame('2A &lt; 3B', RichTextSanitizer::sanitize('2A &amp;lt; 3B'));
        $this->assertSame('A &gt; B', RichTextSanitizer::sanitize('A &amp;gt; B'));
    }

    public function test_preserves_allowed_rich_text_tags(): void
    {
        $input = '<p>Hasil dari <strong>2A &lt; 3B</strong> adalah benar.</p>';
        $sanitized = RichTextSanitizer::sanitize($input);
        $this->assertSame('<p>Hasil dari <strong>2A &lt; 3B</strong> adalah benar.</p>', $sanitized);
    }
}
