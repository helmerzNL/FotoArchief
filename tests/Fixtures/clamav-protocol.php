<?php

declare(strict_types=1);

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    exit(1);
}
echo stream_socket_get_name($server, false)."\n";
flush();
$client = stream_socket_accept($server, 10);
if ($client === false) {
    exit(2);
}
stream_set_timeout($client, 5);
$command = stream_get_line($client, 50, "\0");
if ($command !== 'zINSTREAM') {
    exit(3);
}
$data = '';
while (true) {
    $header = '';
    while (strlen($header) < 4) {
        $part = fread($client, 4 - strlen($header));
        if ($part === false || $part === '') {
            exit(4);
        }
        $header .= $part;
    }
    $length = unpack('Nlength', $header)['length'];
    if ($length === 0) {
        break;
    }
    $remaining = $length;
    while ($remaining > 0) {
        $part = fread($client, $remaining);
        if ($part === false || $part === '') {
            exit(5);
        }
        $data .= $part;
        $remaining -= strlen($part);
    }
}
echo hash('sha256', $data)."\n";
fwrite($client, ($argv[1] ?? 'stream: OK')."\0");
fclose($client);
fclose($server);
