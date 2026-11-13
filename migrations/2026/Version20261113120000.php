<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notification: an address added by a manager waits in an invitation (no account, no membership) until its holder
 * confirms it; a subscription is always consented to, so it loses its confirmation columns.
 */
final class Version20261113120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notification: recipient invitations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX notification_subscription_confirmation');
        $this->addSql('DELETE FROM notification_subscription WHERE confirmed_at IS NULL');
        $this->addSql('ALTER TABLE notification_subscription DROP confirmation_hash, DROP confirmation_expires_at, DROP confirmed_at');
        $this->addSql('CREATE TABLE notification_recipient_invitation (id UUID NOT NULL, team_id UUID NOT NULL, project_id UUID NOT NULL, email VARCHAR(254) NOT NULL, frequency VARCHAR(16) NOT NULL, invited_by UUID NOT NULL, token_hash VARCHAR(255) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, status VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX notification_recipient_invitation_token ON notification_recipient_invitation (token_hash)');
        $this->addSql('CREATE INDEX notification_recipient_invitation_project ON notification_recipient_invitation (team_id, project_id, status)');
        // One pending invitation per project and address (an expired one is resent, never duplicated).
        $this->addSql("CREATE UNIQUE INDEX notification_recipient_invitation_one_pending ON notification_recipient_invitation (project_id, email) WHERE status = 'pending'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification_recipient_invitation');
        $this->addSql('ALTER TABLE notification_subscription ADD confirmation_hash VARCHAR(255) DEFAULT NULL, ADD confirmation_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ADD confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE notification_subscription SET confirmed_at = created_at');
        $this->addSql('CREATE UNIQUE INDEX notification_subscription_confirmation ON notification_subscription (confirmation_hash)');
    }
}
