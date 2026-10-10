<?php

namespace App\Service;

use App\Entity\Entite;
use App\Entity\Instruction;
use App\Entity\Priorite;
use App\Repository\EntiteRepository;
use App\Repository\PrioriteRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class AiActionExtractorService
{
    public function __construct(
        private readonly EntiteRepository $entiteRepo,
        private readonly PrioriteRepository $prioriteRepo,
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
     * Analyse un document ou du texte pour en extraire une liste structurée d'actions opérationnelles.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractActions(
        Instruction $instruction,
        ?UploadedFile $file = null,
        ?string $customPrompt = null
    ): array {
        $allEntites = $this->entiteRepo->findBy(['actif' => true], ['nom' => 'ASC']);
        $allPriorites = $this->prioriteRepo->findBy(['actif' => true], ['ordreAffichage' => 'ASC']);

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
            } elseif ($extension === 'txt') {
                $extractedText = file_get_contents($file->getPathname()) ?: '';
            } else {
                $extractedText = file_get_contents($file->getPathname()) ?: '';
            }
        }

        // Compléter avec les métadonnées de l'instruction
        if (empty($extractedText) && empty($fileBase64)) {
            $extractedText = sprintf(
                "Instruction Ref: %s\nObjet: %s\nDescription: %s\nDirection Pilote: %s\nÉchéance globale: %s",
                $instruction->getReference(),
                $instruction->getObjet(),
                $instruction->getDescription() ?? '',
                $instruction->getEntitePilote()?->getNom() ?? '',
                $instruction->getDateEcheance()?->format('d/m/Y') ?? 'Non définie'
            );
        }

        // 1. Google Gemini API
        if (!empty($this->geminiApiKey)) {
            try {
                return $this->callGeminiApiWithFallbacks(
                    $instruction,
                    $extractedText,
                    $fileBase64,
                    $fileMimeType,
                    $customPrompt,
                    $allEntites,
                    $allPriorites
                );
            } catch (\Throwable $e) {
                // Si OpenAI est configuré en backup
                if (!empty($this->openaiApiKey)) {
                    try {
                        return $this->callOpenAiApi(
                            $instruction,
                            $extractedText,
                            $fileBase64,
                            $fileMimeType,
                            $customPrompt,
                            $allEntites,
                            $allPriorites
                        );
                    } catch (\Throwable) {}
                }
                return $this->generateFallbackActions($instruction, $customPrompt, $allEntites, $allPriorites, $e->getMessage());
            }
        }

        // 2. OpenAI API
        if (!empty($this->openaiApiKey)) {
            try {
                return $this->callOpenAiApi(
                    $instruction,
                    $extractedText,
                    $fileBase64,
                    $fileMimeType,
                    $customPrompt,
                    $allEntites,
                    $allPriorites
                );
            } catch (\Throwable $e) {
                return $this->generateFallbackActions($instruction, $customPrompt, $allEntites, $allPriorites, $e->getMessage());
            }
        }

        // 3. Fallback
        return $this->generateFallbackActions($instruction, $customPrompt, $allEntites, $allPriorites);
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
        array $allEntites,
        array $allPriorites
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
                return $this->callGeminiSingleModel(
                    $model,
                    $instruction,
                    $text,
                    $fileBase64,
                    $fileMimeType,
                    $customPrompt,
                    $allEntites,
                    $allPriorites
                );
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
        array $allEntites,
        array $allPriorites
    ): array {
        $entitesList = array_map(fn(Entite $e) => sprintf('ID:%d | Code:%s | Nom:%s', $e->getId(), $e->getCode(), $e->getNom()), $allEntites);
        $prioritesList = array_map(fn(Priorite $p) => sprintf('ID:%d | Code:%s | Libelle:%s', $p->getId(), $p->getCode(), $p->getLibelle()), $allPriorites);

        $systemPrompt = "Tu es un assistant expert en gestion de projets et suivi opérationnel des instructions et Ordres de Service (OS) pour la Banque Centrale du Congo (BCC).\n"
            . "Ton rôle est de lire le document ou la description d'une instruction et de la décomposer en une liste d'ACTIONS OPÉRATIONNELLES concrètes, mesurables et directement assignables.\n\n"
            . "LISTE DES ENTITÉS DISPONIBLES DANS LE SYSTÈME :\n" . implode("\n", $entitesList) . "\n\n"
            . "LISTE DES PRIORITÉS DISPONIBLES :\n" . implode("\n", $prioritesList) . "\n\n"
            . "CONSIGNES DE MAPPING :\n"
            . "1. Pour chaque action, fournis un 'libelle' clair et précis (ex: 'Élaborer le projet de circulaire relative aux taux directeurs').\n"
            . "2. 'resultat_attendu' : décris le livrable concret ou la preuve attendue.\n"
            . "3. 'entite_id' : choisis l'ID de l'entité la plus pertinente parmi la liste ci-dessus. Si incertain, utilise l'ID de l'entité pilote (" . ($instruction->getEntitePilote()?->getId() ?? 1) . ").\n"
            . "4. 'priorite_id' : choisis l'ID de priorité adapté.\n"
            . "5. 'date_echeance' : format YYYY-MM-DD. Si l'instruction a une échéance (" . ($instruction->getDateEcheance()?->format('Y-m-d') ?? date('Y-m-d', strtotime('+30 days'))) . "), échelonne les actions avant cette date.\n\n"
            . "RÉPONDS STRICTEMENT SOUS FORME D'UN OBJET JSON valide avec la clé 'actions' contenant la liste des actions.";

        $userMessage = "Instruction de référence : " . $instruction->getReference() . "\n"
            . "Objet : " . $instruction->getObjet() . "\n\n";

        if (!empty($text)) {
            $userMessage .= "Texte extrait :\n" . $text . "\n\n";
        }

        if (!empty($customPrompt)) {
            $userMessage .= "Consignes particulières : " . $customPrompt . "\n";
        }

        $parts = [];
        $parts[] = ['text' => $userMessage];

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
                    ['text' => $systemPrompt]
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
        $content = trim($content);

        $parsed = json_decode($content, true);

        if (!isset($parsed['actions']) || !is_array($parsed['actions'])) {
            if (is_array($parsed) && isset($parsed[0])) {
                return $this->normalizeActions($parsed, $instruction, $allEntites, $allPriorites);
            }
            throw new \RuntimeException('Le format de réponse Gemini n\'a pas retourné de liste d\'actions.');
        }

        return $this->normalizeActions($parsed['actions'], $instruction, $allEntites, $allPriorites);
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
        array $allEntites,
        array $allPriorites
    ): array {
        $entitesList = array_map(fn(Entite $e) => sprintf('ID:%d | Code:%s | Nom:%s', $e->getId(), $e->getCode(), $e->getNom()), $allEntites);
        $prioritesList = array_map(fn(Priorite $p) => sprintf('ID:%d | Code:%s | Libelle:%s', $p->getId(), $p->getCode(), $p->getLibelle()), $allPriorites);

        $systemPrompt = "Tu es un assistant expert en gestion de projets et suivi opérationnel des instructions et Ordres de Service (OS) pour la Banque Centrale du Congo (BCC).\n"
            . "Ton rôle est de lire le document ou la description d'une instruction et de la décomposer en une liste d'ACTIONS OPÉRATIONNELLES concrètes, mesurables et directement assignables.\n\n"
            . "LISTE DES ENTITÉS DISPONIBLES DANS LE SYSTÈME :\n" . implode("\n", $entitesList) . "\n\n"
            . "LISTE DES PRIORITÉS DISPONIBLES :\n" . implode("\n", $prioritesList) . "\n\n"
            . "CONSIGNES DE MAPPING :\n"
            . "1. Pour chaque action, fournis un 'libelle' clair et précis.\n"
            . "2. 'resultat_attendu' : décris le livrable concret ou la preuve attendue.\n"
            . "3. 'entite_id' : choisis l'ID de l'entité la plus pertinente parmi la liste.\n"
            . "4. 'priorite_id' : choisis l'ID de priorité adapté.\n"
            . "5. 'date_echeance' : format YYYY-MM-DD.\n\n"
            . "RÉPONDS STRICTEMENT SOUS FORME D'UN OBJET JSON valide avec la clé 'actions' contenant la liste des actions.";

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
                ['role' => 'system', 'content' => $systemPrompt],
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
        $content = $data['choices'][0]['message']['content'] ?? '{}';
        $parsed = json_decode($content, true);

        return $this->normalizeActions($parsed['actions'] ?? [], $instruction, $allEntites, $allPriorites);
    }

    /**
     * Normalise et valide chaque action pour correspondre aux entités en BDD
     */
    private function normalizeActions(
        array $rawActions,
        Instruction $instruction,
        array $allEntites,
        array $allPriorites
    ): array {
        $validEntiteIds = array_map(fn(Entite $e) => $e->getId(), $allEntites);
        $validPrioriteIds = array_map(fn(Priorite $p) => $p->getId(), $allPriorites);
        $defaultEntiteId = $instruction->getEntitePilote()?->getId() ?? ($validEntiteIds[0] ?? 1);
        $defaultPrioriteId = $instruction->getPriorite()?->getId() ?? ($validPrioriteIds[0] ?? 1);
        $defaultDate = $instruction->getDateEcheance()?->format('Y-m-d') ?? date('Y-m-d', strtotime('+30 days'));

        $normalized = [];
        foreach ($rawActions as $act) {
            $libelle = trim($act['libelle'] ?? $act['titre'] ?? '');
            if (empty($libelle)) {
                continue;
            }

            $entiteId = isset($act['entite_id']) && in_array((int)$act['entite_id'], $validEntiteIds, true)
                ? (int)$act['entite_id']
                : $defaultEntiteId;

            $prioriteId = isset($act['priorite_id']) && in_array((int)$act['priorite_id'], $validPrioriteIds, true)
                ? (int)$act['priorite_id']
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
     * Générateur de repli intelligent (si clé API absente ou en démonstration)
     */
    private function generateFallbackActions(
        Instruction $instruction,
        ?string $customPrompt,
        array $allEntites,
        array $allPriorites,
        ?string $warningNotice = null
    ): array {
        $defaultEntiteId = $instruction->getEntitePilote()?->getId() ?? ($allEntites[0]?->getId() ?? 1);
        $defaultPrioriteId = $instruction->getPriorite()?->getId() ?? ($allPriorites[0]?->getId() ?? 1);
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
                'libelle' => sprintf('Mise en œuvre des directives relatives à : %s', substr($instruction->getObjet(), 0, 70)),
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

        return $actions;
    }
}
