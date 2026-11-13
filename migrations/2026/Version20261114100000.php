<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Exposure: a finding copies the host of its subject and whether it is in production, for the context, the filters
 * and the production dashboards (filled again by the next refresh of each team).
 */
final class Version20261114100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Exposure: host and production of a finding';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exposure_finding ADD host_id UUID DEFAULT NULL, ADD host_name TEXT DEFAULT NULL, ADD production BOOLEAN DEFAULT false NOT NULL');
        $this->addSql("UPDATE exposure_finding SET host_id = subject_id, host_name = subject_name WHERE subject_type = 'host'");
        $this->addSql('CREATE INDEX exposure_finding_host ON exposure_finding (team_id, host_id)');
        $this->addSql('CREATE INDEX exposure_finding_production ON exposure_finding (team_id, production, status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX exposure_finding_production');
        $this->addSql('DROP INDEX exposure_finding_host');
        $this->addSql('ALTER TABLE exposure_finding DROP host_id, DROP host_name, DROP production');
    }
}
