<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notification: recipients attached by a manager, silent until they confirm their address (hashed single-use link).
 * The subscriptions taken so far were taken by their accounts: confirmed.
 */
final class Version20261113100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notification: attached recipients and their confirmation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification_subscription ADD added_by UUID DEFAULT NULL, ADD confirmation_hash VARCHAR(255) DEFAULT NULL, ADD confirmation_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ADD confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE notification_subscription SET confirmed_at = created_at');
        $this->addSql('CREATE UNIQUE INDEX notification_subscription_confirmation ON notification_subscription (confirmation_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX notification_subscription_confirmation');
        $this->addSql('ALTER TABLE notification_subscription DROP added_by, DROP confirmation_hash, DROP confirmation_expires_at, DROP confirmed_at');
    }
}
