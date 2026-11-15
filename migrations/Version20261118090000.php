<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Booking: trip_delay_at_booking (a delay only counts from the booking on)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking ADD trip_delay_at_booking SMALLINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking DROP trip_delay_at_booking');
    }
}
