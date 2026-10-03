<?php

return [
    'server_key' => env('MIDTRANS_SERVER_KEY'),
    'client_key' => env('MIDTRANS_CLIENT_KEY'),
    'is_production' => env('MIDTRANS_IS_PRODUCTION', false),
    'is_sanitized' => env('MIDTRANS_IS_SANITIZED', true),
    'is_3ds' => env('MIDTRANS_IS_3DS', true),

    /*
     * Metode pembayaran yang ditawarkan Snap, dipisah koma.
     *
     * Kosong berarti "ikuti dashboard Midtrans" - Snap menampilkan apa pun yang
     * aktif di akun merchant. Itu bawaannya, dan untuk kebanyakan keadaan itu
     * yang benar: mengaktifkan atau menonaktifkan metode tidak perlu deploy.
     *
     * Diisi berarti mengunci daftarnya dari sisi kita, apa pun yang aktif di
     * dashboard. Berguna kalau hanya satu metode yang boleh dipakai dan kamu
     * tidak ingin metode lain tiba-tiba muncul karena ada yang mengaktifkannya.
     *
     * Kode QRIS di Snap adalah `other_qris`. Jadi untuk QRIS saja:
     *   MIDTRANS_ENABLED_PAYMENTS=other_qris
     */
    'enabled_payments' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MIDTRANS_ENABLED_PAYMENTS', '')),
    ))),
];