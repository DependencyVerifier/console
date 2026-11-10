<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109193020 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Email change requests (confirmed by a link sent to the new address).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE identity_email_change_request (expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, consumed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, invalidated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, user_id UUID NOT NULL, new_email VARCHAR(254) NOT NULL, token_hash VARCHAR(128) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_84DB55D9B3BC57DA ON identity_email_change_request (token_hash)');
        $this->addSql('CREATE INDEX identity_email_change_request_user ON identity_email_change_request (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE identity_email_change_request');
    }
}
