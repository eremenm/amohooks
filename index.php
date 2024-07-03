<?php
header('Content-Type: application/json');

require_once 'vendor/autoload.php';

$ctrl = new \App\AmoCRMController();
$result = $ctrl->hookHandler();

echo json_encode($result, JSON_UNESCAPED_UNICODE);
