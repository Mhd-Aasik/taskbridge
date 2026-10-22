<?php

namespace Tests\Support;

use RuntimeException;

final class CapturedAccountEmail
{
    /** Decode body parts only: decoding MIME headers can corrupt random boundary values. */
    public static function parse(string $mime): array
    {
        [$headers,$body] = preg_split('/\r?\n\r?\n/', $mime, 2);
        $headers = preg_replace('/\r?\n[ \t]+/', ' ', $headers);
        if (preg_match('/\bboundary\s*=\s*(?:"([^"]+)"|([^\s;]+))/i', $headers, $boundary) !== 1) {
            throw new RuntimeException('Multipart capture boundary missing.');
        }
        $result = ['headers' => $headers, 'plain' => '', 'html' => ''];
        foreach (explode('--'.($boundary[1] ?: $boundary[2]), $body) as $part) {
            $pair = preg_split('/\r?\n\r?\n/', ltrim($part, "\r\n"), 2);
            if (count($pair) !== 2) {
                continue;
            }
            [$partHeaders,$contents] = $pair;
            if (stripos($partHeaders, 'Content-Transfer-Encoding: quoted-printable') !== false) {
                $contents = quoted_printable_decode($contents);
            } elseif (stripos($partHeaders, 'Content-Transfer-Encoding: base64') !== false) {
                $contents = base64_decode($contents, true);
            }
            if (stripos($partHeaders, 'Content-Type: text/plain') !== false) {
                $result['plain'] = $contents;
            } elseif (stripos($partHeaders, 'Content-Type: text/html') !== false) {
                $result['html'] = $contents;
            }
        }

        return $result;
    }
}
