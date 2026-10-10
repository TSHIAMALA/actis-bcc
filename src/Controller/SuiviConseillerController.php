<?php

namespace App\Controller;

use App\Entity\Action;
use App\Entity\ConseillerGouverneur;
use App\Entity\SuiviConseiller;
use App\Entity\Utilisateur;
use App\Repository\ActionRepository;
use App\Repository\ConseillerGouverneurRepository;
use App\Repository\SuiviConseillerRepository;
use App\Repository\UtilisateurRepository;
use App\Service\InstructionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/suivi-conseillers')]
#[IsGranted('ROLE_USER')]
class SuiviConseillerController extends AbstractController
{
    #[Route('', name: 'app_suivi_conseiller_index', methods: ['GET'])]
    public function index(
        Request $request,
        SuiviConseillerRepository $suiviRepo,
        ConseillerGouverneurRepository $conseillerRepo
    ): Response {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        $isConseiller = $user ? $conseillerRepo->findByUtilisateur($user) : null;
        
        $filters = [
            'statut_suivi' => $request->query->get('statut_suivi', ''),
            'priorite_cabinet' => $request->query->get('priorite_cabinet', ''),
            'conseiller_id' => $request->query->get('conseiller_id', ''),
            'domaine' => $request->query->get('domaine', ''),
            'q' => $request->query->get('q', ''),
            'vue' => $request->query->get('vue', ($isConseiller && !$this->isGranted('ROLE_CABINET') && !$this->isGranted('ROLE_ADMIN')) ? 'mes_suivis' : 'tous'),
        ];

        $conseillerFiltre = null;
        if ($filters['vue'] === 'mes_suivis' && $isConseiller) {
            $conseillerFiltre = $isConseiller;
        }

        $suivis = $suiviRepo->searchAndFilter($filters, $conseillerFiltre);
        $conseillers = $conseillerRepo->findActifs();
        $statsCabinet = $suiviRepo->getGlobalCabinetStats();
        $statsParConseiller = $conseillerRepo->getStatsConseillers();

        return $this->render('suivi_conseiller/index.html.twig', [
            'suivis' => $suivis,
            'conseillers' => $conseillers,
            'statsCabinet' => $statsCabinet,
            'statsParConseiller' => $statsParConseiller,
            'monProfilConseiller' => $isConseiller,
            'filters' => $filters,
            'statutsSuivi' => SuiviConseiller::STATUTS,
        ]);
    }

    #[Route('/examen/{id}', name: 'app_suivi_conseiller_examen', methods: ['GET', 'POST'])]
    public function examen(
        SuiviConseiller $suivi,
        Request $request,
        EntityManagerInterface $em,
        InstructionService $instructionService,
        ConseillerGouverneurRepository $conseillerRepo
    ): Response {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        $monProfilConseiller = $user ? $conseillerRepo->findByUtilisateur($user) : null;

        // Permissions: L'utilisateur doit être le conseiller assigné, un membre du Cabinet, ou un Admin
        $canEdit = $this->isGranted('ROLE_ADMIN') || 
                   $this->isGranted('ROLE_CABINET') || 
                   ($monProfilConseiller && $suivi->getConseiller()->getId() === $monProfilConseiller->getId());

        if ($request->isMethod('POST')) {
            if (!$canEdit) {
                $this->addFlash('danger', 'Vous n\'êtes pas autorisé à évaluer cette action.');
                return $this->redirectToRoute('app_suivi_conseiller_index');
            }

            $statutSuivi = $request->request->get('statut_suivi');
            $avis = $request->request->get('avis');
            $recommandations = $request->request->get('recommandations');
            $pointAttention = $request->request->get('point_attention');
            $prioriteCabinet = $request->request->get('priorite_cabinet', SuiviConseiller::PRIORITE_NORMALE);

            if (in_array($statutSuivi, array_keys(SuiviConseiller::STATUTS))) {
                $suivi->setStatutSuivi($statutSuivi);
            }
            $suivi->setAvis($avis);
            $suivi->setRecommandations($recommandations);
            $suivi->setPointAttention($pointAttention);
            $suivi->setPrioriteCabinet($prioriteCabinet);
            $suivi->setDateAvis(new \DateTime());
            $suivi->setUpdatedAt(new \DateTime());

            $em->flush();

            // Journaliser dans l'historique
            $instructionService->logHistorique(
                $suivi->getAction(),
                'MODIFICATION',
                $user,
                null,
                null,
                sprintf(
                    'Avis du Conseiller (%s) enregistré : [%s] %s',
                    $suivi->getConseiller()->getDomaineCompetence(),
                    $suivi->getStatutLibelle(),
                    mb_substr($avis ?? '', 0, 100)
                )
            );

            if ($request->isXmlHttpRequest()) {
                return new JsonResponse([
                    'success' => true,
                    'message' => 'Avis du conseiller enregistré avec succès.',
                    'statut' => $suivi->getStatutSuivi(),
                    'statut_libelle' => $suivi->getStatutLibelle(),
                    'badge_class' => $suivi->getStatutBadgeClass(),
                ]);
            }

            $this->addFlash('success', 'Avis et analyse du conseiller enregistrés avec succès.');
            
            $redirect = $request->request->get('redirect_to');
            if ($redirect === 'instruction') {
                return $this->redirectToRoute('app_instruction_show', ['id' => $suivi->getAction()->getInstruction()->getId()]);
            }

            return $this->redirectToRoute('app_suivi_conseiller_index');
        }

        return $this->render('suivi_conseiller/examen.html.twig', [
            'suivi' => $suivi,
            'action' => $suivi->getAction(),
            'instruction' => $suivi->getAction()->getInstruction(),
            'canEdit' => $canEdit,
            'statutsSuivi' => SuiviConseiller::STATUTS,
        ]);
    }

    #[Route('/affecter/{id}', name: 'app_suivi_conseiller_affecter', methods: ['POST'])]
    public function affecter(
        Action $action,
        Request $request,
        EntityManagerInterface $em,
        ConseillerGouverneurRepository $conseillerRepo,
        SuiviConseillerRepository $suiviRepo,
        InstructionService $instructionService
    ): Response {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        $conseillerId = $request->request->get('conseiller_id');
        $priorite = $request->request->get('priorite_cabinet', SuiviConseiller::PRIORITE_NORMALE);
        $pointAttention = $request->request->get('point_attention');

        $conseiller = $conseillerRepo->find($conseillerId);
        if (!$conseiller || !$conseiller->isActif()) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => 'Conseiller introuvable ou inactif.'], 400);
            }
            $this->addFlash('danger', 'Conseiller introuvable ou inactif.');
            return $this->redirectToRoute('app_instruction_show', ['id' => $action->getInstruction()->getId()]);
        }

        // Vérifier si déjà affecté
        $existing = $suiviRepo->findOneByActionAndConseiller($action, $conseiller);
        if ($existing) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => 'Ce conseiller est déjà affecté à cette action.'], 400);
            }
            $this->addFlash('warning', 'Ce conseiller est déjà affecté à cette action.');
            return $this->redirectToRoute('app_instruction_show', ['id' => $action->getInstruction()->getId()]);
        }

        $suivi = new SuiviConseiller();
        $suivi->setAction($action);
        $suivi->setConseiller($conseiller);
        $suivi->setStatutSuivi(SuiviConseiller::STATUT_EN_ATTENTE);
        $suivi->setPrioriteCabinet($priorite);
        $suivi->setPointAttention($pointAttention);
        $suivi->setAffectePar($user);

        $em->persist($suivi);
        $em->flush();

        $instructionService->logHistorique(
            $action,
            'AFFECTATION',
            $user,
            null,
            null,
            sprintf('Action assignée pour suivi au Conseiller %s (%s)', $conseiller->getUtilisateur()?->getNomComplet() ?? '', $conseiller->getDomaineCompetence())
        );

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'success' => true,
                'message' => 'Conseiller assigné avec succès.',
                'suivi_id' => $suivi->getId(),
                'conseiller_nom' => $conseiller->getUtilisateur()?->getNomComplet(),
                'domaine' => $conseiller->getDomaineCompetence(),
            ]);
        }

        $this->addFlash('success', sprintf('Le Conseiller (%s) a été affecté à l\'action.', $conseiller->getDomaineCompetence()));
        return $this->redirectToRoute('app_instruction_show', ['id' => $action->getInstruction()->getId()]);
    }

    #[Route('/retirer/{id}', name: 'app_suivi_conseiller_retirer', methods: ['POST'])]
    public function retirer(
        SuiviConseiller $suivi,
        Request $request,
        EntityManagerInterface $em,
        InstructionService $instructionService
    ): Response {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        $action = $suivi->getAction();
        $instructionId = $action->getInstruction()->getId();
        $conseillerNom = $suivi->getConseiller()->getDomaineCompetence();

        $instructionService->logHistorique(
            $action,
            'SUPPRESSION',
            $user,
            null,
            null,
            sprintf('Retrait du suivi par le Conseiller (%s)', $conseillerNom)
        );

        $em->remove($suivi);
        $em->flush();

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => true, 'message' => 'Conseiller retiré avec succès.']);
        }

        $this->addFlash('info', 'Suivi du conseiller retiré de l\'action.');
        return $this->redirectToRoute('app_instruction_show', ['id' => $instructionId]);
    }

    #[Route('/admin/conseillers', name: 'app_suivi_conseiller_admin', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function admin(
        Request $request,
        ConseillerGouverneurRepository $conseillerRepo,
        UtilisateurRepository $userRepo,
        EntityManagerInterface $em
    ): Response {
        if ($request->isMethod('POST')) {
            $actionType = $request->request->get('action_type', 'add');

            if ($actionType === 'add') {
                $userId = $request->request->get('utilisateur_id');
                $domaine = $request->request->get('domaine_competence');
                $codeDomaine = $request->request->get('code_domaine');
                $description = $request->request->get('description');
                $ordre = (int)$request->request->get('ordre_affichage', 0);

                $user = $userRepo->find($userId);
                if ($user && $domaine && $codeDomaine) {
                    $existing = $conseillerRepo->findByUtilisateur($user);
                    if ($existing) {
                        $this->addFlash('danger', 'Cet utilisateur est déjà configuré comme conseiller.');
                    } else {
                        $conseiller = new ConseillerGouverneur();
                        $conseiller->setUtilisateur($user);
                        $conseiller->setDomaineCompetence($domaine);
                        $conseiller->setCodeDomaine(strtoupper(trim($codeDomaine)));
                        $conseiller->setDescription($description);
                        $conseiller->setOrdreAffichage($ordre);
                        $conseiller->setActif(true);

                        $em->persist($conseiller);
                        $em->flush();
                        $this->addFlash('success', 'Nouveau Conseiller du Gouverneur configuré.');
                    }
                }
            } elseif ($actionType === 'edit') {
                $id = $request->request->get('conseiller_id');
                $conseiller = $conseillerRepo->find($id);
                if ($conseiller) {
                    $conseiller->setDomaineCompetence($request->request->get('domaine_competence'));
                    $conseiller->setCodeDomaine(strtoupper(trim($request->request->get('code_domaine'))));
                    $conseiller->setDescription($request->request->get('description'));
                    $conseiller->setOrdreAffichage((int)$request->request->get('ordre_affichage', 0));
                    $conseiller->setUpdatedAt(new \DateTime());
                    $em->flush();
                    $this->addFlash('success', 'Fiche conseiller mise à jour.');
                }
            }

            return $this->redirectToRoute('app_suivi_conseiller_admin');
        }

        $conseillers = $conseillerRepo->findBy([], ['ordreAffichage' => 'ASC', 'domaineCompetence' => 'ASC']);
        $users = $userRepo->findBy(['actif' => true], ['nom' => 'ASC']);

        return $this->render('suivi_conseiller/admin.html.twig', [
            'conseillers' => $conseillers,
            'users' => $users,
        ]);
    }

    #[Route('/admin/conseillers/toggle/{id}', name: 'app_suivi_conseiller_admin_toggle', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggleActif(ConseillerGouverneur $conseiller, EntityManagerInterface $em): Response
    {
        $conseiller->setActif(!$conseiller->isActif());
        $conseiller->setUpdatedAt(new \DateTime());
        $em->flush();

        $this->addFlash('info', sprintf('Statut du conseiller %s modifié.', $conseiller->getDomaineCompetence()));
        return $this->redirectToRoute('app_suivi_conseiller_admin');
    }
}
