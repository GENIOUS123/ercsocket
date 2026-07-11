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
        $this->log('socket', 'ErrorLog WebSocket handler initialized');
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->checkAndUpdateDeviceStatus();

        $querystring = $conn->httpRequest->getUri()->getQuery();
        parse_str($querystring, $query);

        $role = ($query['role'] ?? null) === 'admin' ? 'admin' : 'device';
        $conn->role = $role;
        $conn->tabId = $query['tabId'] ?? uniqid('tab-', true);
        $this->log('socket', 'Connection opening', [
            'resourceId' => $conn->resourceId ?? null,
            'role' => $role,
            'tabId' => $conn->tabId,
            'deviceId' => $query['deviceId'] ?? null,
            'hasEmail' => isset($query['email']) && trim((string) $query['email']) !== '',
        ]);

        if ($role === 'admin') {
            $conn->deviceId = $query['deviceId'] ?? $this->adminDeviceId;
            $this->clients->attach($conn);
            $this->log('socket', 'Admin connection opened', [
                'resourceId' => $conn->resourceId ?? null,
                'tabId' => $conn->tabId,
                'deviceId' => $conn->deviceId,
            ]);
            echo "Admin connection opened for tab {$conn->tabId}\n";
            return;
        }

        if (!$this->isValidDeviceId($query['deviceId'] ?? null)) {
            $this->log('error', 'Rejected connection with invalid deviceId', [
                'resourceId' => $conn->resourceId ?? null,
                'deviceId' => $query['deviceId'] ?? null,
            ]);
            $conn->close();
            return;
        }

        $deviceId = $query['deviceId'];
        $email = $query['email'] ?? null;

        if ($email) {
            $this->db->upsertClient($deviceId, 'Loggedin', $email);
            $this->log('socket', 'Device logged in', ['deviceId' => $deviceId, 'email' => $email]);
            echo "Device {$deviceId} logged in with updated email: $email\n";
        } else {
            $this->db->upsertClient($deviceId, 'Online');
            $this->log('socket', 'Device connected', ['deviceId' => $deviceId]);
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
            $this->log('socket', 'Admin connection closed', [
                'resourceId' => $conn->resourceId ?? null,
                'tabId' => $conn->tabId ?? null,
            ]);
            echo "Admin connection {$conn->resourceId} has disconnected\n";
            return;
        }

        if (isset($conn->deviceId)) {
            $this->db->upsertClient($conn->deviceId, 'Offline');
            $this->clients->detach($conn);
            $this->log('socket', 'Device connection closed', [
                'resourceId' => $conn->resourceId ?? null,
                'deviceId' => $conn->deviceId,
            ]);
            echo "Connection {$conn->resourceId} with Device ID {$conn->deviceId} has disconnected\n";
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $this->log('error', 'WebSocket connection error', [
            'resourceId' => $conn->resourceId ?? null,
            'deviceId' => $conn->deviceId ?? null,
            'message' => $e->getMessage(),
        ]);
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        if (!is_array($data)) {
            $this->log('error', 'Rejected non-JSON WebSocket message', [
                'resourceId' => $from->resourceId ?? null,
                'messageLength' => strlen((string) $msg),
            ]);
            return;
        }

        $role = $from->role ?? 'device';
        $this->log('socket', 'Message received', [
            'resourceId' => $from->resourceId ?? null,
            'role' => $role,
            'deviceId' => $from->deviceId ?? null,
            'command' => $data['command'] ?? null,
            'requestId' => $data['requestId'] ?? null,
            'sentTo' => $data['sentTo'] ?? null,
            'hasErrorLog' => !empty($data['errorlog']),
        ]);

        if ($role === 'admin') {
            $requestId = $data['requestId'] ?? uniqid('req-', true);
            $targetDevice = $data['sentTo'] ?? null;
            $tabId = $data['tabId'] ?? $from->tabId ?? null;
            $this->log('trigger', 'Admin command trigger received', [
                'requestId' => $requestId,
                'targetDevice' => $targetDevice,
                'command' => $data['command'] ?? null,
                'tabId' => $tabId,
            ]);

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
                $this->log('error', 'Blocked admin self-command', [
                    'requestId' => $requestId,
                    'targetDevice' => $targetDevice,
                ]);
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

                if (($client->deviceId ?? null) === $targetDevice) {
                    $payload = $data;
                    $payload['requestId'] = $requestId;
                    $payload['tabId'] = $tabId;
                    $payload['sentBy'] = $this->adminDeviceId;
                    $payload['replyToTabId'] = $tabId;
                    $client->send(json_encode($payload));
                    $this->log('trigger', 'Admin command delivered to device', [
                        'requestId' => $requestId,
                        'targetDevice' => $targetDevice,
                        'command' => $data['command'] ?? null,
                    ]);
                    echo "Admin sent command to Client: " . $targetDevice;
                    return;
                }
            }

            $this->log('error', 'Admin command target device not connected', [
                'requestId' => $requestId,
                'targetDevice' => $targetDevice,
                'command' => $data['command'] ?? null,
            ]);
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
        $deviceId = $from->deviceId ?? null;

        if ($requestId && isset($this->pendingRequests[$requestId])) {
            $replyToTabId = $this->pendingRequests[$requestId]['tabId'] ?? null;
        } elseif ($deviceId !== null && isset($this->pendingRequestsByDevice[$deviceId])) {
            $pending = $this->pendingRequestsByDevice[$deviceId];
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
        $this->log('trigger', 'Device response forwarded to admin', [
            'requestId' => $requestId,
            'deviceId' => $deviceId,
            'replyToTabId' => $replyToTabId,
            'status' => $payload['status'],
        ]);

        if ($requestId && isset($this->pendingRequests[$requestId])) {
            unset($this->pendingRequests[$requestId]);
        }
        if ($deviceId && isset($this->pendingRequestsByDevice[$deviceId])) {
            unset($this->pendingRequestsByDevice[$deviceId]);
        }
    }

    private function sendToAdminTab($payload, $tabId = null) {
        $json = json_encode($payload);
        $sentCount = 0;
        foreach ($this->clients as $client) {
            if (($client->role ?? 'device') !== 'admin') {
                continue;
            }
            if ($tabId === null || ($client->tabId ?? null) === $tabId) {
                $client->send($json);
                $sentCount++;
            }
        }
        $this->log('socket', 'Payload sent to admin tab(s)', [
            'tabId' => $tabId,
            'sentCount' => $sentCount,
            'type' => $payload['type'] ?? null,
            'requestId' => $payload['requestId'] ?? null,
            'status' => $payload['status'] ?? null,
        ]);
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
            $deviceId = $device['deviceId'] ?? null;
            if (!$this->isValidDeviceId($deviceId)) {
                continue;
            }

            $status = $device['status'] ?? null;
            $isConnected = false;

            foreach ($this->clients as $client) {
                if (($client->role ?? 'device') === 'device' && ($client->deviceId ?? null) === $deviceId) {
                    $isConnected = true;
                    break;
                }
            }

            if (!$isConnected && ($status === 'Online' || $status === 'Loggedin')) {
                $this->db->upsertClient($deviceId, 'Offline');
                $this->log('socket', 'Marked stale device offline', [
                    'deviceId' => $deviceId,
                    'previousStatus' => $status,
                ]);
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

    private function log($tag, $message, array $context = []) {
        $contextJson = $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        echo sprintf("[%s] %s%s\n", $tag, $message, $contextJson);
    }
}
