<?php

namespace App\Form;

use App\Entity\Action;
use App\Entity\Entite;
use App\Entity\Instruction;
use App\Entity\Priorite;
use App\Entity\Utilisateur;
use App\Repository\InstructionRepository;
use App\Repository\UtilisateurRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class ActionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['include_instruction']) {
            $builder->add('instruction', EntityType::class, [
                'class' => Instruction::class,
                'choice_label' => fn(Instruction $i) => $i->getReference() . ' — ' . (mb_strlen($i->getObjet()) > 65 ? mb_substr($i->getObjet(), 0, 65) . '...' : $i->getObjet()),
                'label' => 'Instruction Parente / Ordre du Gouverneur',
                'placeholder' => '-- Choisir l\'instruction de rattachement --',
                'required' => true,
                'constraints' => [
                    new NotBlank(['message' => 'Veuillez sélectionner l\'instruction de rattachement.']),
                ],
                'query_builder' => fn(InstructionRepository $repo) => $repo->createQueryBuilder('i')
                    ->where('i.deletedAt IS NULL')
                    ->orderBy('i.createdAt', 'DESC'),
            ]);
        }

        $builder
            ->add('libelle', TextType::class, [
                'label' => 'Libellé de l\'action',
                'constraints' => [
                    new NotBlank(['message' => 'Le libellé de l\'action est obligatoire.']),
                ],
                'attr' => ['placeholder' => 'Ex: Élaborer le rapport trimestriel, Collecter les données...'],
            ])
            ->add('reference', TextType::class, [
                'label' => 'Référence (optionnel - générée auto si vide)',
                'required' => false,
                'attr' => ['placeholder' => 'Ex: ACT-01 (laisser vide pour référence auto)'],
            ])
            ->add('entiteResponsable', EntityType::class, [
                'class' => Entite::class,
                'choice_label' => 'nomComplet',
                'label' => 'Entité responsable de l\'action',
            ])
            ->add('responsable', EntityType::class, [
                'class' => Utilisateur::class,
                'choice_label' => 'nomComplet',
                'group_by' => fn(?Utilisateur $u) => $u && $u->getEntite() ? $u->getEntite()->getCode() : 'Autres Directions',
                'choice_attr' => fn(?Utilisateur $u) => [
                    'data-entite-id' => $u && $u->getEntite() ? (string) $u->getEntite()->getId() : '',
                    'data-email' => $u ? (string) $u->getEmail() : '',
                    'data-matricule' => $u ? (string) $u->getMatricule() : '',
                ],
                'query_builder' => fn(UtilisateurRepository $repo) => $repo->createQueryBuilder('u')
                    ->leftJoin('u.entite', 'e')
                    ->where('u.actif = true')
                    ->orderBy('e.nom', 'ASC')
                    ->addOrderBy('u.nom', 'ASC'),
                'label' => 'Point Focal / Agent désigné',
                'placeholder' => '-- Choisir un point focal --',
                'required' => false,
            ])
            ->add('priorite', EntityType::class, [
                'class' => Priorite::class,
                'choice_label' => 'libelle',
                'label' => 'Niveau de Priorité',
                'placeholder' => '-- Même priorité que l\'instruction --',
                'required' => false,
            ])
            ->add('dateDebut', DateType::class, [
                'widget' => 'single_text',
                'label' => 'Date de début',
                'required' => false,
            ])
            ->add('dateEcheance', DateType::class, [
                'widget' => 'single_text',
                'label' => 'Date d\'échéance',
                'required' => false,
            ])
            ->add('resultatAttendu', TextareaType::class, [
                'label' => 'Résultat attendu / Livrable',
                'attr' => ['rows' => 3, 'placeholder' => 'Livrable concret attendu à l\'issue de cette action...'],
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description / Consignes de travail',
                'attr' => ['rows' => 3, 'placeholder' => 'Détails des tâches et méthodologie...'],
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Action::class,
            'include_instruction' => false,
        ]);
    }
}
