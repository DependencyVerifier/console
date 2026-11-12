<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Exposure: where the subject of a finding is (name, environment, project), copied at each computation for the
 * dashboards, and the upgrade suggested for an end of life.
 */
final class Version20261112220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Exposure: placement of the findings, suggested upgrade';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE exposure_finding ADD suggested VARCHAR(64) DEFAULT NULL, ADD subject_name TEXT DEFAULT '' NOT NULL, ADD environment_id UUID DEFAULT NULL, ADD project_id UUID DEFAULT NULL");
        $this->addSql('ALTER TABLE exposure_finding ALTER subject_name DROP DEFAULT');
        $this->addSql('CREATE INDEX exposure_finding_project ON exposure_finding (team_id, project_id)');
        $this->addSql('CREATE INDEX exposure_finding_environment ON exposure_finding (team_id, environment_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX exposure_finding_environment');
        $this->addSql('DROP INDEX exposure_finding_project');
        $this->addSql('ALTER TABLE exposure_finding DROP suggested, DROP subject_name, DROP environment_id, DROP project_id');
    }
}
