<?php

/**
 * Ajoute à la table `instructions` les colonnes du document officiel (PDF) et du lien portail BCC.
 * Idempotent : peut être relancé sans risque.
 *
 * Usage : php scripts/add_instruction_document_columns.php
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

$columns = [
    'document_fichier' => "ALTER TABLE instructions ADD COLUMN document_fichier VARCHAR(255) NULL AFTER emetteur",
    'document_nom_original' => "ALTER TABLE instructions ADD COLUMN document_nom_original VARCHAR(255) NULL AFTER document_fichier",
    'lien_portail' => "ALTER TABLE instructions ADD COLUMN lien_portail VARCHAR(500) NULL AFTER document_nom_original",
];

$check = $pdo->prepare(
    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND COLUMN_NAME = :col'
);

foreach ($columns as $column => $sql) {
    $check->execute(['db' => $dbname, 'table' => 'instructions', 'col' => $column]);
    if ((int) $check->fetchColumn() > 0) {
        echo "Colonne {$column} déjà présente\n";
        continue;
    }
    $pdo->exec($sql);
    echo "Colonne {$column} ajoutée\n";
}

echo "Terminé.\n";
