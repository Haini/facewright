<?php
// Calls an ExApp route through AppAPI, exactly as the bridge does (PublicFunctions::exAppRequest).
// Runs inside the Nextcloud container:  php exapp_call.php ROUTE BODY_JSON_FILE [USER] [TIMEOUT]
// Prints "HTTP <status> <ms>" and the response body on the next line; "HTTP 0" for a transport failure.
define('OC_CONSOLE', 1);
require_once '/var/www/html/lib/base.php';

[$route, $bodyFile] = [$argv[1], $argv[2]];
$user = $argv[3] ?? 'admin';
$timeout = (int)($argv[4] ?? 120);
$body = json_decode(file_get_contents($bodyFile), true, 512, JSON_THROW_ON_ERROR);

$started = microtime(true);
$response = \OCP\Server::get(\OCA\AppAPI\PublicFunctions::class)
	->exAppRequest('facewright', $route, $user, 'POST', $body, ['timeout' => $timeout]);
$ms = (int)round((microtime(true) - $started) * 1000);

if (!$response instanceof \OCP\Http\Client\IResponse) {
	echo "HTTP 0 $ms\n" . json_encode($response) . "\n";
	exit;
}
echo 'HTTP ' . $response->getStatusCode() . " $ms\n" . $response->getBody() . "\n";
