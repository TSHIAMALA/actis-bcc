<?php

namespace App\Form;

use App\Entity\ConseillerGouverneur;
use App\Entity\Instruction;
use App\Entity\Priorite;
use App\Entity\TypeInstruction;
use App\Repository\ConseillerGouverneurRepository;
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
            // Les entités ne se saisissent plus ici : elles se déduisent des actions de l'instruction
            ->add('responsables', EntityType::class, [
                'class' => ConseillerGouverneur::class,
                'query_builder' => fn (ConseillerGouverneurRepository $repo) => $repo->createChoicesQueryBuilder(
                    ($options['data'] ?? null)?->getResponsables()?->toArray() ?? []
                ),
                'choice_label' => fn (ConseillerGouverneur $c) => $c->getNom(),
                'choice_attr' => fn (ConseillerGouverneur $c) => ['data-domaine' => $c->getDomaineCompetence()],
                'label' => 'Conseillers responsables',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'by_reference' => false,
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
