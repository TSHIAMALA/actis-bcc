<?php

namespace App\Form;

use App\Entity\TypeEntite;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class TypeEntiteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Code unique (ex: GOUVERNANCE, DIRECTION, DEPARTEMENT, SERVICE)',
                'required' => true,
                'constraints' => [
                    new NotBlank(['message' => 'Le code est obligatoire.']),
                ],
                'attr' => [
                    'placeholder' => 'ex: DEPARTEMENT',
                    'style' => 'text-transform: uppercase;',
                ],
            ])
            ->add('libelle', TextType::class, [
                'label' => 'Libellé du type d\'entité',
                'required' => true,
                'constraints' => [
                    new NotBlank(['message' => 'Le libellé est obligatoire.']),
                ],
                'attr' => [
                    'placeholder' => 'ex: Département Opérationnel',
                ],
            ])
            ->add('niveau', IntegerType::class, [
                'label' => 'Niveau hiérarchique (0 = Haut / Direction Générale, 1 = Direction, 2 = Sous-direction...)',
                'required' => true,
                'attr' => [
                    'min' => 0,
                    'max' => 10,
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => [
                    'rows' => 3,
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
                'label' => 'Type d\'entité actif',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TypeEntite::class,
        ]);
    }
}
