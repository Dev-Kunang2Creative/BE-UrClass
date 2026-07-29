<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Tryout {{ $tryout->title }}</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: #333;
        }
        .page-break {
            page-break-after: always;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #004AAB;
            padding-bottom: 10px;
        }
        .header h1 {
            color: #004AAB;
            margin: 0;
            font-size: 24px;
        }
        .subtest-title {
            background-color: #004AAB;
            color: #ffffff;
            padding: 8px 12px;
            font-size: 18px;
            font-weight: bold;
            margin-top: 30px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .question-block {
            margin-bottom: 40px;
            page-break-inside: avoid;
        }
        .question-text {
            margin-bottom: 10px;
        }
        .question-image {
            max-width: 100%;
            height: auto;
            margin-bottom: 10px;
        }
        table.options {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        table.options td {
            vertical-align: top;
            padding: 5px;
        }
        .option-key {
            font-weight: bold;
            width: 30px;
            color: #004AAB;
        }
        .correct-answer {
            background-color: #e6f4ea;
            border-left: 4px solid #34a853;
            padding: 8px;
            margin-top: 10px;
        }
        .correct-answer-title {
            font-weight: bold;
            color: #34a853;
            margin-bottom: 5px;
        }
        .discussion {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 15px;
            margin-top: 15px;
            border-radius: 4px;
            page-break-inside: avoid;
        }
        .discussion-title {
            font-weight: bold;
            color: #004AAB;
            margin-bottom: 10px;
        }
        /* Fix for rich text styles */
        p { margin: 0 0 10px 0; }
        img { max-width: 100%; height: auto; }
        ol, ul { padding-left: 20px; margin-top: 0; margin-bottom: 10px; }
        li { margin-bottom: 5px; }
        sub, sup { font-size: 75%; line-height: 0; position: relative; vertical-align: baseline; }
        sup { top: -0.5em; }
        sub { bottom: -0.25em; }
    </style>
</head>
<body>

<div class="header">
    <h1>PAKET TRYOUT: {{ mb_strtoupper($tryout->title) }}</h1>
</div>

@foreach($subtests as $subtestIndex => $subtest)
    <div class="subtest-title">
        Bagian {{ $subtestIndex + 1 }}: {{ $subtest['name'] }} ({{ $subtest['duration'] }} Menit)
    </div>

    @if(count($subtest['questions']) === 0)
        <p><i>Tidak ada soal di subtest ini.</i></p>
    @endif

    @foreach($subtest['questions'] as $qIndex => $question)
        <div class="question-block">
            <table width="100%">
                <tr>
                    <td width="30" valign="top"><strong>{{ $qIndex + 1 }}.</strong></td>
                    <td valign="top">
                        @if($question->question_image_url)
                            <div style="margin-bottom: 10px;">
                                <img src="{{ $question->question_image_url }}" class="question-image" alt="Soal">
                            </div>
                        @endif
                        <div class="question-text">
                            {!! $question->question_text !!}
                        </div>

                        @if($question->question_type === 'multiple_choice')
                            <table class="options">
                                @foreach($question->options as $optIndex => $option)
                                    <tr>
                                        <td class="option-key">{{ chr(65 + $optIndex) }}.</td>
                                        <td>{!! $option->option_text !!}</td>
                                    </tr>
                                @endforeach
                            </table>
                        @endif

                        <div class="correct-answer">
                            <div class="correct-answer-title">Kunci Jawaban:</div>
                            @if($question->question_type === 'multiple_choice')
                                {{ $question->correct_answer ?: 'Belum diatur' }}
                            @else
                                <i>Soal Essay (Jawaban otomatis benar)</i>
                            @endif
                        </div>

                        <div class="discussion">
                            <div class="discussion-title">Pembahasan:</div>
                            @if($question->discussion)
                                {!! $question->discussion !!}
                            @else
                                <p><i>Pembahasan belum tersedia untuk soal ini.</i></p>
                            @endif

                            @if($question->discussion_image_url)
                                <div style="margin-top: 15px;">
                                    <img src="{{ $question->discussion_image_url }}" class="question-image" alt="Pembahasan">
                                </div>
                            @endif
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    @endforeach

    @if(!$loop->last)
        <div class="page-break"></div>
    @endif
@endforeach

</body>
</html>
