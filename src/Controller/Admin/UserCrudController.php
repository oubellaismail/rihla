<?php

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;

class UserCrudController extends AbstractCrudController
{
    public function __construct(private readonly Security $security)
    {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Utilisateur')
            ->setEntityLabelInPlural('Utilisateurs')
            ->overrideTemplate('crud/edit', 'admin/user_edit.html.twig')
            ->setDefaultSort(['lastName' => 'ASC']);
    }

    /** Accounts are created by sign-up; the back-office only edits them (roles), never deletes. */
    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE);
    }

    /** An admin cannot remove ROLE_ADMIN from their own account. */
    public function keepOwnAdminRole(?array $roles, ExecutionContextInterface $context): void
    {
        $edited = $context->getRoot()->getData();
        if ($edited instanceof User && $edited === $this->security->getUser() && !\in_array('ROLE_ADMIN', $roles ?? [], true)) {
            $context->addViolation("Vous ne pouvez pas retirer votre propre rôle d'administrateur.");
        }
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('firstName', 'Prénom');
        yield TextField::new('lastName', 'Nom');
        yield EmailField::new('email', 'E-mail');
        yield TelephoneField::new('phone', 'Téléphone');
        yield ArrayField::new('roles', 'Rôle')
            ->hideOnForm()
            ->setTemplatePath('admin/field/formatted.html.twig')
            ->formatValue(static fn ($value, User $u) => \in_array('ROLE_ADMIN', $u->getRoles(), true) ? 'Administrateur' : 'Utilisateur');
        // The form works on the choosable roles only (getRoles() always adds ROLE_USER); other stored roles are kept.
        yield ChoiceField::new('roles', 'Rôles')
            ->onlyOnForms()
            ->setChoices(['Administrateur' => 'ROLE_ADMIN'])
            ->allowMultipleChoices()
            ->renderExpanded()
            ->setRequired(false)
            ->setFormTypeOptions([
                'getter' => static fn (User $u): array => array_values(array_intersect($u->getRoles(), ['ROLE_ADMIN'])),
                'setter' => function (User $u, ?array $roles): void {
                    if ($u === $this->security->getUser() && !\in_array('ROLE_ADMIN', $roles ?? [], true)) {
                        return; // refused by the constraint below; never touch the logged-in admin's own role
                    }
                    $kept = array_diff($u->getRoles(), ['ROLE_USER', 'ROLE_ADMIN']);
                    $u->setRoles(array_values(array_merge($kept, $roles ?? [])));
                },
                'constraints' => [new Callback($this->keepOwnAdminRole(...))],
            ]);
        yield DateTimeField::new('createdAt', 'Inscrit le')->hideOnForm();
    }
}
