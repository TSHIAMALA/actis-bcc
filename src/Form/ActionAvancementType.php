<?php

namespace App\Form;

use App\Entity\Action;
use App\Entity\Statut;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\RangeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ActionAvancementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('tauxAvancement', RangeType::class, [
                'label' => 'Taux d\'avancement (%)',
                'attr' => [
                    'min' => 0,
                    'max' => 100,
                    'step' => 5,
                    'class' => 'form-range',
                ],
            ])
            ->add('statut', EntityType::class, [
                'class' => Statut::class,
                'choice_label' => 'libelle',
                'label' => 'Statut opérationnel',
            ])
            ->add('dateRealisation', DateType::class, [
                'widget' => 'single_text',
                'label' => 'Date effective de réalisation',
                'required' => false,
            ])
            ->add('resultatObtenu', TextareaType::class, [
                'label' => 'Résultat obtenu / Compte-rendu d\'exécution',
                'attr' => ['rows' => 3, 'placeholder' => 'Préciser les résultats atteints, les constats ou les difficultés éventuelles...'],
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
