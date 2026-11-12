<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Vulnerability: the advisories of the watched packages (from osv.dev), their package index, and the exploitation
 * signals keyed by CVE (CISA KEV, FIRST EPSS). The index and the signals are raw SQL tables (schema_filter).
 */
final class Version20261112100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vulnerability: advisories, watched packages, KEV and EPSS';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE vulnerability_advisory (
                id UUID NOT NULL,
                osv_id VARCHAR(255) NOT NULL,
                aliases JSON NOT NULL,
                summary TEXT DEFAULT NULL,
                details TEXT DEFAULT NULL,
                published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                modified_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                withdrawn_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                cvss_vector VARCHAR(255) DEFAULT NULL,
                cvss_score DOUBLE PRECISION DEFAULT NULL,
                severity VARCHAR(16) DEFAULT NULL,
                affected JSON NOT NULL,
                reference_urls JSON NOT NULL,
                synced_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX vulnerability_advisory_osv_id ON vulnerability_advisory (osv_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE vulnerability_advisory_package (
                ecosystem VARCHAR(64) NOT NULL,
                name TEXT NOT NULL,
                osv_id VARCHAR(255) NOT NULL,
                PRIMARY KEY (ecosystem, name, osv_id)
            )
            SQL);
        $this->addSql('CREATE INDEX vulnerability_advisory_package_osv_id ON vulnerability_advisory_package (osv_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE vulnerability_watched_package (
                id UUID NOT NULL,
                ecosystem VARCHAR(64) NOT NULL,
                name TEXT NOT NULL,
                watched_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                synced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX vulnerability_watched_package_key ON vulnerability_watched_package (ecosystem, name)');
        $this->addSql('CREATE INDEX vulnerability_watched_package_synced ON vulnerability_watched_package (synced_at)');
        $this->addSql('CREATE TABLE vulnerability_kev (cve VARCHAR(32) NOT NULL, added_on DATE NOT NULL, PRIMARY KEY (cve))');
        $this->addSql('CREATE TABLE vulnerability_epss (cve VARCHAR(32) NOT NULL, score DOUBLE PRECISION NOT NULL, percentile DOUBLE PRECISION NOT NULL, scored_on DATE NOT NULL, PRIMARY KEY (cve))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE vulnerability_epss');
        $this->addSql('DROP TABLE vulnerability_kev');
        $this->addSql('DROP TABLE vulnerability_watched_package');
        $this->addSql('DROP TABLE vulnerability_advisory_package');
        $this->addSql('DROP TABLE vulnerability_advisory');
    }
}
