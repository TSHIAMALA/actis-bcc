<?php

namespace App\Form;

use App\Entity\Priorite;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class PrioriteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Code unique (ex: TRES_HAUTE, HAUTE, NORMALE, BASSE)',
                'required' => true,
                'constraints' => [
                    new NotBlank(['message' => 'Le code est obligatoire.']),
                ],
                'attr' => [
                    'placeholder' => 'ex: URGENTE',
                    'style' => 'text-transform: uppercase;',
                ],
            ])
            ->add('libelle', TextType::class, [
                'label' => 'Libellé de la priorité',
                'required' => true,
                'constraints' => [
                    new NotBlank(['message' => 'Le libellé est obligatoire.']),
                ],
                'attr' => [
                    'placeholder' => 'ex: Très Haute Priorité',
                ],
            ])
            ->add('niveau', IntegerType::class, [
                'label' => 'Niveau d\'importance numérique (ex: 4 = Très Haute, 1 = Basse)',
                'required' => true,
                'attr' => [
                    'min' => 1,
                    'max' => 10,
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
                'label' => 'Priorité active',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Priorite::class,
        ]);
    }
}
