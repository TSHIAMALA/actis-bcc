<?php

namespace App\Controller\Api;

use App\Entity\Role;
use App\Entity\Utilisateur;
use App\Repository\EntiteRepository;
use App\Repository\RoleRepository;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/point-focal')]
class PointFocalApiController extends AbstractController
{
    #[Route('/quick-create', name: 'app_api_point_focal_quick_create', methods: ['POST'])]
    public function quickCreate(
        Request $request,
        EntityManagerInterface $em,
        EntiteRepository $entiteRepo,
        RoleRepository $roleRepo,
        UtilisateurRepository $userRepo,
        UserPasswordHasherInterface $hasher
    ): JsonResponse {
        // Retrieve JSON or Form Data
        $data = json_decode($request->getContent(), true) ?? $request->request->all();

        $nom = trim($data['nom'] ?? '');
        $prenom = trim($data['prenom'] ?? '');
        $postnom = trim($data['postnom'] ?? '');
        $email = trim($data['email'] ?? '');
        $telephone = trim($data['telephone'] ?? '');
        $matricule = trim($data['matricule'] ?? '');
        $entiteId = $data['entite_id'] ?? null;

        // Validation
        if (empty($nom) || empty($prenom)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Le nom et le prénom sont obligatoires.'
            ], Response::HTTP_BAD_REQUEST);
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Veuillez renseigner une adresse email valide.'
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!$entiteId) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Veuillez sélectionner une entité / direction de rattachement.'
            ], Response::HTTP_BAD_REQUEST);
        }

        $entite = $entiteRepo->find($entiteId);
        if (!$entite) {
            return new JsonResponse([
                'success' => false,
                'message' => 'L\'entité sélectionnée est introuvable.'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Check if email already exists
        $existingUserByEmail = $userRepo->findOneBy(['email' => $email]);
        if ($existingUserByEmail) {
            return new JsonResponse([
                'success' => false,
                'message' => sprintf('Un utilisateur avec l\'adresse email "%s" existe déjà.', $email)
            ], Response::HTTP_CONFLICT);
        }

        // Auto-generate matricule if empty or check uniqueness
        if (empty($matricule)) {
            $year = (new \DateTime())->format('Y');
            $count = $userRepo->count([]) + 1;
            $matricule = sprintf('PF-%s-%03d', $year, $count);

            // Ensure unique
            while ($userRepo->findOneBy(['matricule' => $matricule])) {
                $count++;
                $matricule = sprintf('PF-%s-%03d', $year, $count);
            }
        } else {
            $existingUserByMatricule = $userRepo->findOneBy(['matricule' => $matricule]);
            if ($existingUserByMatricule) {
                return new JsonResponse([
                    'success' => false,
                    'message' => sprintf('Le matricule "%s" est déjà attribué.', $matricule)
                ], Response::HTTP_CONFLICT);
            }
        }

        // Create new Utilisateur
        $user = new Utilisateur();
        $user->setNom(strtoupper($nom));
        $user->setPrenom(ucfirst($prenom));
        if (!empty($postnom)) {
            $user->setPostnom(ucfirst($postnom));
        }
        $user->setEmail(strtolower($email));
        $user->setTelephone(!empty($telephone) ? $telephone : null);
        $user->setMatricule($matricule);
        $user->setEntite($entite);
        $user->setActif(true);

        // Assign RESPONSABLE role by default
        $roleResponsable = $roleRepo->findOneBy(['code' => Role::CODE_RESPONSABLE]);
        if ($roleResponsable) {
            $user->addAppRole($roleResponsable);
        }

        // Default secure password
        $defaultPassword = 'Bcc@' . (new \DateTime())->format('Y') . '!';
        $user->setMotDePasseHash($hasher->hashPassword($user, $defaultPassword));

        $em->persist($user);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => sprintf('Le point focal %s a été créé avec succès.', $user->getNomComplet()),
            'user' => [
                'id' => $user->getId(),
                'nomComplet' => $user->getNomComplet(),
                'email' => $user->getEmail(),
                'matricule' => $user->getMatricule(),
                'entiteId' => $entite->getId(),
                'entiteNom' => $entite->getCode(),
            ]
        ], Response::HTTP_CREATED);
    }
}
