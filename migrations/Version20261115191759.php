<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115191759 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Trip changes: trip.delay_minutes, booking.cancellation_reason, trip_stop.removed_at (position becomes nullable)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking ADD cancellation_reason VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE trip ADD delay_minutes SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE trip_stop ADD removed_at DATETIME DEFAULT NULL, CHANGE position position SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking DROP cancellation_reason');
        $this->addSql('ALTER TABLE trip DROP delay_minutes');
        $this->addSql('ALTER TABLE trip_stop DROP removed_at, CHANGE position position SMALLINT NOT NULL');
    }
}
