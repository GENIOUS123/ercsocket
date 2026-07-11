<?php

namespace ERC\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use ERC\WebSocket\Database;
use Dotenv\Dotenv;

class ErrorLog implements MessageComponentInterface {
    protected $clients;
    private $db;
    private $adminEmail;
    private $adminDeviceId;
    private $pendingRequests = [];
    private $pendingRequestsByDevice = [];

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->db = new Database();
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
        $dotenv->safeLoad();
        $this->adminEmail = getenv('ADMIN_EMAIL') ?: ($_ENV['ADMIN_EMAIL'] ?? 'itsupport@erceyecare.com');
        $this->adminDeviceId = getenv('DEVICE_ID') ?: ($_ENV['DEVICE_ID'] ?? 'asxc1234567ERC004145665551wesASDVB123f0');
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->checkAndUpdateDeviceStatus();

        $querystring = $conn->httpRequest->getUri()->getQuery();
        parse_str($querystring, $query);

        $role = ($query['role'] ?? null) === 'admin' ? 'admin' : 'device';
        $conn->role = $role;
        $conn->tabId = $query['tabId'] ?? uniqid('tab-', true);

        if ($role === 'admin') {
            $conn->deviceId = $query['deviceId'] ?? $this->adminDeviceId;
            $this->clients->attach($conn);
            echo "Admin connection opened for tab {$conn->tabId}\n";
            return;
        }

        if (!$this->isValidDeviceId($query['deviceId'] ?? null)) {
            $conn->close();
            return;
        }

        $deviceId = $query['deviceId'];
        $email = $query['email'] ?? null;

        if ($email) {
            $this->db->upsertClient($deviceId, 'Loggedin', $email);
            echo "Device {$deviceId} logged in with updated email: $email\n";
        } else {
            $this->db->upsertClient($deviceId, 'Online');
            echo "Device {$deviceId} connected with no or unchanged email\n";
        }

        $conn->deviceId = $deviceId;
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId}) - Device ID: $deviceId\n";
    }

    public function onClose(ConnectionInterface $conn) {
        $role = $conn->role ?? 'device';

        if ($role === 'admin') {
            $this->removePendingRequestsForTab($conn->tabId ?? null);
            $this->clients->detach($conn);
            echo "Admin connection {$conn->resourceId} has disconnected\n";
            return;
        }

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
        if (!is_array($data)) {
            return;
        }

        $role = $from->role ?? 'device';

        if ($role === 'admin') {
            $requestId = $data['requestId'] ?? uniqid('req-', true);
            $targetDevice = $data['sentTo'] ?? null;
            $tabId = $data['tabId'] ?? $from->tabId ?? null;

            $this->pendingRequests[$requestId] = [
                'tabId' => $tabId,
                'targetDevice' => $targetDevice,
                'requestId' => $requestId,
            ];

            if ($targetDevice !== null) {
                $this->pendingRequestsByDevice[$targetDevice] = $this->pendingRequests[$requestId];
            }

            $this->sendToAdminTab([
                'type' => 'request-status',
                'requestId' => $requestId,
                'status' => 'pending',
                'message' => 'Request sent to device',
                'targetDevice' => $targetDevice,
                'tabId' => $tabId,
            ], $tabId);

            if ($targetDevice === $this->adminDeviceId) {
                $this->sendToAdminTab([
                    'type' => 'request-status',
                    'requestId' => $requestId,
                    'status' => 'error',
                    'message' => 'You cannot command yourself',
                    'targetDevice' => $targetDevice,
                    'tabId' => $tabId,
                ], $tabId);
                return;
            }

            foreach ($this->clients as $client) {
                if (($client->role ?? 'device') !== 'device') {
                    continue;
                }

                if ($client->deviceId === $targetDevice) {
                    $payload = $data;
                    $payload['requestId'] = $requestId;
                    $payload['tabId'] = $tabId;
                    $payload['sentBy'] = $this->adminDeviceId;
                    $payload['replyToTabId'] = $tabId;
                    $client->send(json_encode($payload));
                    echo "Admin sent command to Client: " . $targetDevice;
                    return;
                }
            }

            $this->sendToAdminTab([
                'type' => 'request-status',
                'requestId' => $requestId,
                'status' => 'error',
                'message' => 'Device is not currently connected',
                'targetDevice' => $targetDevice,
                'tabId' => $tabId,
            ], $tabId);
            return;
        }

        $requestId = $data['requestId'] ?? null;
        $replyToTabId = null;

        if ($requestId && isset($this->pendingRequests[$requestId])) {
            $replyToTabId = $this->pendingRequests[$requestId]['tabId'] ?? null;
        } elseif (isset($this->pendingRequestsByDevice[$from->deviceId])) {
            $pending = $this->pendingRequestsByDevice[$from->deviceId];
            $replyToTabId = $pending['tabId'] ?? null;
            $requestId = $pending['requestId'] ?? $requestId;
        }

        $payload = $data;
        $payload['requestId'] = $requestId;
        $payload['replyToTabId'] = $replyToTabId;
        $payload['type'] = 'response';
        $payload['status'] = !empty($data['errorlog']) ? 'download-ready' : 'received';
        $payload['message'] = $payload['message'] ?? 'Received from device';

        $this->sendToAdminTab($payload, $replyToTabId);

        if ($requestId && isset($this->pendingRequests[$requestId])) {
            unset($this->pendingRequests[$requestId]);
        }
        if ($from->deviceId && isset($this->pendingRequestsByDevice[$from->deviceId])) {
            unset($this->pendingRequestsByDevice[$from->deviceId]);
        }
    }

    private function sendToAdminTab($payload, $tabId = null) {
        $json = json_encode($payload);
        foreach ($this->clients as $client) {
            if (($client->role ?? 'device') !== 'admin') {
                continue;
            }
            if ($tabId === null || ($client->tabId ?? null) === $tabId) {
                $client->send($json);
            }
        }
    }

    private function removePendingRequestsForTab($tabId) {
        if ($tabId === null) {
            return;
        }

        foreach ($this->pendingRequests as $requestId => $pending) {
            if (($pending['tabId'] ?? null) === $tabId) {
                unset($this->pendingRequests[$requestId]);
            }
        }

        foreach ($this->pendingRequestsByDevice as $deviceId => $pending) {
            if (($pending['tabId'] ?? null) === $tabId) {
                unset($this->pendingRequestsByDevice[$deviceId]);
            }
        }
    }

    private function checkAndUpdateDeviceStatus() {
        $allDevices = $this->db->getClients();
        foreach ($allDevices as $device) {
            $deviceId = $device['deviceId'];
            $isConnected = false;

            foreach ($this->clients as $client) {
                if (($client->role ?? 'device') === 'device' && $client->deviceId === $deviceId) {
                    $isConnected = true;
                    break;
                }
            }

            if (!$isConnected && ($device['status'] === 'Online' || $device['status'] === 'Loggedin')) {
                $this->db->upsertClient($deviceId, 'Offline');
                echo "Updated status for Device ID {$deviceId} to Offline\n";
            }
        }
    }

    private function isValidDeviceId($deviceId) {
        if ($deviceId === null) {
            return false;
        }

        $deviceId = trim((string) $deviceId);
        return $deviceId !== '' && !in_array(strtolower($deviceId), ['undefined', 'null', 'nan'], true);
    }
}
