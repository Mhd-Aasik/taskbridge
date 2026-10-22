<?php

// Test-only, loopback-only sink. No outbound socket, AUTH or relay implementation.
$directory = $argv[1];
$server = stream_socket_server('tcp://127.0.0.1:0', $number, $error);
if (! $server) {
    exit(1);
}
file_put_contents($directory.'/ready', substr(strrchr(stream_socket_get_name($server, false), ':'), 1));
$counter = 0;
while ($client = stream_socket_accept($server, 120)) {
    stream_set_timeout($client, 120);
    fwrite($client, "220 TaskBridge test capture\r\n");
    $allowed = false;
    while (($line = fgets($client)) !== false) {
        $command = strtoupper(strtok(trim($line), ' '));
        if ($command === 'EHLO' || $command === 'HELO') {
            fwrite($client, "250 localhost\r\n");
        } elseif ($command === 'MAIL' || $command === 'RCPT') {
            $valid = preg_match('/<[^<>\s@]+@[^<>\s@]+\.test>/i', $line) === 1;
            if ($command === 'RCPT') {
                $allowed = $valid;
            }
            fwrite($client, $valid ? "250 Accepted\r\n" : "550 Reserved .test recipients/senders only\r\n");
        } elseif ($command === 'DATA' && $allowed) {
            fwrite($client, "354 End with dot\r\n");
            $message = '';
            while (($part = fgets($client)) !== false && $part !== ".\r\n") {
                $message .= str_starts_with($part, '..') ? substr($part, 1) : $part;
                if (strlen($message) > 1024 * 1024) {
                    exit(1);
                }
            }
            file_put_contents($directory.'/message-'.(++$counter).'.eml', $message);
            fwrite($client, "250 Captured locally\r\n");
        } elseif ($command === 'QUIT') {
            fwrite($client, "221 Bye\r\n");
            break;
        } elseif ($command === 'RSET' || $command === 'NOOP') {
            fwrite($client, "250 OK\r\n");
        } else {
            fwrite($client, "502 Unsupported\r\n");
        }
    }
    fclose($client);
}
