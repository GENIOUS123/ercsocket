<?php
require __DIR__ . '/vendor/autoload.php';

use ERC\WebSocket\ErrorLog;
use ERC\WebSocket\Database;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

try {
    // Assuming Database.php is autoloaded or included manually
    $server = IoServer::factory(
        new HttpServer(
            new WsServer(
                new ErrorLog(new Database())
            )
        ),
        8087
    );

    echo "WebSocket server is running at port 8087";
    
    $server->run();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
