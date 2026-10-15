<?php

namespace App\Service;

use App\Entity\ConseillerGouverneur;
use App\Entity\Entite;
use App\Entity\Instruction;
use App\Entity\Priorite;
use App\Repository\ConseillerGouverneurRepository;
use App\Repository\EntiteRepository;
use App\Repository\PrioriteRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

class AiActionExtractorService
{
    /** Nombre maximal de conseillers proposés comme responsables */
    private const MAX_CONSEILLERS = 2;

    public function __construct(
        private readonly EntiteRepository $entiteRepo,
        private readonly PrioriteRepository $prioriteRepo,
        private readonly ConseillerGouverneurRepository $conseillerRepo,
        #[Autowire('%env(default::GEMINI_API_KEY)%')]
        private readonly ?string $geminiApiKey = null,
        #[Autowire('%env(default::GEMINI_MODEL)%')]
        private readonly ?string $geminiModel = 'gemini-3.5-flash',
        #[Autowire('%env(default::OPENAI_API_KEY)%')]
        private readonly ?string $openaiApiKey = null,
        #[Autowire('%env(default::OPENAI_MODEL)%')]
        private readonly ?string $openaiModel = 'gpt-4o-mini'
    ) {
    }

    /**
     * Analyse un document ou du texte pour en extraire une liste structurée d'actions opérationnelles,
     * et propose 1 à 2 conseillers du Gouverneur responsables selon leur domaine de compétence.
     *
     * @return array{
     *     actions: array<int, array<string, mixed>>,
     *     conseillers: array<int, array{id: int, nom: string, code: string, domaine: string, raison: string}>
     * }
     */
    public function extractActions(
        Instruction $instruction,
        ?UploadedFile $file = null,
        ?string $customPrompt = null
    ): array {
        $contexte = [
            'entites' => $this->entiteRepo->findActivesGroupees(),
            'priorites' => $this->prioriteRepo->findBy(['actif' => true], ['ordreAffichage' => 'ASC']),
            'conseillers' => $this->conseillerRepo->findActifs(),
        ];

        $fileBase64 = null;
        $fileMimeType = null;
        $extractedText = '';

        if ($file instanceof UploadedFile && $file->isValid()) {
            $extension = strtolower($file->getClientOriginalExtension());
            $mimeType = $file->getMimeType();

            if (in_array($extension, ['png', 'jpg', 'jpeg', 'webp']) || str_starts_with($mimeType ?? '', 'image/')) {
                $fileBase64 = base64_encode(file_get_contents($file->getPathname()));
                $fileMimeType = $mimeType ?: 'image/jpeg';
            } elseif ($extension === 'pdf' || $mimeType === 'application/pdf') {
                $fileBase64 = base64_encode(file_get_contents($file->getPathname()));
                $fileMimeType = 'application/pdf';
                $extractedText = $this->extractTextFromPdf($file->getPathname());
            } elseif ($extension === 'docx') {
                $extractedText = $this->extractTextFromDocx($file->getPathname());
            } else {
                $extractedText = file_get_contents($file->getPathname()) ?: '';
            }
        }

        // Compléter avec les métadonnées de l'instruction
        if (empty($extractedText) && empty($fileBase64)) {
            $extractedText = sprintf(
                "Instruction Ref: %s\nObjet: %s\nDescription: %s\nÉchéance globale: %s",
                $instruction->getReference(),
                $instruction->getObjet(),
                $instruction->getDescription() ?? '',
                $instruction->getDateEcheance()?->format('d/m/Y') ?? 'Non définie'
            );
        }

        // 1. Google Gemini API
        if (!empty($this->geminiApiKey)) {
            try {
                return $this->callGeminiApiWithFallbacks($instruction, $extractedText, $fileBase64, $fileMimeType, $customPrompt, $contexte);
            } catch (\Throwable $e) {
                // Si OpenAI est configuré en backup
                if (!empty($this->openaiApiKey)) {
                    try {
                        return $this->callOpenAiApi($instruction, $extractedText, $fileBase64, $fileMimeType, $customPrompt, $contexte);
                    } catch (\Throwable) {}
                }
                return $this->generateFallback($instruction, $extractedText, $contexte);
            }
        }

        // 2. OpenAI API
        if (!empty($this->openaiApiKey)) {
            try {
                return $this->callOpenAiApi($instruction, $extractedText, $fileBase64, $fileMimeType, $customPrompt, $contexte);
            } catch (\Throwable) {
                return $this->generateFallback($instruction, $extractedText, $contexte);
            }
        }

        // 3. Fallback
        return $this->generateFallback($instruction, $extractedText, $contexte);
    }

    /**
     * Consigne commune aux fournisseurs d'IA : actions à extraire et conseillers à proposer.
     */
    private function buildSystemPrompt(Instruction $instruction, array $contexte): string
    {
        $entitesList = array_map(fn (Entite $e) => sprintf('ID:%d | Code:%s | Nom:%s', $e->getId(), $e->getCode(), $e->getNom()), $contexte['entites']);
        $prioritesList = array_map(fn (Priorite $p) => sprintf('ID:%d | Code:%s | Libelle:%s', $p->getId(), $p->getCode(), $p->getLibelle()), $contexte['priorites']);
        $conseillersList = array_map(
            fn (ConseillerGouverneur $c) => sprintf('ID:%d | Code:%s | Conseiller:%s | Domaine:%s', $c->getId(), $c->getCodeDomaine(), $c->getNom(), $c->getDomaineCompetence()),
            $contexte['conseillers']
        );
        $echeance = $instruction->getDateEcheance()?->format('Y-m-d') ?? date('Y-m-d', strtotime('+30 days'));

        return "Tu es un assistant expert en gestion de projets et suivi opérationnel des instructions et Ordres de Service (OS) pour la Banque Centrale du Congo (BCC).\n"
            . "Ton rôle est de lire le document ou la description d'une instruction, de la décomposer en ACTIONS OPÉRATIONNELLES concrètes, mesurables et directement assignables, "
            . "et de proposer les conseillers du Gouverneur les mieux placés pour en suivre l'exécution.\n\n"
            . "LISTE DES ENTITÉS DISPONIBLES DANS LE SYSTÈME :\n" . implode("\n", $entitesList) . "\n\n"
            . "LISTE DES PRIORITÉS DISPONIBLES :\n" . implode("\n", $prioritesList) . "\n\n"
            . "LISTE DES CONSEILLERS DU GOUVERNEUR (avec leur domaine de compétence) :\n" . (implode("\n", $conseillersList) ?: '(aucun)') . "\n\n"
            . "CONSIGNES DE MAPPING DES ACTIONS :\n"
            . "1. Pour chaque action, fournis un 'libelle' clair et précis (ex: 'Élaborer le projet de circulaire relative aux taux directeurs').\n"
            . "2. 'resultat_attendu' : décris le livrable concret ou la preuve attendue.\n"
            . "3. 'entite_id' : choisis l'ID de l'entité dont les missions correspondent le mieux à l'action, parmi la liste ci-dessus.\n"
            . "4. 'priorite_id' : choisis l'ID de priorité adapté.\n"
            . "5. 'date_echeance' : format YYYY-MM-DD. Si l'instruction a une échéance ($echeance), échelonne les actions avant cette date.\n\n"
            . "CONSIGNES POUR LES CONSEILLERS RESPONSABLES :\n"
            . "6. 'conseillers_proposes' : propose 1 conseiller, ou 2 si l'instruction couvre clairement deux domaines (jamais plus de " . self::MAX_CONSEILLERS . "), "
            . "en comparant le sujet de l'instruction aux domaines de compétence ci-dessus. Pour chacun, donne 'conseiller_id' (ID de la liste) "
            . "et 'raison' (une phrase courte expliquant le lien entre l'instruction et son domaine). Si aucun domaine ne correspond, renvoie une liste vide.\n\n"
            . "RÉPONDS STRICTEMENT SOUS FORME D'UN OBJET JSON valide avec les clés 'actions' (liste des actions) et 'conseillers_proposes' (liste des conseillers).";
    }

    /**
     * Tente l'appel Gemini avec bascule automatique sur les modèles de secours en cas de charge temporaire
     */
    private function callGeminiApiWithFallbacks(
        Instruction $instruction,
        string $text,
        ?string $fileBase64,
        ?string $fileMimeType,
        ?string $customPrompt,
        array $contexte
    ): array {
        $primaryModel = !empty($this->geminiModel) ? $this->geminiModel : 'gemini-3.5-flash';
        $modelsToTry = array_values(array_filter(array_unique([
            $primaryModel,
            'gemini-3.5-flash',
            'gemini-3.7-flash',
            'gemini-3.1-flash-lite',
            'gemini-flash-latest'
        ])));

        $lastException = null;

        foreach ($modelsToTry as $model) {
            try {
                return $this->callGeminiSingleModel($model, $instruction, $text, $fileBase64, $fileMimeType, $customPrompt, $contexte);
            } catch (\Throwable $e) {
                $lastException = $e;
                continue;
            }
        }

        throw $lastException ?: new \RuntimeException('Aucun modèle Gemini n\'a pu traiter la demande.');
    }

    /**
     * Appel à un modèle Gemini spécifique
     */
    private function callGeminiSingleModel(
        string $modelName,
        Instruction $instruction,
        string $text,
        ?string $fileBase64,
        ?string $fileMimeType,
        ?string $customPrompt,
        array $contexte
    ): array {
        $userMessage = "Instruction de référence : " . $instruction->getReference() . "\n"
            . "Objet : " . $instruction->getObjet() . "\n\n";

        if (!empty($text)) {
            $userMessage .= "Texte extrait :\n" . $text . "\n\n";
        }

        if (!empty($customPrompt)) {
            $userMessage .= "Consignes particulières : " . $customPrompt . "\n";
        }

        $parts = [['text' => $userMessage]];

        if ($fileBase64 && $fileMimeType) {
            $parts[] = [
                'inlineData' => [
                    'mimeType' => $fileMimeType,
                    'data' => $fileBase64
                ]
            ];
        }

        $payload = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $this->buildSystemPrompt($instruction, $contexte)]
                ]
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => $parts
                ]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.2
            ]
        ];

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            urlencode($modelName),
            urlencode($this->geminiApiKey)
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || !empty($curlError)) {
            throw new \RuntimeException('Erreur cURL Gemini : ' . $curlError);
        }

        if ($httpCode >= 400) {
            $errData = json_decode($response, true);
            $msg = $errData['error']['message'] ?? ('Erreur HTTP ' . $httpCode);
            throw new \RuntimeException("Gemini API Error [$modelName]: " . $msg);
        }

        $data = json_decode($response, true);
        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '{}';

        // Nettoyage Markdown éventuel
        $content = trim($content);
        if (str_starts_with($content, '```json')) {
            $content = substr($content, 7);
        }
        if (str_starts_with($content, '```')) {
            $content = substr($content, 3);
        }
        if (str_ends_with($content, '```')) {
            $content = substr($content, 0, -3);
        }
        $parsed = json_decode(trim($content), true);

        // Ancien format : liste d'actions sans enveloppe
        if (is_array($parsed) && isset($parsed[0])) {
            $parsed = ['actions' => $parsed];
        }
        if (!isset($parsed['actions']) || !is_array($parsed['actions'])) {
            throw new \RuntimeException('Le format de réponse Gemini n\'a pas retourné de liste d\'actions.');
        }

        return $this->normalizeResult($parsed, $instruction, $contexte);
    }

    /**
     * Appel à l'API OpenAI Chat Completions
     */
    private function callOpenAiApi(
        Instruction $instruction,
        string $text,
        ?string $imageBase64,
        ?string $imageMimeType,
        ?string $customPrompt,
        array $contexte
    ): array {
        $userMessage = "Instruction de référence : " . $instruction->getReference() . "\n"
            . "Objet : " . $instruction->getObjet() . "\n\n"
            . "Contenu :\n" . $text;

        if (!empty($customPrompt)) {
            $userMessage .= "\n\nConsignes particulières : " . $customPrompt;
        }

        $userContent = [['type' => 'text', 'text' => $userMessage]];

        if ($imageBase64 && $imageMimeType && str_starts_with($imageMimeType, 'image/')) {
            $userContent[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => sprintf('data:%s;base64,%s', $imageMimeType, $imageBase64)
                ]
            ];
        }

        $payload = [
            'model' => $this->openaiModel,
            'messages' => [
                ['role' => 'system', 'content' => $this->buildSystemPrompt($instruction, $contexte)],
                ['role' => 'user', 'content' => $userContent]
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.2
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->openaiApiKey
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || !empty($curlError)) {
            throw new \RuntimeException('Erreur cURL OpenAI : ' . $curlError);
        }

        if ($httpCode >= 400) {
            $errData = json_decode($response, true);
            $msg = $errData['error']['message'] ?? ('Erreur HTTP ' . $httpCode);
            throw new \RuntimeException('OpenAI API Error: ' . $msg);
        }

        $data = json_decode($response, true);
        $parsed = json_decode($data['choices'][0]['message']['content'] ?? '{}', true) ?: [];

        return $this->normalizeResult($parsed, $instruction, $contexte);
    }

    /**
     * Normalise la réponse de l'IA : actions validées contre la base, 2 conseillers au plus.
     */
    private function normalizeResult(array $parsed, Instruction $instruction, array $contexte): array
    {
        $conseillers = $this->normalizeConseillers($parsed['conseillers_proposes'] ?? [], $contexte['conseillers']);

        return [
            'actions' => $this->normalizeActions($parsed['actions'] ?? [], $instruction, $contexte),
            // Si l'IA n'a rien proposé, on se rabat sur la correspondance par mots-clés
            'conseillers' => $conseillers ?: $this->suggestConseillersParMotsCles($instruction->getObjet() . ' ' . $instruction->getDescription(), $contexte['conseillers']),
        ];
    }

    /**
     * Normalise et valide chaque action pour correspondre aux entités en BDD
     */
    private function normalizeActions(array $rawActions, Instruction $instruction, array $contexte): array
    {
        $validEntiteIds = array_map(fn (Entite $e) => $e->getId(), $contexte['entites']);
        $validPrioriteIds = array_map(fn (Priorite $p) => $p->getId(), $contexte['priorites']);
        // Entité par défaut : celle des actions déjà saisies, sinon aucune (l'utilisateur choisit)
        $defaultEntiteId = ($instruction->getEntitesConcernees()[0] ?? null)?->getId();
        $defaultPrioriteId = $instruction->getPriorite()?->getId() ?? ($validPrioriteIds[0] ?? null);
        $defaultDate = $instruction->getDateEcheance()?->format('Y-m-d') ?? date('Y-m-d', strtotime('+30 days'));

        $normalized = [];
        foreach ($rawActions as $act) {
            $libelle = trim($act['libelle'] ?? $act['titre'] ?? '');
            if (empty($libelle)) {
                continue;
            }

            $entiteId = isset($act['entite_id']) && in_array((int) $act['entite_id'], $validEntiteIds, true)
                ? (int) $act['entite_id']
                : $defaultEntiteId;

            $prioriteId = isset($act['priorite_id']) && in_array((int) $act['priorite_id'], $validPrioriteIds, true)
                ? (int) $act['priorite_id']
                : $defaultPrioriteId;

            $dateEcheance = !empty($act['date_echeance']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $act['date_echeance'])
                ? $act['date_echeance']
                : $defaultDate;

            $normalized[] = [
                'libelle' => $libelle,
                'resultat_attendu' => trim($act['resultat_attendu'] ?? $act['description'] ?? ''),
                'entite_id' => $entiteId,
                'priorite_id' => $prioriteId,
                'date_echeance' => $dateEcheance
            ];
        }

        return $normalized;
    }

    /**
     * Ne garde que des conseillers actifs existants, sans doublon, 2 au plus.
     *
     * @param ConseillerGouverneur[] $allConseillers
     */
    private function normalizeConseillers(array $raw, array $allConseillers): array
    {
        $parId = [];
        foreach ($allConseillers as $c) {
            $parId[$c->getId()] = $c;
        }

        $result = [];
        foreach ($raw as $item) {
            $id = (int) ($item['conseiller_id'] ?? $item['id'] ?? 0);
            if (!isset($parId[$id]) || isset($result[$id])) {
                continue;
            }
            $result[$id] = $this->formatConseiller($parId[$id], trim((string) ($item['raison'] ?? '')));
            if (count($result) >= self::MAX_CONSEILLERS) {
                break;
            }
        }

        return array_values($result);
    }

    /**
     * Sans IA : rapproche les mots du texte de l'instruction des domaines de compétence.
     *
     * @param ConseillerGouverneur[] $allConseillers
     */
    private function suggestConseillersParMotsCles(string $texte, array $allConseillers): array
    {
        $motsTexte = $this->motsSignificatifs($texte);
        if (!$motsTexte) {
            return [];
        }

        $scores = [];
        foreach ($allConseillers as $c) {
            $communs = array_intersect($this->motsSignificatifs($c->getDomaineCompetence() ?? ''), $motsTexte);
            if ($communs) {
                $scores[] = ['conseiller' => $c, 'score' => count($communs), 'mots' => array_values($communs)];
            }
        }
        usort($scores, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_map(
            fn ($s) => $this->formatConseiller($s['conseiller'], 'Mots communs avec son domaine : ' . implode(', ', array_slice($s['mots'], 0, 3)) . '.'),
            array_slice($scores, 0, self::MAX_CONSEILLERS)
        );
    }

    /** @return string[] racines (6 premières lettres) des mots de plus de 4 lettres, sans accents */
    private function motsSignificatifs(string $texte): array
    {
        $texte = (new AsciiSlugger())->slug($texte, ' ')->lower()->toString();
        preg_match_all('/[a-z]{5,}/', $texte, $m);
        $vides = ['suivi', 'questions', 'question', 'direction', 'relations', 'activites', 'gestion', 'banque', 'instruction', 'mise', 'place'];

        return array_values(array_unique(array_map(
            fn ($mot) => substr($mot, 0, 6),
            array_diff($m[0], $vides)
        )));
    }

    private function formatConseiller(ConseillerGouverneur $c, string $raison): array
    {
        return [
            'id' => $c->getId(),
            'nom' => $c->getNom(),
            'code' => $c->getCodeDomaine(),
            'domaine' => $c->getDomaineCompetence(),
            'raison' => $raison !== '' ? $raison : 'Domaine de compétence en lien avec l\'instruction.',
        ];
    }

    /**
     * Extraction de texte depuis un fichier Word (.docx)
     */
    private function extractTextFromDocx(string $filePath): string
    {
        if (!class_exists(\ZipArchive::class)) {
            return '';
        }

        $zip = new \ZipArchive();
        if ($zip->open($filePath) === true) {
            $content = $zip->getFromName('word/document.xml');
            $zip->close();
            if ($content !== false) {
                return strip_tags(str_replace(['</w:p>', '</w:r>'], ["\n", ' '], $content));
            }
        }

        return '';
    }

    /**
     * Extraction simple de texte depuis un PDF
     */
    private function extractTextFromPdf(string $filePath): string
    {
        $content = @file_get_contents($filePath);
        if ($content === false) {
            return '';
        }

        preg_match_all('/\((.*?)\)\s*Tj/s', $content, $matches1);
        preg_match_all('/\[(.*?)\]\s*TJ/s', $content, $matches2);

        $text = implode(' ', $matches1[1] ?? []) . ' ' . implode(' ', $matches2[1] ?? []);
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Générateur de repli (si clé API absente ou service indisponible) :
     * actions types et conseillers choisis par correspondance de mots-clés.
     */
    private function generateFallback(Instruction $instruction, string $texte, array $contexte): array
    {
        $defaultEntiteId = ($instruction->getEntitesConcernees()[0] ?? null)?->getId();
        $defaultPrioriteId = $instruction->getPriorite()?->getId() ?? (($contexte['priorites'][0] ?? null)?->getId());
        $baseDate = $instruction->getDateEcheance() ?? new \DateTime('+30 days');

        $actions = [
            [
                'libelle' => 'Analyse préalable et cadrage technique de l\'instruction',
                'resultat_attendu' => 'Note de cadrage et identification des parties prenantes clés.',
                'entite_id' => $defaultEntiteId,
                'priorite_id' => $defaultPrioriteId,
                'date_echeance' => (clone $baseDate)->modify('-20 days')->format('Y-m-d')
            ],
            [
                'libelle' => sprintf('Mise en œuvre des directives relatives à : %s', mb_substr($instruction->getObjet(), 0, 70)),
                'resultat_attendu' => 'Rapport d\'exécution opérationnel et livrables intermédiaires validés.',
                'entite_id' => $defaultEntiteId,
                'priorite_id' => $defaultPrioriteId,
                'date_echeance' => (clone $baseDate)->modify('-10 days')->format('Y-m-d')
            ],
            [
                'libelle' => 'Contrôle de conformité et validation des résultats finaux',
                'resultat_attendu' => 'Procès-verbal de recette et note de synthèse pour la Haute Direction.',
                'entite_id' => $defaultEntiteId,
                'priorite_id' => $defaultPrioriteId,
                'date_echeance' => $baseDate->format('Y-m-d')
            ]
        ];

        return [
            'actions' => $actions,
            'conseillers' => $this->suggestConseillersParMotsCles(
                $instruction->getObjet() . ' ' . $instruction->getDescription() . ' ' . $texte,
                $contexte['conseillers']
            ),
        ];
    }
}
