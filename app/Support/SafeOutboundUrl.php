<?php

namespace App\Support;

/**
 * Memastikan URL yang akan dipanggil server bukan alamat internal.
 *
 * Endpoint provider AI bisa diatur admin dan dipanggil oleh server, bukan oleh
 * browser. Itu jalur Server-Side Request Forgery yang klasik: URL yang menunjuk
 * ke dalam jaringan membuat server ini jadi perantara untuk membaca hal yang
 * seharusnya tidak bisa dijangkau dari luar. Yang paling berbahaya:
 *
 *   - 169.254.169.254 - endpoint metadata di AWS, GCP, Azure, DigitalOcean.
 *     Sering menyajikan kredensial instans tanpa autentikasi apa pun.
 *   - 127.0.0.1 / localhost - MySQL, Redis, panel admin yang hanya mengikat
 *     antarmuka lokal karena dianggap tidak terjangkau dari luar.
 *   - 10/8, 172.16/12, 192.168/16 - jaringan internal.
 *
 * Pemeriksaan dilakukan **dua kali**: saat admin menyimpan, dan sekali lagi
 * tepat sebelum permintaan dikirim. Yang kedua bukan pengulangan yang sia-sia -
 * nama host bisa menunjuk alamat berbeda di dua waktu (DNS rebinding), sehingga
 * pemeriksaan saat menyimpan saja bisa dilewati.
 */
class SafeOutboundUrl
{
    /**
     * @return string|null null kalau aman, atau alasan penolakan
     */
    public static function reject(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return 'Endpoint belum diisi.';
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            return 'Endpoint bukan URL yang valid.';
        }

        // Hanya https. Selain menghindari kredensial melintas sebagai teks
        // biasa, ini juga menutup skema seperti file:// dan gopher:// yang
        // pernah dipakai untuk menyalahgunakan klien HTTP.
        if (($parts['scheme'] ?? '') !== 'https') {
            return 'Endpoint harus memakai https.';
        }

        $host = $parts['host'];

        // Nama host lokal tidak perlu diresolusi untuk ditolak.
        if (in_array(mb_strtolower($host), ['localhost', 'localhost.localdomain', '[::1]', '::1'], true)) {
            return 'Endpoint tidak boleh menunjuk ke server ini sendiri.';
        }

        // .localhost, .local, .internal, dan .home.arpa memang ditujukan untuk
        // nama internal.
        foreach (['.localhost', '.local', '.internal', '.home.arpa'] as $suffix) {
            if (str_ends_with(mb_strtolower($host), $suffix)) {
                return 'Endpoint tidak boleh memakai nama domain internal.';
            }
        }

        $addresses = self::resolve($host);

        if ($addresses === []) {
            return 'Nama host endpoint tidak bisa diresolusi.';
        }

        foreach ($addresses as $ip) {
            if (! self::isPublic($ip)) {
                return "Endpoint mengarah ke alamat internal ({$ip}).";
            }
        }

        return null;
    }

    /** @return array<string> */
    private static function resolve(string $host): array
    {
        // Host yang sudah berupa alamat IP tidak perlu diresolusi.
        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP)) {
            return [$bare];
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if (! $records) {
            // Sebagian lingkungan membatasi dns_get_record; gethostbyname hanya
            // mengembalikan IPv4 tapi lebih baik daripada tidak memeriksa.
            $ipv4 = @gethostbyname($host);

            return $ipv4 && $ipv4 !== $host ? [$ipv4] : [];
        }

        $out = [];
        foreach ($records as $record) {
            $out[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }

        return array_values(array_filter($out));
    }

    /**
     * FILTER_FLAG_NO_PRIV_RANGE dan NO_RES_RANGE sekaligus menolak loopback,
     * rentang privat, link-local (termasuk 169.254.0.0/16), dan blok yang
     * dicadangkan - untuk IPv4 maupun IPv6.
     */
    private static function isPublic(string $ip): bool
    {
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );
    }
}
