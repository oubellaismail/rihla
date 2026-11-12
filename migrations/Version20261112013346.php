<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112013346 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Luggage: trunk volume of vehicles and trips, bags of bookings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking ADD cabin_bags SMALLINT DEFAULT 0 NOT NULL, ADD medium_bags SMALLINT DEFAULT 0 NOT NULL, ADD large_bags SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE trip ADD trunk_litres SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE vehicle ADD trunk_litres SMALLINT DEFAULT 400 NOT NULL');
        // Existing trips keep the volume of their vehicle.
        $this->addSql('UPDATE trip t INNER JOIN vehicle v ON v.id = t.vehicle_id SET t.trunk_litres = v.trunk_litres');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking DROP cabin_bags, DROP medium_bags, DROP large_bags');
        $this->addSql('ALTER TABLE trip DROP trunk_litres');
        $this->addSql('ALTER TABLE vehicle DROP trunk_litres');
    }
}
