<?php
namespace ERC\WebSocket;
use PDO;

class Database {
    private $pdo;
    private $driver;

    public function __construct() {
        $this->connect();
        $this->initialize();
    }

    private function connect() {
        $driver = getenv('DB_CONNECTION') ?: 'sqlite';
        $this->driver = $driver;

        if ($driver === 'pgsql') {
            $host = getenv('DB_HOST') ?: 'db';
            $port = getenv('DB_PORT') ?: '5432';
            $database = getenv('DB_DATABASE') ?: 'ercsocket';
            $username = getenv('DB_USERNAME') ?: 'ercsocket';
            $password = getenv('DB_PASSWORD') ?: 'ercsocket';

            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database);
            $this->pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            return;
        }

        $dbPath = getenv('DB_PATH') ?: __DIR__ . '/../../clients.db';
        $this->pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private function initialize() {
        if ($this->driver === 'pgsql') {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS clients (
                id SERIAL PRIMARY KEY,
                deviceId TEXT UNIQUE,
                email TEXT,
                status TEXT
            )");
            $this->ensurePostgresIdDefault();
            return;
        }

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS clients (
            id INTEGER PRIMARY KEY,
            deviceId TEXT UNIQUE,
            email TEXT,
            status TEXT
        )");
    }

    private function ensurePostgresIdDefault() {
        $default = $this->pdo
            ->query("SELECT column_default FROM information_schema.columns WHERE table_name = 'clients' AND column_name = 'id'")
            ->fetchColumn();

        if ($default) {
            return;
        }

        $this->pdo->exec("CREATE SEQUENCE IF NOT EXISTS clients_id_seq OWNED BY clients.id");
        $this->pdo->exec("SELECT setval('clients_id_seq', COALESCE((SELECT MAX(id) FROM clients), 0) + 1, false)");
        $this->pdo->exec("ALTER TABLE clients ALTER COLUMN id SET DEFAULT nextval('clients_id_seq')");
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
