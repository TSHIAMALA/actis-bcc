<?php

namespace App\Form;

use App\Entity\Prorogation;
use App\Entity\StatutProrogation;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProrogationDecisionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('statutProrogation', EntityType::class, [
                'class' => StatutProrogation::class,
                'choice_label' => 'libelle',
                'label' => 'Décision',
            ])
            ->add('commentaireValidation', TextareaType::class, [
                'label' => 'Commentaire ou motivation de la décision',
                'attr' => ['rows' => 3, 'placeholder' => 'Commentaire transmis au demandeur...'],
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Prorogation::class,
        ]);
    }
}
