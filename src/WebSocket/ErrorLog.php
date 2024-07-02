<?php

namespace ERC\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Database;
use Dotenv\Dotenv;

class ErrorLog implements MessageComponentInterface {
    protected $clients;
    private $db;
    private $adminEmail;
    private $adminDeviceId;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->db = new Database();
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
        $dotenv->load();
        $this->adminEmail = $_ENV['ADMIN_EMAIL'];
        $this->adminDeviceId = $_ENV['DEVICE_ID'];
    }

    public function onOpen(ConnectionInterface $conn) {

        $querystring = $conn->httpRequest->getUri()->getQuery();
        parse_str($querystring, $query);
         if (isset($query['deviceId'])) {
            $deviceId = $query['deviceId'];
            $email = $query['email'] ?? null;

        if ($email) {
                // Email provided and existing email is empty, update email
                $this->db->upsertClient($deviceId, 'Loggedin', $email);
                echo "Device {$deviceId} logged in with updated email: $email\n";
            } else {
                // Email not provided or existing email is not empty, do not update email
                $this->db->upsertClient($deviceId, 'Online');
                echo "Device {$deviceId} connected with no or unchanged email\n";
            }
            $conn->deviceId = $deviceId;
            $this->clients->attach($conn);
            echo "New connection! ({$conn->resourceId}) - Device ID: $deviceId\n";

        }else{
            $conn->close();
        }
    }
    public function onClose(ConnectionInterface $conn) {
        if (isset($conn->deviceId)) {
            $this->db->upsertClient($conn->deviceId, 'Offline');
            $this->clients->detach($conn);
            echo "Connection {$conn->resourceId} with Device ID {$conn->deviceId} has disconnected\n";
        }
    }
    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }
    public function onMessage(ConnectionInterface $from, $msg) {
    $data = json_decode($msg, true);
    if ($data) {
        if ($from->deviceId === $this->adminDeviceId) {
            if ($data['sentTo'] === $this->adminDeviceId) {
                $data['sentBy'] = $this->adminDeviceId;
                $data['message'] = "You cannot command yourself";
                foreach ($this->clients as $client) {
                    if ($client->deviceId === $this->adminDeviceId) {
                        $client->send(json_encode($data));
                        echo "Admin sent command to Self";
                        return;
                    }
                }
            } else {
                // Admin sent a message to another client
                $targetDevice = $data['sentTo'];
                $data['sentBy'] = $this->adminDeviceId;
                foreach ($this->clients as $client) {
                    if ($client->deviceId === $targetDevice) {
                        $client->send(json_encode($data));
                        echo "Admin sent command to Client: " . $targetDevice;
                        return;
                    }
                }
            }
        } else {
            // A client sent a message to the admin
            $targetDevice = $this->adminDeviceId;
            foreach ($this->clients as $client) {
                if ($client->deviceId === $targetDevice) {
                    $client->send(json_encode($data));
                    echo "Client: " . $from->deviceId . " sent message to Admin";
                    return;
                }
            }
        }
    }
    }


  }