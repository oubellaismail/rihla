<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Driver notifications: notification.trip_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification ADD trip_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAA5BC2E0E FOREIGN KEY (trip_id) REFERENCES trip (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_BF5476CAA5BC2E0E ON notification (trip_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CAA5BC2E0E');
        $this->addSql('DROP INDEX IDX_BF5476CAA5BC2E0E ON notification');
        $this->addSql('ALTER TABLE notification DROP trip_id');
    }
}
