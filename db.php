<?php
/**
 * db.php — Database connection & schema-safety helpers
 * AI Accountant — PHP 8.x / MySQLi with prepared statements
 */

// ----------------------------------------------------
// Configuration (override via environment variables)
// ----------------------------------------------------
$host   = getenv('DB_HOST')   ?: 'localhost';
$user   = getenv('DB_USER')   ?: 'root';
$pass   = getenv('DB_PASS')   ?: '';
$dbname = getenv('DB_NAME')   ?: 'ai_accountant';
$port   = (int)(getenv('DB_PORT') ?: 3306);
$socket = getenv('DB_SOCKET') ?: '';  // e.g. /tmp/mysqlrun/mysql.sock

// When host is 'localhost' and a socket is provided, use it directly.
// Otherwise 'localhost' in MySQLi defaults to the system socket path.

// ----------------------------------------------------
// Establish MySQLi connection (utf8mb4 for emoji / Devanagari)
// ----------------------------------------------------
if ($socket !== '') {
    $conn = new mysqli($host, $user, $pass, $dbname, $port, $socket);
} else {
    $conn = new mysqli($host, $user, $pass, $dbname, $port);
}

if ($conn->connect_error) {
    http_response_code(500);
    die("Database Connection Failed: " . htmlspecialchars($conn->connect_error));
}

if (!$conn->set_charset('utf8mb4')) {
    // Fallback — not fatal
    $conn->query("SET NAMES utf8mb4");
}

// ----------------------------------------------------
// Schema-Safety Helpers
// ----------------------------------------------------

/**
 * Check whether a column exists on a table.
 * Prevents crashes when the DB schema is missing optional columns
 * (e.g. party_name, notes) added in later migrations.
 */
function columnExists(mysqli $conn, string $table, string $column): bool
{
    // SHOW COLUMNS does not support prepared-statement placeholders,
    // so we escape the identifiers manually (internal use only).
    $table  = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

/**
 * Build a safe SELECT fragment for an optional column.
 * Returns the real column name if it exists, or a NULL alias
 * so downstream code always sees the key.
 *
 *   safeColumn($conn, 'transactions', 'party_name')
 *   => "party_name"
 *
 *   safeColumn($conn, 'transactions', 'notes', 'remarks')
 *   => "remarks AS notes"
 */
function safeColumn(mysqli $conn, string $table, string $column, ?string $fallback = null): string
{
    if (columnExists($conn, $table, $column)) {
        return "`$column`";
    }
    if ($fallback !== null && columnExists($conn, $table, $fallback)) {
        return "`$fallback` AS `$column`";
    }
    return "NULL AS `$column`";
}

/**
 * Determine the effective "notes" column name for INSERT / display.
 * Prefers `notes`, falls back to `remarks`, returns '' if neither exists.
 */
function notesColumn(mysqli $conn): string
{
    if (columnExists($conn, 'transactions', 'notes')) return 'notes';
    if (columnExists($conn, 'transactions', 'remarks')) return 'remarks';
    return '';
}

/**
 * Ensure optional columns exist; auto-migrate if missing.
 * Called once on authenticated page load for forward-compatibility.
 */
function ensureSchema(mysqli $conn): void
{
    $migrations = [
        "ALTER TABLE transactions ADD COLUMN IF NOT EXISTS `party_name` VARCHAR(255) DEFAULT NULL AFTER `amount`",
        "ALTER TABLE transactions ADD COLUMN IF NOT EXISTS `notes` TEXT DEFAULT NULL AFTER `remarks`",
    ];
    foreach ($migrations as $sql) {
        @$conn->query($sql); // suppress — column may already exist
    }

    // Add helpful indexes if missing (idempotent)
    $indexChecks = [
        'idx_txn_user_date' => 'CREATE INDEX IF NOT EXISTS idx_txn_user_date ON transactions (user_id, date_ad)',
        'idx_txn_user_type' => 'CREATE INDEX IF NOT EXISTS idx_txn_user_type ON transactions (user_id, transaction_type)',
    ];
    foreach ($indexChecks as $sql) {
        @$conn->query($sql);
    }
}

// Run forward-compatible migration on every connection
ensureSchema($conn);
