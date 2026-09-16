<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Subtest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleanupDoubleEncodedEntitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_command_fixes_double_encoded_entities(): void
    {
        $subtest = Subtest::create([
            'name' => 'Subtest TIU',
            'category' => 'TIU',
            'max_questions' => 10,
        ]);

        $question = Question::create([
            'subtest_id' => $subtest->id,
            'question_type' => 'multiple_choice',
            'question_text' => 'Kondisi jika x &amp;lt; y',
            'discussion' => 'Karena A &amp;gt; B',
            'order_no' => 1,
            'is_active' => true,
        ]);

        $optionA = QuestionOption::create([
            'question_id' => $question->id,
            'option_key' => 'A',
            'option_text' => '2A &amp;lt; 3B',
            'is_correct' => false,
        ]);

        $optionB = QuestionOption::create([
            'question_id' => $question->id,
            'option_key' => 'B',
            'option_text' => 'A &amp;gt; B',
            'is_correct' => true,
        ]);

        // 1. Dry run should not persist changes
        $this->artisan('questions:cleanup-double-encoded-entities --dry-run')
            ->assertSuccessful();

        $this->assertSame('Kondisi jika x &amp;lt; y', $question->fresh()->question_text);
        $this->assertSame('2A &amp;lt; 3B', $optionA->fresh()->option_text);

        // 2. Real run should clean up entities
        $this->artisan('questions:cleanup-double-encoded-entities')
            ->assertSuccessful();

        $this->assertSame('Kondisi jika x &lt; y', $question->fresh()->question_text);
        $this->assertSame('Karena A &gt; B', $question->fresh()->discussion);
        $this->assertSame('2A &lt; 3B', $optionA->fresh()->option_text);
        $this->assertSame('A &gt; B', $optionB->fresh()->option_text);
    }
}
