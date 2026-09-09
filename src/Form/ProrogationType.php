<?php

namespace App\Form;

use App\Entity\Prorogation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProrogationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nouvelleEcheance', DateType::class, [
                'widget' => 'single_text',
                'label' => 'Nouvelle date d\'échéance souhaitée',
            ])
            ->add('motif', TextareaType::class, [
                'label' => 'Motif détaillé de la demande de prorogation',
                'attr' => ['rows' => 4, 'placeholder' => 'Justifier les raisons du report : contraintes techniques, dépendances externes, etc.'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Prorogation::class,
        ]);
    }
}
