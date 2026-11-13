<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notification: webhooks of a team, rules of a project or of the fleet, subscriptions, and the outbox (a notice per
 * rule and occurrence, a delivery per recipient: their unique indexes make a notification told once).
 */
final class Version20261113000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notification: webhooks, rules, subscriptions, notices and deliveries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification_delivery (id UUID NOT NULL, notice_id UUID NOT NULL, team_id UUID NOT NULL, channel VARCHAR(16) NOT NULL, target VARCHAR(36) NOT NULL, frequency VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, attempts INT NOT NULL, next_attempt_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, done_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_error VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX notification_delivery_due ON notification_delivery (status, frequency, next_attempt_at)');
        $this->addSql('CREATE UNIQUE INDEX notification_delivery_once ON notification_delivery (notice_id, channel, target)');
        $this->addSql('CREATE TABLE notification_notice (id UUID NOT NULL, rule_id UUID NOT NULL, team_id UUID NOT NULL, project_id UUID DEFAULT NULL, event VARCHAR(32) NOT NULL, dedupe_key VARCHAR(255) NOT NULL, content JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX notification_notice_team ON notification_notice (team_id)');
        $this->addSql('CREATE UNIQUE INDEX notification_notice_occurrence ON notification_notice (rule_id, dedupe_key)');
        $this->addSql('CREATE TABLE notification_rule (id UUID NOT NULL, team_id UUID NOT NULL, project_id UUID DEFAULT NULL, environment_id UUID DEFAULT NULL, name VARCHAR(100) NOT NULL, events JSON NOT NULL, min_severity VARCHAR(16) DEFAULT NULL, min_cvss DOUBLE PRECISION DEFAULT NULL, known_exploited_only BOOLEAN NOT NULL, min_epss DOUBLE PRECISION DEFAULT NULL, silent_hours INT NOT NULL, webhook_ids JSON NOT NULL, email_subscribers BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX notification_rule_team ON notification_rule (team_id)');
        $this->addSql('CREATE TABLE notification_subscription (id UUID NOT NULL, team_id UUID NOT NULL, project_id UUID DEFAULT NULL, scope_key VARCHAR(36) NOT NULL, user_id UUID NOT NULL, frequency VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX notification_subscription_lookup ON notification_subscription (team_id, scope_key)');
        $this->addSql('CREATE UNIQUE INDEX notification_subscription_scope ON notification_subscription (user_id, team_id, scope_key)');
        $this->addSql('CREATE TABLE notification_webhook (id UUID NOT NULL, team_id UUID NOT NULL, name VARCHAR(100) NOT NULL, url VARCHAR(2048) NOT NULL, secret TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_called_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_succeeded BOOLEAN DEFAULT NULL, last_error VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX notification_webhook_team ON notification_webhook (team_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification_delivery');
        $this->addSql('DROP TABLE notification_notice');
        $this->addSql('DROP TABLE notification_rule');
        $this->addSql('DROP TABLE notification_subscription');
        $this->addSql('DROP TABLE notification_webhook');
    }
}
