<?php
class Database {
    private $pdo;

    public function __construct() {
        $this->pdo = new PDO('sqlite:clients.db');
        $this->initialize();
    }

    private function initialize() {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS clients (
            id INTEGER PRIMARY KEY,
            deviceId TEXT UNIQUE,
            email TEXT,
            status TEXT
        )");
    }

  public function upsertClient($deviceId, $status, $email = null) {
    // Check if client with given deviceId already exists
    $existingClient = $this->getClientByDeviceId($deviceId);

    if ($existingClient) {
        // Client exists, update status and optionally email
        if ($email !== null) {
            $stmt = $this->pdo->prepare("UPDATE clients SET email = :email, status = :status WHERE deviceId = :deviceId");
            $stmt->execute([':email' => $email, ':status' => $status, ':deviceId' => $deviceId]);
            echo "Device {$deviceId} updated with email: $email and status: $status\n";
        } else {
            $stmt = $this->pdo->prepare("UPDATE clients SET status = :status WHERE deviceId = :deviceId");
            $stmt->execute([':status' => $status, ':deviceId' => $deviceId]);
            echo "Device {$deviceId} updated with status: $status\n";
        }
    } else {
        // Client does not exist, insert new client with deviceId, email (if provided), and status
        if ($email !== null) {
            $stmt = $this->pdo->prepare("INSERT INTO clients (deviceId, email, status) VALUES (:deviceId, :email, :status)");
            $stmt->execute([':deviceId' => $deviceId, ':email' => $email, ':status' => $status]);
            echo "New device {$deviceId} logged in with email: $email and status: $status\n";
        } else {
            $stmt = $this->pdo->prepare("INSERT INTO clients (deviceId, status) VALUES (:deviceId, :status)");
            $stmt->execute([':deviceId' => $deviceId, ':status' => $status]);
            echo "New device {$deviceId} connected with status: $status\n";
        }
    }
}

    public function getClientByDeviceId($deviceId) {
        $stmt = $this->pdo->prepare("SELECT * FROM clients WHERE deviceId = :deviceId LIMIT 1");
        $stmt->bindParam(':deviceId', $deviceId);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getClients() {
        $stmt = $this->pdo->query("SELECT deviceId , email, status FROM clients");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
