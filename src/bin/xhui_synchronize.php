#!/usr/bin/php
<?php

if ($argc !== 4) {
    echo "php xhui_synchronize.php <directory> <endpoint> <apikey>\n";
    exit(1);
}

list ($_, $directory, $endpoint, $apiKey) = $argv;

if (!is_dir($directory)) {
    echo "Not a valid directory.\n";
    exit(2);
}

if (strpos($endpoint, 'http') === false) {
    echo "Not a valid endpoint url.\n";
    exit(3);
}

$profiles = glob($directory . "/*.json.gz");

foreach ($profiles as $profile) {
    $data = file_get_contents($profile);
    $result = httppost($endpoint, $apiKey, $data);
    @unlink($profile);
}
echo count($profiles);

function httppost($url, $apiKey, $data)
{
    $opts = array(
        'http' => array(
            'method'  => 'POST',
            'header'  => "Content-type: gzip\nAuthorization: Basic " . base64_encode("api:" . $apiKey),
            'content' => $data
        )
    );

    $context  = stream_context_create($opts);
    $result = @file_get_contents($url, false, $context);

    return $result ? true : false;
}
