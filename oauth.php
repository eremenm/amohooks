<?php

use Symfony\Component\Dotenv\Dotenv;

header('Content-Type: application/json');

require_once 'vendor/autoload.php';

$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/.env');

$clientId = $_ENV['CLIENT_ID'];
$clientSecret = $_ENV['CLIENT_SECRET'];
$redirectUri = $_ENV['CLIENT_REDIRECT_URI'];
$authCode = $_ENV['AUTH_CODE'];
$subdomain = $_ENV['SUBDOMAIN'];

$baseDomain = $subdomain . '.amocrm.ru';
$link = 'https://' . $baseDomain . '/oauth2/access_token';

$data = [
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'grant_type' => 'authorization_code',
    'code' => $authCode,
    'redirect_uri' => $redirectUri,
];

$curl = curl_init();
curl_setopt($curl,CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl,CURLOPT_USERAGENT,'amoCRM-oAuth-client/1.0');
curl_setopt($curl,CURLOPT_URL, $link);
curl_setopt($curl,CURLOPT_HTTPHEADER,['Content-Type:application/json']);
curl_setopt($curl,CURLOPT_HEADER, false);
curl_setopt($curl,CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($curl,CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($curl,CURLOPT_SSL_VERIFYPEER, 1);
curl_setopt($curl,CURLOPT_SSL_VERIFYHOST, 2);
$out = curl_exec($curl);
$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

$code = (int)$code;
$errors = [
    400 => 'Bad request',
    401 => 'Unauthorized',
    403 => 'Forbidden',
    404 => 'Not found',
    500 => 'Internal server error',
    502 => 'Bad gateway',
    503 => 'Service unavailable',
];

try {
    if ($code < 200 || $code > 204) {
        throw new Exception(isset($errors[$code]) ? $errors[$code] : 'Undefined error', $code);
    }
} catch(\Exception $e) {
    echo $out;
    die();
}

$accessToken = json_decode($out, true);

$data = [
    'accessToken' => $accessToken['access_token'],
    'expires' => $accessToken['expires_in'],
    'refreshToken' => $accessToken['refresh_token'],
    'baseDomain' => $baseDomain,
];

\App\AmoCRMTokenActions::saveToken($data);

echo $out;
