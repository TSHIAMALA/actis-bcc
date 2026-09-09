<?php

namespace App\Form;

use App\Entity\Entite;
use App\Entity\Instruction;
use App\Entity\Priorite;
use App\Entity\Statut;
use App\Entity\TypeInstruction;
use App\Entity\Utilisateur;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

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
                'choice_label' => 'nomComplet',
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
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Instruction::class,
        ]);
    }
}
