<?php
/**
 * Configurazione Database MySQL
 * File di configurazione per la connessione al database
 */

// Configurazioni del database - MODIFICARE CON I PROPRI PARAMETRI
define('DB_HOST', 'localhost');          // Indirizzo del server MySQL
define('DB_NAME', 'vaglioty_DB_VP'); // Nome del database
define('DB_USER', 'vaglioty_DB_VP');      // Username MySQL
define('DB_PASS', 'busriMnyahh2Xc5');      // Password MySQL
define('DB_CHARSET', 'utf8mb4');         // Charset del database

// Classe per la gestione della connessione al database
class DatabaseConnection {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ];
            
            // Aggiungi opzione MySQL solo se disponibile
            if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
                $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4";
            }
            
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
            
            // Imposta charset come query separata se l'opzione non è disponibile
            if (!defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
                $this->connection->exec("SET NAMES utf8mb4");
            }
            
        } catch(PDOException $e) {
            die("Errore di connessione al database: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    // Metodo per testare la connessione
    public function testConnection() {
        try {
            $stmt = $this->connection->query("SELECT 1");
            return true;
        } catch(PDOException $e) {
            return false;
        }
    }
}

// Funzione di utilità per ottenere la connessione
function getDatabase() {
    return DatabaseConnection::getInstance()->getConnection();
}

// Headers per API JSON
function setJSONHeaders() {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

// Funzione per gestire errori API
function sendErrorResponse($message, $code = 400) {
    setJSONHeaders();
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// Funzione per gestire risposte di successo API
function sendSuccessResponse($data, $message = 'Operazione completata con successo') {
    setJSONHeaders();
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// Configurazioni aggiuntive
define('API_VERSION', '1.0');
define('TIMEZONE', 'Europe/Rome');

// Imposta il timezone
date_default_timezone_set(TIMEZONE);

// Creazione automatica cartella logs
$logsDir = __DIR__ . '/logs';
if (!file_exists($logsDir)) {
    if (!mkdir($logsDir, 0755, true)) {
        die("Impossibile creare la cartella logs: $logsDir");
    }
}

// Verifica che la cartella sia scrivibile
if (!is_writable($logsDir)) {
    chmod($logsDir, 0755);
    if (!is_writable($logsDir)) {
        die("La cartella logs non è scrivibile: $logsDir");
    }
}

// Log degli errori
ini_set('log_errors', 1);
ini_set('error_log', $logsDir . '/php_errors.log');
?>