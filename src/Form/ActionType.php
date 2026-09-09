<?php

namespace App\Form;

use App\Entity\Action;
use App\Entity\Entite;
use App\Entity\Priorite;
use App\Entity\Statut;
use App\Entity\Utilisateur;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ActionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('libelle', TextType::class, [
                'label' => 'Libellé de l\'action',
                'attr' => ['placeholder' => 'Ex: Élaborer le rapport trimestriel, Collecter les données...'],
            ])
            ->add('reference', TextType::class, [
                'label' => 'Référence (optionnel)',
                'required' => false,
                'attr' => ['placeholder' => 'Ex: ACT-01'],
            ])
            ->add('entiteResponsable', EntityType::class, [
                'class' => Entite::class,
                'choice_label' => 'nomComplet',
                'label' => 'Entité responsable de l\'action',
            ])
            ->add('responsable', EntityType::class, [
                'class' => Utilisateur::class,
                'choice_label' => 'nomComplet',
                'label' => 'Agent / Responsable désigné',
                'placeholder' => '-- Choisir un responsable --',
                'required' => false,
            ])
            ->add('priorite', EntityType::class, [
                'class' => Priorite::class,
                'choice_label' => 'libelle',
                'label' => 'Priorité',
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
                'label' => 'Description / Consignes',
                'attr' => ['rows' => 3, 'placeholder' => 'Détails des tâches et méthodologie...'],
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Action::class,
        ]);
    }
}
