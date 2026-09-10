<?php

namespace App\Form;

use App\Entity\TypeInstruction;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class TypeInstructionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Code unique (ex: ORDRE_SERVICE, INSTRUCTION)',
                'required' => true,
                'constraints' => [
                    new NotBlank(['message' => 'Le code est obligatoire.']),
                ],
                'attr' => [
                    'placeholder' => 'ex: LETTRE_CIRCULAIRE',
                    'style' => 'text-transform: uppercase;',
                ],
            ])
            ->add('libelle', TextType::class, [
                'label' => 'Libellé complet',
                'required' => true,
                'constraints' => [
                    new NotBlank(['message' => 'Le libellé est obligatoire.']),
                ],
                'attr' => [
                    'placeholder' => 'ex: Lettre Circulaire du Gouverneur',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description & Champ d\'application',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'Préciser la finalité et les règles applicables à ce type...',
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
                'label' => 'Type actif (disponible pour les nouvelles saisies)',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TypeInstruction::class,
        ]);
    }
}
