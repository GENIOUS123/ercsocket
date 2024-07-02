<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/database.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();


use ERC\WebSocket\ErrorLog;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;


$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new ErrorLog()
        )
    ),
    8087
);

$server->run();