<?php

// An isolated HTTPS origin with a fresh, untrusted certificate for browser TLS tests.
$key = openssl_pkey_new(['private_key_bits' => 2048]);
$csr = openssl_csr_new(['commonName' => 'localhost'], $key);
$certificate = openssl_csr_sign($csr, null, $key, 1);
openssl_x509_export($certificate, $pem);
openssl_pkey_export($key, $private);
file_put_contents($argv[1] . '/server.pem', $pem . $private);
$context = stream_context_create(['ssl' => ['local_cert' => $argv[1] . '/server.pem']]);
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, context: $context);
if ($listener === false) throw new RuntimeException($error);
echo 'https://' . stream_socket_get_name($listener, false) . "\n";
flush();
while ($socket = stream_socket_accept($listener, -1)) {
    stream_set_timeout($socket, 2);
    if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
        while (($line = fgets($socket)) !== false && trim($line) !== '') {}
        $body = '<!doctype html><title>Trusted by capability</title><p id="ready">HTTPS loaded</p>';
        @fwrite($socket, "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nContent-Length: " . strlen($body)
            . "\r\nConnection: close\r\n\r\n" . $body);
    }
    fclose($socket);
}
