<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Naskah Soal - {{ $subtest->name }} - UrClass</title>
    <style>
        @page {
            margin-top: 26mm;
            margin-bottom: 22mm;
            margin-left: 16mm;
            margin-right: 16mm;
            margin-header: 12mm;
            margin-footer: 12mm;
        }

        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 10.5pt;
            line-height: 1.5;
            color: #1e293b;
        }

        /* Kop & Header */
        .kop-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
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
            border-top: 2px solid #0f172a;
            margin: 8px 0 2px 0;
        }
        .divider-sub {
            border: none;
            border-top: 0.8px solid #004AAB;
            margin: 0 0 12px 0;
        }

        /* Info Card / Metadata */
        .meta-card {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 14px;
        }
        .meta-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.5pt;
        }
        .meta-table td {
            padding: 2px 4px;
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

        /* Petunjuk pengerjaan */
        .instructions-box {
            background-color: #fffbeb;
            border: 1px solid #fef3c7;
            border-left: 3.5px solid #d97706;
            border-radius: 4px;
            padding: 6px 10px;
            margin-bottom: 16px;
            font-size: 8.5pt;
            color: #92400e;
        }

        /* Daftar Soal */
        .question-item {
            margin-bottom: 20px;
            page-break-inside: avoid;
        }
        .q-header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }
        .q-header-table td {
            padding: 0;
            vertical-align: top;
        }
        .q-num-cell {
            width: 32px;
            font-weight: bold;
            font-size: 11pt;
            color: #004AAB;
        }
        .q-text-cell {
            font-size: 10.5pt;
            color: #0f172a;
            line-height: 1.55;
        }

        .question-image {
            max-width: 480px;
            max-height: 280px;
            margin: 8px 0 10px 0;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
        }

        /* Pilihan Opsi */
        .options-table {
            width: 100%;
            border-collapse: collapse;
            margin-left: 32px;
            margin-top: 4px;
        }
        .options-table td {
            padding: 3.5px 0;
            vertical-align: top;
        }
        .opt-key-cell {
            width: 26px;
            font-weight: bold;
            font-size: 10pt;
            color: #334155;
        }
        .opt-text-cell {
            font-size: 10pt;
            color: #334155;
            line-height: 1.45;
        }

        /* Running Header & Footer for mPDF */
        .pdf-header-table, .pdf-footer-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8pt;
            color: #94a3b8;
        }
        .pdf-header-table td {
            border-bottom: 0.5px solid #cbd5e1;
            padding-bottom: 6px;
        }
        .pdf-footer-table td {
            border-top: 0.5px solid #cbd5e1;
            padding-top: 6px;
        }

        p { margin: 0 0 4px 0; }
        ol, ul { padding-left: 18px; margin-top: 2px; margin-bottom: 4px; }
        li { margin-bottom: 2px; }
    </style>
</head>
<body>

    <!-- Header Berulang (mPDF) -->
    <htmlpageheader name="subtestHeader">
        <table class="pdf-header-table">
            <tr>
                <td style="text-align: left; font-weight: bold; color: #004AAB;">
                    UrClass — Naskah Bank Soal
                </td>
                <td style="text-align: right;">
                    {{ $subtest->name }} ({{ strtoupper($subtest->category) }})
                </td>
            </tr>
        </table>
    </htmlpageheader>

    <!-- Footer Berulang (mPDF) -->
    <htmlpagefooter name="subtestFooter">
        <table class="pdf-footer-table">
            <tr>
                <td style="text-align: left;">
                    Dokumen ini diunduh dari Platform UrClass (urclass.id)
                </td>
                <td style="text-align: right;">
                    Halaman {PAGENO} dari {nbpg}
                </td>
            </tr>
        </table>
    </htmlpagefooter>

    <sethtmlpageheader name="subtestHeader" value="on" show-this-page="0" />
    <sethtmlpagefooter name="subtestFooter" value="on" />

    <!-- Kop Halaman Pertama -->
    <table class="kop-table">
        <tr>
            <td style="width: 130px;">
                @if($logoBase64)
                    <img src="{{ $logoBase64 }}" class="kop-logo" alt="UrClass Logo">
                @else
                    <span style="font-size: 16pt; font-weight: bold; color: #004AAB;">UrClass</span>
                @endif
            </td>
            <td style="text-align: left;">
                <div class="kop-title">NASKAH LEMBAR SOAL</div>
                <div class="kop-subtitle">Platform Persiapan Ujian UTBK & CPNS — urclass.id</div>
            </td>
            <td style="text-align: right; width: 140px;">
                <span class="kop-badge">{{ strtoupper($subtest->exam_type) }} • {{ strtoupper($subtest->category) }}</span>
            </td>
        </tr>
    </table>

    <hr class="divider-main">
    <hr class="divider-sub">

    <!-- Metadata Bank Soal -->
    <div class="meta-card">
        <table class="meta-table">
            <tr>
                <td class="meta-label">Nama Bank Soal</td>
                <td style="width: 8px;">:</td>
                <td class="meta-value">{{ $subtest->name }}</td>
                <td class="meta-label" style="padding-left: 20px;">Total Butir Soal</td>
                <td style="width: 8px;">:</td>
                <td class="meta-value">{{ count($questions) }} Soal</td>
            </tr>
            <tr>
                <td class="meta-label">Kategori Subtes</td>
                <td>:</td>
                <td class="meta-value">{{ strtoupper($subtest->category) }} ({{ strtoupper($subtest->exam_type) }})</td>
                <td class="meta-label" style="padding-left: 20px;">Tanggal Terbit</td>
                <td>:</td>
                <td class="meta-value">{{ now()->translatedFormat('d F Y') }}</td>
            </tr>
        </table>
    </div>

    <div class="instructions-box">
        <strong>Petunjuk Pengerjaan:</strong> Pilihlah salah satu jawaban yang paling tepat (A, B, C, D, atau E) untuk setiap butir soal berikut ini.
    </div>

    <!-- Daftar Butir Soal -->
    @forelse($questions as $index => $q)
        <div class="question-item">
            <table class="q-header-table">
                <tr>
                    <td class="q-num-cell">{{ $index + 1 }}.</td>
                    <td class="q-text-cell">
                        {!! $q->question_text !!}

                        @if(!empty($q->question_image_base64))
                            <div>
                                <img src="{{ $q->question_image_base64 }}" class="question-image" alt="Gambar Soal">
                            </div>
                        @endif
                    </td>
                </tr>
            </table>

            @if($q->options && $q->options->count() > 0)
                <table class="options-table">
                    @foreach($q->options->sortBy('option_key') as $opt)
                        <tr>
                            <td class="opt-key-cell">{{ strtoupper($opt->option_key) }}.</td>
                            <td class="opt-text-cell">{!! $opt->option_text !!}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>
    @empty
        <div style="text-align: center; color: #94a3b8; padding: 40px 0;">
            Belum ada soal aktif di dalam bank soal ini.
        </div>
    @endforelse

</body>
</html>
