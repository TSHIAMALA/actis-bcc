<?php

/**
 * Responsables multiples (conseillers du Gouverneur) sur les instructions :
 *  - crée la table instruction_responsables ;
 *  - rend facultative l'ancienne entité pilote (les entités se déduisent désormais des actions).
 * Idempotent : peut être relancé sans risque.
 *
 * Usage : php scripts/add_instruction_responsables.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

$dotenv = new Dotenv();
$dotenv->bootEnv(__DIR__ . '/../.env');

$dbUrl = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');

// Parse database URL
$parsed = parse_url($dbUrl);
// Identifiant et mot de passe sont encodés dans l'URL (ex. « @ » écrit « %40 ») : on les décode comme Doctrine
$user = rawurldecode($parsed['user'] ?? 'root');
$pass = rawurldecode($parsed['pass'] ?? '');
$host = $parsed['host'] ?? '127.0.0.1';
$port = $parsed['port'] ?? 3306;
$dbname = ltrim($parsed['path'] ?? 'actis_bcc', '/');

$dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "Connected to {$dbname}\n";

// Les clés étrangères exigent des tables InnoDB
foreach (['conseillers_gouverneur', 'suivis_conseillers'] as $table) {
    $engine = $pdo->query("SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table'")->fetchColumn();
    if ($engine && $engine !== 'InnoDB') {
        $pdo->exec("ALTER TABLE $table ENGINE=InnoDB");
        echo "Table $table convertie en InnoDB\n";
    }
}

// Type exact de la clé primaire des tables liées (les clés étrangères doivent correspondre)
$typeOf = fn (string $table) => $pdo->query(
    "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND COLUMN_NAME = 'id'"
)->fetchColumn();
$instructionIdType = $typeOf('instructions');
$conseillerIdType = $typeOf('conseillers_gouverneur');

$pdo->exec("CREATE TABLE IF NOT EXISTS instruction_responsables (
    instruction_id $instructionIdType NOT NULL,
    conseiller_id $conseillerIdType NOT NULL,
    PRIMARY KEY (instruction_id, conseiller_id),
    INDEX idx_instruction_responsables_conseiller (conseiller_id),
    CONSTRAINT fk_instruction_responsables_instruction FOREIGN KEY (instruction_id) REFERENCES instructions (id) ON DELETE CASCADE,
    CONSTRAINT fk_instruction_responsables_conseiller FOREIGN KEY (conseiller_id) REFERENCES conseillers_gouverneur (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "Table instruction_responsables prête\n";

$nullable = $pdo->query(
    "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'instructions' AND COLUMN_NAME = 'entite_pilote_id'"
)->fetchColumn();
if ($nullable === 'NO') {
    $type = $pdo->query(
        "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'instructions' AND COLUMN_NAME = 'entite_pilote_id'"
    )->fetchColumn();
    $pdo->exec("ALTER TABLE instructions MODIFY entite_pilote_id $type NULL");
    echo "Colonne instructions.entite_pilote_id rendue facultative\n";
} else {
    echo "Colonne instructions.entite_pilote_id déjà facultative\n";
}

echo "Terminé.\n";
