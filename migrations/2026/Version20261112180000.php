<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Exposure: the findings of each team, and whether its findings are to be computed again.
 */
final class Version20261112180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Exposure: findings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE exposure_finding (
                id UUID NOT NULL,
                team_id UUID NOT NULL,
                subject_type VARCHAR(16) NOT NULL,
                subject_id UUID NOT NULL,
                kind VARCHAR(16) NOT NULL,
                finding_key TEXT NOT NULL,
                title TEXT NOT NULL,
                package TEXT NOT NULL,
                installed VARCHAR(255) DEFAULT NULL,
                fixed VARCHAR(255) DEFAULT NULL,
                severity VARCHAR(16) DEFAULT NULL,
                cvss DOUBLE PRECISION DEFAULT NULL,
                known_exploited BOOLEAN NOT NULL,
                epss DOUBLE PRECISION DEFAULT NULL,
                eol_level VARCHAR(16) DEFAULT NULL,
                end_of_life DATE DEFAULT NULL,
                latest VARCHAR(255) DEFAULT NULL,
                aliases JSON NOT NULL,
                status VARCHAR(16) NOT NULL,
                first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                fixed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX exposure_finding_identity ON exposure_finding (team_id, subject_type, subject_id, kind, finding_key)');
        $this->addSql('CREATE INDEX exposure_finding_team_status ON exposure_finding (team_id, status)');
        $this->addSql('CREATE TABLE exposure_team (team_id UUID NOT NULL, refresh_requested BOOLEAN NOT NULL, refreshed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (team_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE exposure_team');
        $this->addSql('DROP TABLE exposure_finding');
    }
}
