<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Kegagalan dari provider AI, dengan dua pesan berbeda untuk dua pembaca.
 *
 * `getMessage()` sudah diterjemahkan untuk peserta - tidak menyebut provider,
 * endpoint, maupun isi galat aslinya. `detail()` memuat status dan potongan
 * badan galat untuk admin, yang justru membutuhkannya saat mendiagnosis.
 *
 * Sebelumnya keduanya satu pesan, dan akibatnya admin yang menguji koneksi
 * hanya melihat "Hubungi admin" - kalimat yang ditujukan untuk peserta, dan
 * yang tidak memberi tahu apa pun kepada orang yang justru sedang mencari
 * sebabnya.
 */
class AiProviderException extends RuntimeException
{
    public function __construct(
        string $pesanUntukPeserta,
        private readonly int $statusProvider = 0,
        private readonly ?string $detailProvider = null,
    ) {
        parent::__construct($pesanUntukPeserta, $statusProvider);
    }

    public function status(): int
    {
        return $this->statusProvider;
    }

    /**
     * Rincian untuk admin. Kuncinya disamarkan lebih dulu: badan galat provider
     * sering meng-echo kunci yang dikirim, dan pesan diagnostik tidak boleh jadi
     * cara membocorkannya kembali ke layar.
     */
    public function detail(?string $apiKey = null): ?string
    {
        if ($this->detailProvider === null) {
            return null;
        }

        $detail = $this->detailProvider;

        if ($apiKey) {
            $detail = str_replace($apiKey, '[API KEY DISENSOR]', $detail);
        }

        // Pola kunci umum, untuk yang tidak sama persis dengan kunci tersimpan.
        return preg_replace('/\b(sk|pk|api)[-_][A-Za-z0-9_-]{12,}/', '[KUNCI DISENSOR]', $detail);
    }
}
