<?php

namespace App\Console\Commands;

use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Console\Command;

class CleanupDoubleEncodedEntities extends Command
{
    protected $signature = 'questions:cleanup-double-encoded-entities {--dry-run : Preview tanpa menyimpan perubahan}';

    protected $description = 'Membersihkan entitas HTML yang ter-double encode (&amp;lt;, &amp;gt;, dll.) pada soal, pembahasan, dan opsi jawaban';

    private const REPLACEMENTS = [
        '&amp;lt;'   => '&lt;',
        '&amp;gt;'   => '&gt;',
        '&amp;quot;' => '&quot;',
        '&amp;#039;' => '&#039;',
    ];

    public function handle(): int
    {
        $isDry = (bool) $this->option('dry-run');

        if ($isDry) {
            $this->warn('[DRY RUN] Mode pratinjau aktif. Tidak ada perubahan yang disimpan ke database.');
        }

        $searchKeys = array_keys(self::REPLACEMENTS);
        $replaceValues = array_values(self::REPLACEMENTS);

        // 1. Bersihkan tabel questions
        $questionsUpdated = 0;
        Question::query()
            ->where(function ($q) use ($searchKeys) {
                foreach ($searchKeys as $key) {
                    $q->orWhere('question_text', 'like', "%{$key}%")
                      ->orWhere('discussion', 'like', "%{$key}%");
                }
            })
            ->chunkById(100, function ($questions) use ($searchKeys, $replaceValues, $isDry, &$questionsUpdated) {
                foreach ($questions as $question) {
                    $newQuestionText = str_replace($searchKeys, $replaceValues, $question->question_text ?? '');
                    $newDiscussion   = str_replace($searchKeys, $replaceValues, $question->discussion ?? '');

                    $changed = ($newQuestionText !== ($question->question_text ?? ''))
                            || ($newDiscussion !== ($question->discussion ?? ''));

                    if ($changed) {
                        $questionsUpdated++;
                        if (! $isDry) {
                            $question->update([
                                'question_text' => $newQuestionText,
                                'discussion'   => $newDiscussion,
                            ]);
                        }
                    }
                }
            });

        // 2. Bersihkan tabel question_options
        $optionsUpdated = 0;
        QuestionOption::query()
            ->where(function ($q) use ($searchKeys) {
                foreach ($searchKeys as $key) {
                    $q->orWhere('option_text', 'like', "%{$key}%");
                }
            })
            ->chunkById(100, function ($options) use ($searchKeys, $replaceValues, $isDry, &$optionsUpdated) {
                foreach ($options as $option) {
                    $newText = str_replace($searchKeys, $replaceValues, $option->option_text ?? '');
                    if ($newText !== ($option->option_text ?? '')) {
                        $optionsUpdated++;
                        if (! $isDry) {
                            $option->update(['option_text' => $newText]);
                        }
                    }
                }
            });

        $this->info("Selesai. Soal terpengaruh: {$questionsUpdated}, Opsi jawaban terpengaruh: {$optionsUpdated}.");

        return self::SUCCESS;
    }
}
