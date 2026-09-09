<?php

namespace App\Form;

use App\Entity\Entite;
use App\Entity\TypeEntite;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EntiteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Sigle / Code',
                'attr' => ['placeholder' => 'Ex : DOPM, DSI, DRH, SM...'],
            ])
            ->add('nom', TextType::class, [
                'label' => 'Nom complet de l\'entité',
                'attr' => ['placeholder' => 'Direction des Systèmes d\'Information...'],
            ])
            ->add('typeEntite', EntityType::class, [
                'class' => TypeEntite::class,
                'choice_label' => 'libelle',
                'label' => 'Type d\'entité (Niveau hiérarchique)',
            ])
            ->add('parent', EntityType::class, [
                'class' => Entite::class,
                'choice_label' => 'nomComplet',
                'label' => 'Entité parente (Hiérarchie)',
                'placeholder' => '-- Aucune (Entité racine / Direction Générale) --',
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description / Missions',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('actif', CheckboxType::class, [
                'label' => 'Entité active',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Entite::class,
        ]);
    }
}
