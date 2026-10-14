<?php

namespace App\Form;

use App\Entity\Entite;
use App\Entity\Instruction;
use App\Entity\Priorite;
use App\Entity\Statut;
use App\Entity\TypeInstruction;
use App\Entity\Utilisateur;
use App\Repository\EntiteRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Url;

class InstructionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('reference', TextType::class, [
                'label' => 'Référence',
                'attr' => ['placeholder' => 'Ex: OS/DG/2026/015 (laisser vide pour auto-génération)'],
                'required' => false,
            ])
            ->add('objet', TextType::class, [
                'label' => 'Objet / Intitulé de l\'instruction',
                'attr' => ['placeholder' => 'Objet succinct et explicite...'],
            ])
            ->add('typeInstruction', EntityType::class, [
                'class' => TypeInstruction::class,
                'choice_label' => 'libelle',
                'label' => 'Type d\'instruction',
                'placeholder' => '-- Choisir un type --',
            ])
            ->add('priorite', EntityType::class, [
                'class' => Priorite::class,
                'choice_label' => 'libelle',
                'label' => 'Niveau de priorité',
            ])
            ->add('emetteur', TextType::class, [
                'label' => 'Émetteur',
                'data' => $options['data']?->getEmetteur() ?? 'Gouverneur de la Banque Centrale',
                'attr' => ['placeholder' => 'Gouverneur, Cabinet, etc.'],
                'required' => false,
            ])
            ->add('entitePilote', EntityType::class, [
                'class' => Entite::class,
                'query_builder' => fn (EntiteRepository $repo) => $repo->createChoicesQueryBuilder(($options['data'] ?? null)?->getEntitePilote()),
                'choice_label' => 'nomComplet',
                'group_by' => fn (Entite $e) => $e->getGroupeDirectionGenerale(),
                'label' => 'Entité pilote (Direction / Département responsable)',
                'placeholder' => '-- Sélectionner l\'entité pilote --',
            ])
            ->add('responsable', EntityType::class, [
                'class' => Utilisateur::class,
                'choice_label' => 'nomComplet',
                'label' => 'Responsable désigné (optionnel)',
                'placeholder' => '-- Aucun responsable direct désigné --',
                'required' => false,
            ])
            ->add('dateInstruction', DateType::class, [
                'widget' => 'single_text',
                'label' => 'Date de l\'instruction',
            ])
            ->add('dateReception', DateType::class, [
                'widget' => 'single_text',
                'label' => 'Date de réception',
                'required' => false,
            ])
            ->add('dateEcheance', DateType::class, [
                'widget' => 'single_text',
                'label' => 'Date d\'échéance globale',
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Contenu synthétique / Description détaillée',
                'attr' => ['rows' => 4, 'placeholder' => 'Détails, contexte, orientations spécifiques...'],
                'required' => false,
            ])
            ->add('documentPdf', FileType::class, [
                'label' => 'Document officiel (PDF)',
                'mapped' => false,
                'required' => false,
                'attr' => ['accept' => 'application/pdf,.pdf'],
                'constraints' => [
                    new File([
                        'maxSize' => '20M',
                        'mimeTypes' => ['application/pdf', 'application/x-pdf'],
                        'mimeTypesMessage' => 'Le document officiel doit être un fichier PDF.',
                        'maxSizeMessage' => 'Le PDF ne doit pas dépasser {{ limit }} {{ suffix }}.',
                    ]),
                ],
            ])
            ->add('lienPortail', UrlType::class, [
                'label' => 'Lien sur le portail BCC',
                'required' => false,
                'default_protocol' => 'https',
                'attr' => ['placeholder' => 'Coller ici l\'adresse de la publication sur le portail BCC'],
                'constraints' => [
                    new Url(['message' => 'Le lien du portail n\'est pas une adresse web valide.', 'requireTld' => false]),
                    new Length(['max' => 500]),
                ],
            ]);

        if ($options['is_edit']) {
            $builder->add('supprimerDocument', CheckboxType::class, [
                'label' => 'Supprimer le document actuel',
                'mapped' => false,
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Instruction::class,
            'is_edit' => false,
        ]);
        $resolver->setAllowedTypes('is_edit', 'bool');
    }
}
