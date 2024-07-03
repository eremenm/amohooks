<?php
header('Content-Type: application/json');

require_once 'vendor/autoload.php';

$ctrl = new \App\AmoCRMController();
$result = $ctrl->hookHandler();

if (!empty($_POST)) {
    file_put_contents(
        __DIR__.'/../hooks.log',
        json_encode($_POST,JSON_UNESCAPED_UNICODE).PHP_EOL,
        FILE_APPEND
    );
    file_put_contents(
        __DIR__.'/../hooks.log',
        json_encode($result,JSON_UNESCAPED_UNICODE).PHP_EOL,
        FILE_APPEND
    );
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
