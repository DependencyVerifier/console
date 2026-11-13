<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What a human says of the inventory: a display name and IP addresses for a host (Fleet, copied by Inventory), and
 * documentation links for an application.
 */
final class Version20261113140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fleet and Inventory: host labels, application documentation links';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE fleet_host ADD display_name VARCHAR(100) DEFAULT NULL, ADD addresses JSON DEFAULT '[]' NOT NULL");
        $this->addSql('CREATE TABLE inventory_host_label (host_id UUID NOT NULL, team_id UUID NOT NULL, display_name VARCHAR(100) DEFAULT NULL, addresses JSON NOT NULL, PRIMARY KEY (host_id))');
        $this->addSql('CREATE INDEX inventory_host_label_team ON inventory_host_label (team_id)');
        $this->addSql("ALTER TABLE inventory_app_instance ADD links JSON DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inventory_app_instance DROP links');
        $this->addSql('DROP TABLE inventory_host_label');
        $this->addSql('ALTER TABLE fleet_host DROP display_name, DROP addresses');
    }
}
