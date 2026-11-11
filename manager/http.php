<?php
/** Gedeelde HTTP-GET helper met 429-retry (Jolpica/OpenF1 rate-limiten agressief
 *  en deze server deelt één IP voor alle spelers — zonder retry krijgt iedereen
 *  af en toe een lege respons als er kort na elkaar meerdere calls gebeuren). */
function f1hub_http_get($url, $attempt = 0) {
    $context = stream_context_create(['http' => [
        'timeout' => 8,
        'header' => "User-Agent: Formule1Hub\r\n",
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) return null;

    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int)$m[1]; break; }
    }
    if ($status === 429 && $attempt < 3) {
        usleep((300000) * ($attempt + 1)); // 0.3s, 0.6s, 0.9s
        return f1hub_http_get($url, $attempt + 1);
    }
    if ($status >= 400) return null;

    return $raw;
}
