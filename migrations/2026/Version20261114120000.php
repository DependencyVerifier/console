<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fleet: the console key each agent pins (filled at its next poll), and the key each order was signed with, for the
 * rotation of the console key (ROTATE_CONSOLE_KEY).
 */
final class Version20261114120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fleet: pinned console key of an agent, signing key of an order';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fleet_agent ADD console_key_id VARCHAR(60) DEFAULT NULL');
        $this->addSql('ALTER TABLE fleet_agent_order ADD signed_with VARCHAR(60) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fleet_agent_order DROP signed_with');
        $this->addSql('ALTER TABLE fleet_agent DROP console_key_id');
    }
}
