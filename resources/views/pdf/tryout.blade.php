<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Tryout {{ $tryout->title }} - UrClass</title>
    <style>
        @page {
            margin-top: 18mm;
            margin-bottom: 16mm;
            margin-left: 14mm;
            margin-right: 14mm;
        }

        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.55;
            color: #1e293b;
        }

        /* Kop & Header */
        .kop-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        .kop-table td {
            vertical-align: middle;
            padding: 0;
        }
        .kop-logo {
            max-height: 38px;
            max-width: 120px;
        }
        .kop-title {
            font-size: 13pt;
            font-weight: bold;
            color: #004AAB;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .kop-subtitle {
            font-size: 8.5pt;
            color: #64748b;
            margin: 2px 0 0 0;
        }
        .kop-badge {
            display: inline-block;
            background-color: #f1f5f9;
            border: 1px solid #cbd5e1;
            color: #004AAB;
            font-size: 8pt;
            font-weight: bold;
            padding: 3px 8px;
            border-radius: 4px;
            text-transform: uppercase;
        }
        .divider-main {
            border: none;
            border-top: 2.5px solid #0f172a;
            margin: 8px 0 2px 0;
        }
        .divider-sub {
            border: none;
            border-top: 0.8px solid #004AAB;
            margin: 0 0 14px 0;
        }

        /* Info Card / Metadata */
        .meta-card {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 16px;
        }
        .meta-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
        }
        .meta-table td {
            padding: 3px 6px;
            vertical-align: top;
        }
        .meta-label {
            color: #64748b;
            font-weight: 500;
            width: 110px;
        }
        .meta-value {
            color: #0f172a;
            font-weight: bold;
        }

        /* Subtest Section Header */
        .subtest-header {
            background-color: #004AAB;
            color: #ffffff;
            padding: 8px 14px;
            border-radius: 5px;
            margin-top: 20px;
            margin-bottom: 16px;
            page-break-after: avoid;
        }
        .subtest-header-table {
            width: 100%;
            border-collapse: collapse;
        }
        .subtest-header-table td {
            padding: 0;
            vertical-align: middle;
        }
        .subtest-name {
            font-size: 11.5pt;
            font-weight: bold;
            letter-spacing: 0.3px;
        }
        .subtest-badge {
            font-size: 8.5pt;
            background-color: rgba(255, 255, 255, 0.2);
            padding: 3px 8px;
            border-radius: 3px;
            font-weight: normal;
        }

        /* Question Layout */
        .question-card {
            margin-bottom: 22px;
            page-break-inside: avoid;
            border-bottom: 1px dashed #e2e8f0;
            padding-bottom: 18px;
        }
        .question-table {
            width: 100%;
            border-collapse: collapse;
        }
        .question-table td {
            vertical-align: top;
            padding: 0;
        }
        .num-badge {
            display: inline-block;
            background-color: #004AAB;
            color: #ffffff;
            font-weight: bold;
            font-size: 9pt;
            width: 22px;
            height: 22px;
            line-height: 22px;
            text-align: center;
            border-radius: 50%;
            margin-right: 8px;
        }
        .question-content {
            font-size: 10pt;
            color: #1e293b;
            line-height: 1.55;
            margin-bottom: 10px;
        }
        .question-image-box {
            text-align: center;
            margin: 10px 0;
        }
        .question-image {
            max-width: 90%;
            max-height: 220px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 2px;
        }

        /* Options */
        .options-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            margin-bottom: 10px;
        }
        .options-table td {
            vertical-align: top;
            padding: 4px 6px;
            font-size: 9.5pt;
        }
        .opt-letter {
            font-weight: bold;
            color: #004AAB;
            width: 24px;
            text-align: center;
            background-color: #eff6ff;
            border-radius: 3px;
            border: 1px solid #bfdbfe;
            font-size: 9pt;
            padding: 2px 0;
        }
        .opt-text {
            color: #334155;
            padding-left: 8px !important;
        }

        /* Answer Key & Discussion */
        .answer-card {
            background-color: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-left: 3.5px solid #16a34a;
            border-radius: 4px;
            padding: 6px 10px;
            margin-top: 8px;
            font-size: 9pt;
            color: #166534;
        }
        .answer-card strong {
            color: #15803d;
            font-size: 9pt;
        }
        .discussion-card {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-left: 3.5px solid #004AAB;
            border-radius: 4px;
            padding: 8px 12px;
            margin-top: 8px;
            font-size: 9pt;
            color: #334155;
            page-break-inside: avoid;
        }
        .discussion-title {
            font-weight: bold;
            color: #004AAB;
            margin-bottom: 4px;
            font-size: 9pt;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        /* Running Header & Footer for mPDF */
        .pdf-header-table, .pdf-footer-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8pt;
            color: #94a3b8;
        }
        .pdf-header-table td {
            border-bottom: 0.5px solid #e2e8f0;
            padding-bottom: 4px;
        }
        .pdf-footer-table td {
            border-top: 0.5px solid #e2e8f0;
            padding-top: 4px;
        }

        .page-break {
            page-break-after: always;
        }

        /* Rich text formatting cleanup */
        p { margin: 0 0 6px 0; }
        ol, ul { padding-left: 18px; margin-top: 2px; margin-bottom: 6px; }
        li { margin-bottom: 3px; }
        sub, sup { font-size: 75%; line-height: 0; position: relative; vertical-align: baseline; }
        sup { top: -0.5em; }
        sub { bottom: -0.25em; }
    </style>
</head>
<body>

<!-- Header Halaman Berjalan -->
<htmlpageheader name="pageHeader">
    <table class="pdf-header-table">
        <tr>
            <td width="60%" align="left"><strong>UrClass</strong> • Naskah Soal & Pembahasan Tryout</td>
            <td width="40%" align="right">{{ $tryout->title }}</td>
        </tr>
    </table>
</htmlpageheader>

<!-- Footer Halaman Berjalan -->
<htmlpagefooter name="pageFooter">
    <table class="pdf-footer-table">
        <tr>
            <td width="40%" align="left">© {{ date('Y') }} UrClass Indonesia • Dokumen Resmi</td>
            <td width="20%" align="center">Halaman {PAGENO} / {nbpg}</td>
            <td width="40%" align="right">Dicetak: {{ now()->translatedFormat('d M Y, H:i') }} WIB</td>
        </tr>
    </table>
</htmlpagefooter>

<sethtmlpageheader name="pageHeader" value="on" show-this-page="0" />
<sethtmlpagefooter name="pageFooter" value="on" />

<!-- KOP UTAMA HALAMAN 1 -->
<table class="kop-table">
    <tr>
        <td width="60%">
            @if(!empty($logoBase64))
                <img src="{{ $logoBase64 }}" class="kop-logo" alt="UrClass Logo">
            @else
                <h1 class="kop-title">URCLASS</h1>
            @endif
            <div class="kop-subtitle">Platform Ujian & Bimbingan Akademik Digital Terintegrasi</div>
        </td>
        <td width="40%" align="right">
            <span class="kop-badge">{{ $tryout->category ? strtoupper($tryout->category) : 'TRYOUT AKADEMIK' }}</span>
            <div style="font-size: 8pt; color: #64748b; margin-top: 4px;">
                Kode: TR-{{ str_pad($tryout->id, 5, '0', STR_PAD_LEFT) }}
            </div>
        </td>
    </tr>
</table>

<hr class="divider-main">
<hr class="divider-sub">

<!-- INFORMASI DOKUMEN TRYOUT -->
<div class="meta-card">
    <table class="meta-table">
        <tr>
            <td class="meta-label">Nama Paket</td>
            <td width="10">:</td>
            <td class="meta-value">{{ $tryout->title }}</td>
            <td class="meta-label">Total Subtes</td>
            <td width="10">:</td>
            <td class="meta-value">{{ count($subtests) }} Subtes</td>
        </tr>
        <tr>
            <td class="meta-label">Kategori / Track</td>
            <td>:</td>
            <td class="meta-value">{{ $tryout->category ? strtoupper($tryout->category) : '-' }}</td>
            <td class="meta-label">Total Soal</td>
            <td>:</td>
            <td class="meta-value">{{ $subtests->sum(fn($s) => count($s['questions'])) }} Butir Soal</td>
        </tr>
        <tr>
            <td class="meta-label">Tipe Akses</td>
            <td>:</td>
            <td class="meta-value">{{ $tryout->is_free ? 'Tryout Terbuka (Gratis)' : 'Tryout Premium' }}</td>
            <td class="meta-label">Total Durasi</td>
            <td>:</td>
            <td class="meta-value">{{ $subtests->sum('duration') }} Menit</td>
        </tr>
    </table>
</div>

<!-- KONTEN SUBTES & SOAL -->
@foreach($subtests as $subtestIndex => $subtest)
    <div class="subtest-header">
        <table class="subtest-header-table">
            <tr>
                <td class="subtest-name">
                    BAGIAN {{ $subtestIndex + 1 }}: {{ mb_strtoupper($subtest['name']) }}
                </td>
                <td align="right">
                    <span class="subtest-badge">{{ $subtest['duration'] }} Menit &bull; {{ count($subtest['questions']) }} Soal</span>
                </td>
            </tr>
        </table>
    </div>

    @if(count($subtest['questions']) === 0)
        <div style="padding: 12px; text-align: center; color: #94a3b8; font-style: italic; font-size: 9.5pt;">
            Tidak ada butir soal pada subtes ini.
        </div>
    @endif

    @foreach($subtest['questions'] as $qIndex => $question)
        <div class="question-card">
            <table class="question-table">
                <tr>
                    <td width="28" valign="top">
                        <span class="num-badge">{{ $qIndex + 1 }}</span>
                    </td>
                    <td valign="top">
                        @if($question->question_image_url)
                            <div class="question-image-box">
                                <img src="{{ $question->question_image_url }}" class="question-image" alt="Ilustrasi Soal">
                            </div>
                        @endif

                        <div class="question-content">
                            {!! $question->question_text !!}
                        </div>

                        @if($question->question_type === 'multiple_choice' && count($question->options) > 0)
                            <table class="options-table">
                                @foreach($question->options as $optIndex => $option)
                                    <tr>
                                        <td width="24" class="opt-letter">{{ chr(65 + $optIndex) }}</td>
                                        <td class="opt-text">{!! $option->option_text !!}</td>
                                    </tr>
                                @endforeach
                            </table>
                        @endif

                        <div class="answer-card">
                            <strong>Kunci Jawaban:</strong>
                            @if($question->question_type === 'multiple_choice')
                                <span>Pilihan <strong>{{ $question->correct_answer ?: '-' }}</strong></span>
                            @else
                                <i>Soal Essay / Isian Singkat</i>
                            @endif
                        </div>

                        @if($question->discussion || $question->discussion_image_url)
                            <div class="discussion-card">
                                <div class="discussion-title">Pembahasan & Penjelasan:</div>
                                @if($question->discussion)
                                    <div>{!! $question->discussion !!}</div>
                                @endif

                                @if($question->discussion_image_url)
                                    <div class="question-image-box" style="margin-top: 6px;">
                                        <img src="{{ $question->discussion_image_url }}" class="question-image" alt="Ilustrasi Pembahasan">
                                    </div>
                                @endif
                            </div>
                        @endif
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
