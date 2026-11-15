<?php

namespace App\Controller\Admin;

use App\Entity\City;
use App\Entity\TripStop;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use Symfony\Component\HttpFoundation\Response;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;

class CityCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return City::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Ville')
            ->setEntityLabelInPlural('Villes')
            ->setDefaultSort(['name' => 'ASC']);
    }

    /** A city used by a trip stop cannot be deleted: go back to the list with an explanation instead of a crash. */
    public function delete(AdminContext $context): KeyValueStore|Response
    {
        $city = $context->getEntity()->getInstance();
        if ($city instanceof City && $this->container->get('doctrine')->getManager()->getRepository(TripStop::class)->count(['city' => $city]) > 0) {
            $this->addFlash('danger', 'Cette ville est utilisée par des trajets : elle ne peut pas être supprimée.');

            return $this->redirect($this->container->get(AdminUrlGeneratorInterface::class)
                ->setController(self::class)->setAction(Action::INDEX)->unset('entityId')->generateUrl());
        }

        return parent::delete($context);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name', 'Nom');
        yield NumberField::new('latitude')->setNumDecimals(4);
        yield NumberField::new('longitude')->setNumDecimals(4);
    }
}
