<?php
require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use ERC\WebSocket\Database;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$db = new Database();
$clients = $db->getClients();

header('Content-Type: application/json');
echo json_encode($clients);
