<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Vulnerability: end of life cycles (endoflife.date), latest releases of direct dependencies (Packagist, npm), and
 * whether a watched package is a direct dependency of some team.
 */
final class Version20261112160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vulnerability: end of life cycles and latest releases';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vulnerability_watched_package ADD direct BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE vulnerability_watched_package ALTER direct DROP DEFAULT');
        $this->addSql(<<<'SQL'
            CREATE TABLE vulnerability_eol_cycle (
                id UUID NOT NULL,
                product VARCHAR(64) NOT NULL,
                cycle VARCHAR(64) NOT NULL,
                support_until DATE DEFAULT NULL,
                end_of_life DATE DEFAULT NULL,
                latest VARCHAR(64) DEFAULT NULL,
                synced_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX vulnerability_eol_cycle_key ON vulnerability_eol_cycle (product, cycle)');
        $this->addSql(<<<'SQL'
            CREATE TABLE vulnerability_latest_release (
                id UUID NOT NULL,
                ecosystem VARCHAR(64) NOT NULL,
                name TEXT NOT NULL,
                version VARCHAR(255) NOT NULL,
                checked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX vulnerability_latest_release_key ON vulnerability_latest_release (ecosystem, name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE vulnerability_latest_release');
        $this->addSql('DROP TABLE vulnerability_eol_cycle');
        $this->addSql('ALTER TABLE vulnerability_watched_package DROP direct');
    }
}
