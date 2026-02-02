<?php
/**
 * Database Configuration
 * Update these settings to match your PostgreSQL server
 */

define('DB_HOST', 'temp-ops-onboarding-jan-26-do-user-2735892-0.h.db.ondigitalocean.com');
define('DB_PORT', '25060');
define('DB_NAME', 'mock_lrf');
define('DB_USER', 'doadmin');
define('DB_PASS', 'AVNS_4AprZZQFgUjMnSYh048');

/**
 * Get PDO database connection
 */
function getDbConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=require',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            die('Database connection failed: ' . $e->getMessage());
        }
    }

    return $pdo;
}

/**
 * Flash message handling
 */
function setFlashMessage(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlashMessage(): ?array
{
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Generate a random output table name (10 alpha characters)
 */
function generateOutputTableName(): string
{
    $chars = 'abcdefghijklmnopqrstuvwxyz';
    $name = '';
    for ($i = 0; $i < 10; $i++) {
        $name .= $chars[random_int(0, 25)];
    }
    return $name;
}
