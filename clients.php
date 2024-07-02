<?php
require __DIR__ . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

require 'database.php';

$db = new Database();
$clients = $db->getClients();

header('Content-Type: application/json');
echo json_encode($clients);