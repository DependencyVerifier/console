<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Identity: the passkeys of an account (WebAuthn credentials, docs/cadrage.md §5 PasskeyCredential). A credential id
 * belongs to one account of the instance; the counter goes up to 2^32 - 1.
 */
final class Version20261115120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identity: passkeys';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE identity_passkey (last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, credential_id TEXT NOT NULL, public_key TEXT NOT NULL, sign_count BIGINT NOT NULL, transports JSON NOT NULL, aaguid VARCHAR(36) NOT NULL, name VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX identity_passkey_credential ON identity_passkey (credential_id)');
        $this->addSql('CREATE INDEX IDX_82309714A76ED395 ON identity_passkey (user_id)');
        $this->addSql('ALTER TABLE identity_passkey ADD CONSTRAINT FK_82309714A76ED395 FOREIGN KEY (user_id) REFERENCES identity_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE identity_passkey');
    }
}
