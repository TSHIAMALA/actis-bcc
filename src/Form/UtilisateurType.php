<?php

namespace App\Form;

use App\Entity\Entite;
use App\Entity\Role;
use App\Entity\Utilisateur;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UtilisateurType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isEdit = $options['is_edit'] ?? false;

        $builder
            ->add('matricule', TextType::class, [
                'label' => 'Matricule BCC',
                'attr' => ['placeholder' => 'Ex : ACTIS008'],
            ])
            ->add('nom', TextType::class, [
                'label' => 'Nom',
            ])
            ->add('postnom', TextType::class, [
                'label' => 'Post-nom',
                'required' => false,
            ])
            ->add('prenom', TextType::class, [
                'label' => 'Prénom',
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse Email professionnelle',
                'attr' => ['placeholder' => 'prenom.nom@bcc.local'],
            ])
            ->add('telephone', TextType::class, [
                'label' => 'Téléphone',
                'required' => false,
            ])
            ->add('entite', EntityType::class, [
                'class' => Entite::class,
                'choice_label' => 'nomComplet',
                'label' => 'Entité de rattachement',
            ])
            ->add('appRoles', EntityType::class, [
                'class' => Role::class,
                'choice_label' => 'libelle',
                'label' => 'Profils / Rôles applicatifs',
                'multiple' => true,
                'expanded' => true,
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => $isEdit ? 'Nouveau mot de passe (laisser vide pour ne pas changer)' : 'Mot de passe initial',
                'mapped' => false,
                'required' => !$isEdit,
                'attr' => ['placeholder' => $isEdit ? 'Optionnel' : 'Mot de passe'],
            ])
            ->add('actif', CheckboxType::class, [
                'label' => 'Compte actif',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Utilisateur::class,
            'is_edit' => false,
        ]);
    }
}
