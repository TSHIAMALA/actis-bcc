<?php

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

$sqlConseillers = "CREATE TABLE IF NOT EXISTS conseillers_gouverneur (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    utilisateur_id BIGINT UNSIGNED NOT NULL UNIQUE,
    domaine_competence VARCHAR(255) NOT NULL,
    code_domaine VARCHAR(50) NOT NULL UNIQUE,
    description TEXT NULL,
    ordre_affichage INT DEFAULT 0 NOT NULL,
    actif TINYINT(1) DEFAULT 1 NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT fk_conseiller_user FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

$sqlSuivis = "CREATE TABLE IF NOT EXISTS suivis_conseillers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action_id BIGINT UNSIGNED NOT NULL,
    conseiller_id BIGINT UNSIGNED NOT NULL,
    statut_suivi VARCHAR(50) DEFAULT 'EN_ATTENTE' NOT NULL,
    avis LONGTEXT NULL,
    recommandations LONGTEXT NULL,
    point_attention LONGTEXT NULL,
    priorite_cabinet VARCHAR(30) DEFAULT 'NORMALE' NOT NULL,
    date_affectation DATETIME NOT NULL,
    date_avis DATETIME NULL,
    affecte_par BIGINT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT fk_suivi_action FOREIGN KEY (action_id) REFERENCES actions (id) ON DELETE CASCADE,
    CONSTRAINT fk_suivi_conseiller FOREIGN KEY (conseiller_id) REFERENCES conseillers_gouverneur (id) ON DELETE CASCADE,
    CONSTRAINT fk_suivi_affecte_par FOREIGN KEY (affecte_par) REFERENCES utilisateurs (id) ON DELETE SET NULL,
    INDEX idx_suivi_action (action_id),
    INDEX idx_suivi_conseiller (conseiller_id),
    INDEX idx_suivi_statut (statut_suivi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

$pdo->exec($sqlConseillers);
echo "Table conseillers_gouverneur created / verified.\n";

$pdo->exec($sqlSuivis);
echo "Table suivis_conseillers created / verified.\n";

// Let's seed standard advisors if none exist
$count = $pdo->query("SELECT COUNT(*) FROM conseillers_gouverneur")->fetchColumn();
if ($count == 0) {
    echo "Checking users to seed initial advisors...\n";
    $users = $pdo->query("SELECT id, nom, prenom, email FROM utilisateurs LIMIT 5")->fetchAll();
    
    $domaines = [
        ['code' => 'ECO_FIN', 'nom' => 'Economie, Finances & Budget', 'desc' => 'Suivi macro-économique, réformes financières, budget et trésorerie.'],
        ['code' => 'JUR_REG', 'nom' => 'Juridique & Réglementation', 'desc' => 'Conformité légale, contentieux, réformes réglementaires bancaires.'],
        ['code' => 'INFRA_TECH', 'nom' => 'Systèmes d\'Information & Infrastructures', 'desc' => 'Modernisation informatique, digitalisation des moyens de paiement, cybersécurité.'],
        ['code' => 'POL_MON', 'nom' => 'Politique Monétaire & Marchés', 'desc' => 'Stabilité des prix, opérations d\'open market, réserves de change.'],
    ];

    if (!empty($users)) {
        $stmt = $pdo->prepare("INSERT INTO conseillers_gouverneur (utilisateur_id, domaine_competence, code_domaine, description, ordre_affichage, actif, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())");
        foreach ($domaines as $idx => $dom) {
            if (isset($users[$idx])) {
                $stmt->execute([
                    $users[$idx]['id'],
                    $dom['nom'],
                    $dom['code'],
                    $dom['desc'],
                    ($idx + 1) * 10
                ]);
                echo "Advisor seeded for {$users[$idx]['nom']} ({$dom['nom']})\n";
            }
        }
    }
}

echo "Database preparation finished successfully.\n";
