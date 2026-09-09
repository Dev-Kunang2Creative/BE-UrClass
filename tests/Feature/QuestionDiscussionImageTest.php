<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Subtest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QuestionDiscussionImageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Subtest $subtest;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->subtest = Subtest::create([
            'name' => 'Tes Gambar Pembahasan',
            'category' => 'TPS',
            'max_questions' => 10,
        ]);
    }

    public function test_admin_can_upload_replace_and_delete_a_discussion_image(): void
    {
        $response = $this->actingAs($this->admin)->post(
            "/api/admin/subtests/{$this->subtest->id}/questions",
            $this->questionPayload([
                'discussion_image' => UploadedFile::fake()->image('pembahasan.jpg'),
            ]),
        );

        $response->assertCreated()->assertJsonPath('data.discussion_image_url', fn ($url) => str_contains($url, '/storage/discussion-images/'));

        $question = Question::query()->firstOrFail();
        $originalPath = $question->discussion_image;
        Storage::disk('public')->assertExists($originalPath);

        $response = $this->actingAs($this->admin)->post(
            "/api/admin/subtests/{$this->subtest->id}/questions/{$question->id}",
            $this->questionPayload([
                '_method' => 'PUT',
                'discussion_image' => UploadedFile::fake()->image('pengganti.webp'),
            ]),
        );

        $response->assertOk();
        $question->refresh();
        Storage::disk('public')->assertMissing($originalPath);
        Storage::disk('public')->assertExists($question->discussion_image);

        $replacementPath = $question->discussion_image;
        $this->actingAs($this->admin)->delete(
            "/api/admin/subtests/{$this->subtest->id}/questions/{$question->id}",
        )->assertOk();

        Storage::disk('public')->assertMissing($replacementPath);
    }

    public function test_discussion_image_rejects_unsupported_formats_and_files_over_two_megabytes(): void
    {
        $this->actingAs($this->admin)->post(
            "/api/admin/subtests/{$this->subtest->id}/questions",
            $this->questionPayload([
                'discussion_image' => UploadedFile::fake()->image('animasi.gif'),
            ]),
        )->assertUnprocessable()->assertJsonValidationErrors('discussion_image');

        $this->actingAs($this->admin)->post(
            "/api/admin/subtests/{$this->subtest->id}/questions",
            $this->questionPayload([
                'discussion_image' => UploadedFile::fake()->image('besar.png')->size(2049),
            ]),
        )->assertUnprocessable()->assertJsonValidationErrors('discussion_image');
    }

    private function questionPayload(array $overrides = []): array
    {
        return array_merge([
            'question_type' => 'multiple_choice',
            'question_text' => '<p>Berapa hasil 1 + 1?</p>',
            'discussion' => '<p>Hasilnya adalah dua.</p>',
            'correct_answer' => 'A',
            'order_no' => 1,
            'is_active' => true,
            'options' => [
                ['option_key' => 'A', 'option_text' => '2'],
                ['option_key' => 'B', 'option_text' => '3'],
            ],
        ], $overrides);
    }
}
