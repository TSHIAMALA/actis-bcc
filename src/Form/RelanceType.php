<?php

namespace App\Form;

use App\Entity\Relance;
use App\Entity\Utilisateur;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RelanceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('destinataireUtilisateur', EntityType::class, [
                'class' => Utilisateur::class,
                'choice_label' => 'nomComplet',
                'label' => 'Destinataire',
            ])
            ->add('objet', TextType::class, [
                'label' => 'Objet de la relance',
                'attr' => ['placeholder' => 'Ex : Rappel d\'échéance - Instruction ...'],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Message de relance',
                'attr' => ['rows' => 4, 'placeholder' => 'Contenu du message de relance...'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Relance::class,
        ]);
    }
}
