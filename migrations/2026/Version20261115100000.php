<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Vulnerability: the Red Hat advisories kept so far dropped RHEL 10 and the update channels (EUS, E4S, AUS, TUS),
 * which the console now matches. Their stored modification date goes back to the epoch and their packages are due,
 * so that the next `vuln-sync` reads them again (an unchanged advisory is never fetched twice otherwise).
 */
final class Version20261115100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vulnerability: read the Red Hat advisories again (RHEL 10, update channels)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE vulnerability_advisory SET modified_at = '1970-01-01 00:00:00' WHERE osv_id ~ '^RH[SBE]A-'");
        $this->addSql("UPDATE vulnerability_watched_package SET synced_at = NULL WHERE ecosystem LIKE 'Red Hat:%'");
    }

    public function down(Schema $schema): void
    {
        // Nothing to undo: the next sync writes the advisories again.
    }
}
