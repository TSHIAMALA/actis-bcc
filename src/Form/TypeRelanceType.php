<?php

namespace App\Form;

use App\Entity\TypeRelance;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class TypeRelanceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Code unique (ex: AVANT_ECHEANCE_J7, JOUR_J, RETARD_J3, MANUELLE)',
                'required' => true,
                'constraints' => [
                    new NotBlank(['message' => 'Le code est obligatoire.']),
                ],
                'attr' => [
                    'placeholder' => 'ex: RETARD_J15',
                    'style' => 'text-transform: uppercase;',
                ],
            ])
            ->add('libelle', TextType::class, [
                'label' => 'Libellé du type de relance',
                'required' => true,
                'constraints' => [
                    new NotBlank(['message' => 'Le libellé est obligatoire.']),
                ],
                'attr' => [
                    'placeholder' => 'ex: Alerte Retard Critique (+15 jours)',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description & Déclencheur',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'Description du moment où cette relance est envoyée...',
                ],
            ])
            ->add('ordreAffichage', IntegerType::class, [
                'label' => 'Ordre d\'affichage',
                'required' => false,
                'attr' => [
                    'min' => 0,
                ],
            ])
            ->add('actif', CheckboxType::class, [
                'label' => 'Type de relance actif',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TypeRelance::class,
        ]);
    }
}
