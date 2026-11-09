<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Identity: accounts, backup codes, email login codes, password reset requests, code verification attempts, and the
 * PHP sessions table (PdoSessionHandler). Privileges for depverify_app come from the default privileges of the
 * public schema (first migration): SELECT, INSERT, UPDATE, DELETE.
 */
final class Version20261109140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identity tables and database sessions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE identity_user (
                id UUID NOT NULL,
                email VARCHAR(254) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                credentials_kind VARCHAR(16) NOT NULL,
                password_hash VARCHAR(255) DEFAULT NULL,
                second_factor_kind VARCHAR(16) NOT NULL,
                totp_secret TEXT DEFAULT NULL,
                totp_since TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                totp_last_step BIGINT DEFAULT NULL,
                auth_version INT NOT NULL,
                trusted_device_version INT NOT NULL,
                status VARCHAR(16) NOT NULL,
                disabled_since TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                super_admin BOOLEAN NOT NULL,
                instance_permissions JSON NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_39E1FCDE7927C74 ON identity_user (email)');

        $this->addSql('CREATE TABLE identity_backup_code (id UUID NOT NULL, user_id UUID NOT NULL, hash VARCHAR(128) NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_53BD3120A76ED395 ON identity_backup_code (user_id)');
        $this->addSql('ALTER TABLE identity_backup_code ADD CONSTRAINT FK_53BD3120A76ED395 FOREIGN KEY (user_id) REFERENCES identity_user (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql(<<<'SQL'
            CREATE TABLE identity_email_login_code (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                hash VARCHAR(128) NOT NULL,
                issued_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                attempts INT NOT NULL,
                consumed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                consumed_by_attempt_id UUID DEFAULT NULL,
                invalidated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX IDX_FF985F05A76ED395 ON identity_email_login_code (user_id)');
        $this->addSql('ALTER TABLE identity_email_login_code ADD CONSTRAINT FK_FF985F05A76ED395 FOREIGN KEY (user_id) REFERENCES identity_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        // One active code per account, also guaranteed by the aggregate. Deferred to the commit: Doctrine inserts
        // the new code before it updates the previous one (invalidated), and a partial unique index cannot wait.
        $this->addSql(<<<'SQL'
            ALTER TABLE identity_email_login_code ADD CONSTRAINT identity_email_login_code_one_active
                EXCLUDE USING btree (user_id WITH =) WHERE (consumed_at IS NULL AND invalidated_at IS NULL)
                DEFERRABLE INITIALLY DEFERRED
            SQL);

        $this->addSql('CREATE TABLE identity_password_reset_request (id UUID NOT NULL, user_id UUID NOT NULL, token_hash VARCHAR(128) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, consumed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, invalidated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4CD1188BB3BC57DA ON identity_password_reset_request (token_hash)');
        $this->addSql('CREATE INDEX identity_password_reset_request_user ON identity_password_reset_request (user_id)');

        $this->addSql('CREATE TABLE identity_code_verification_attempt (id UUID NOT NULL, user_id UUID NOT NULL, kind VARCHAR(32) NOT NULL, outcome VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX identity_code_verification_attempt_user ON identity_code_verification_attempt (user_id, created_at)');

        // PdoSessionHandler schema (PostgreSQL). Holds raw session ids: accepted risk (12 h max, revocable through
        // authVersion, runtime role only), docs/cadrage.md §7.
        $this->addSql('CREATE TABLE sessions (sess_id VARCHAR(128) NOT NULL, sess_data BYTEA NOT NULL, sess_lifetime INT NOT NULL, sess_time INT NOT NULL, PRIMARY KEY (sess_id))');
        $this->addSql('CREATE INDEX sess_lifetime_idx ON sessions (sess_lifetime)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sessions');
        $this->addSql('DROP TABLE identity_code_verification_attempt');
        $this->addSql('DROP TABLE identity_password_reset_request');
        $this->addSql('DROP TABLE identity_email_login_code');
        $this->addSql('DROP TABLE identity_backup_code');
        $this->addSql('DROP TABLE identity_user');
    }
}
