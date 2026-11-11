<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The agent that last reported an application: several agents may share a host (one per Compose project), and only
 * the one that reported an application makes it `gone` by no longer reporting it.
 */
final class Version20261111180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reporting agent of an application';
    }

    public function up(Schema $schema): void
    {
        // No application exists before the processing of this step: the table is empty.
        $this->addSql('DELETE FROM inventory_app_instance');
        $this->addSql('ALTER TABLE inventory_app_instance ADD agent_id UUID NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inventory_app_instance DROP agent_id');
    }
}
