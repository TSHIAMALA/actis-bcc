<?php

namespace App\Form;

use App\Entity\CanalNotification;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class CanalNotificationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Code unique (ex: EMAIL, SMS, SYSTEME)',
                'required' => true,
                'constraints' => [
                    new NotBlank(['message' => 'Le code est obligatoire.']),
                ],
                'attr' => [
                    'placeholder' => 'ex: WHATSAPP_API',
                    'style' => 'text-transform: uppercase;',
                ],
            ])
            ->add('libelle', TextType::class, [
                'label' => 'Libellé du canal',
                'required' => true,
                'constraints' => [
                    new NotBlank(['message' => 'Le libellé est obligatoire.']),
                ],
                'attr' => [
                    'placeholder' => 'ex: Notification WhatsApp Professionnelle',
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
                'label' => 'Canal actif',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CanalNotification::class,
        ]);
    }
}
