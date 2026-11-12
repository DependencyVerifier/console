<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Exposure: the risk acceptances of the findings (justification, author, expiry, revocation).
 */
final class Version20261112200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Exposure: risk acceptances';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE exposure_risk_acceptance (
                id UUID NOT NULL,
                finding_id UUID NOT NULL,
                team_id UUID NOT NULL,
                justification TEXT NOT NULL,
                accepted_by UUID NOT NULL,
                accepted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                expires_on DATE NOT NULL,
                revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                revoked_by UUID DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX exposure_risk_acceptance_finding ON exposure_risk_acceptance (finding_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE exposure_risk_acceptance');
    }
}
