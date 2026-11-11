<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fleet: enrollment tokens, hosts, agents, their orders, and the nonces of the signed agent requests (raw SQL table,
 * outside the ORM mapping: excluded by the schema filter). References to Organization (team, environment) are plain
 * UUIDs, no foreign key across contexts.
 */
final class Version20261111120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fleet tables (enrollment tokens, hosts, agents, orders, request nonces)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE fleet_enrollment_token (
                id UUID NOT NULL,
                team_id UUID NOT NULL,
                environment_id UUID DEFAULT NULL,
                kind VARCHAR(16) NOT NULL,
                token_hash VARCHAR(128) NOT NULL,
                created_by UUID NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                max_uses INT NOT NULL,
                uses INT NOT NULL,
                status VARCHAR(16) NOT NULL,
                approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                approved_by UUID DEFAULT NULL,
                revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E1D1357AB3BC57DA ON fleet_enrollment_token (token_hash)');
        $this->addSql('CREATE INDEX fleet_enrollment_token_team ON fleet_enrollment_token (team_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE fleet_host (
                id UUID NOT NULL,
                team_id UUID NOT NULL,
                identity_kind VARCHAR(16) NOT NULL,
                identity_value VARCHAR(255) NOT NULL,
                hostname VARCHAR(255) NOT NULL,
                os_id VARCHAR(255) DEFAULT NULL,
                os_version_id VARCHAR(255) DEFAULT NULL,
                os_pretty_name VARCHAR(255) DEFAULT NULL,
                kernel VARCHAR(255) DEFAULT NULL,
                arch VARCHAR(255) DEFAULT NULL,
                docker_engine_id VARCHAR(255) DEFAULT NULL,
                machine_id_hash VARCHAR(64) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX fleet_host_identity ON fleet_host (team_id, identity_kind, identity_value)');

        $this->addSql(<<<'SQL'
            CREATE TABLE fleet_agent (
                id UUID NOT NULL,
                team_id UUID NOT NULL,
                host_id UUID NOT NULL,
                enrollment_token_id UUID NOT NULL,
                public_key VARCHAR(60) NOT NULL,
                fingerprint VARCHAR(60) NOT NULL,
                mode VARCHAR(16) NOT NULL,
                scope VARCHAR(16) DEFAULT NULL,
                compose_project VARCHAR(255) DEFAULT NULL,
                version VARCHAR(64) NOT NULL,
                status VARCHAR(16) NOT NULL,
                enrolled_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                approved_by UUID DEFAULT NULL,
                revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                applied_config_version INT NOT NULL,
                desired_config_version INT NOT NULL,
                desired_config JSON DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX fleet_agent_team ON fleet_agent (team_id)');
        $this->addSql('CREATE INDEX fleet_agent_host ON fleet_agent (host_id)');
        $this->addSql('CREATE UNIQUE INDEX fleet_agent_token_key ON fleet_agent (enrollment_token_id, fingerprint)');
        // One live agent per key: a revoked key may come back only through a new installation (new key anyway).
        $this->addSql("CREATE UNIQUE INDEX fleet_agent_live_fingerprint ON fleet_agent (fingerprint) WHERE (status <> 'revoked')");

        $this->addSql(<<<'SQL'
            CREATE TABLE fleet_agent_order (
                id UUID NOT NULL,
                agent_id UUID NOT NULL,
                team_id UUID NOT NULL,
                type VARCHAR(32) NOT NULL,
                payload JSON NOT NULL,
                requested_by UUID DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                status VARCHAR(16) NOT NULL,
                issued_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                signature VARCHAR(128) DEFAULT NULL,
                finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                reason VARCHAR(64) DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX fleet_agent_order_agent ON fleet_agent_order (agent_id, status)');

        // Replay protection of the signed requests (protocol §4.3): a (keyid, nonce) pair is accepted once; rows
        // older than 10 minutes are purged (beyond the ±300 s clock window, a replay is refused as clock_skew).
        $this->addSql(<<<'SQL'
            CREATE TABLE fleet_agent_nonce (
                key_id VARCHAR(60) NOT NULL,
                nonce VARCHAR(64) NOT NULL,
                seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (key_id, nonce)
            )
            SQL);
        $this->addSql('CREATE INDEX fleet_agent_nonce_seen_at ON fleet_agent_nonce (seen_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE fleet_agent_nonce');
        $this->addSql('DROP TABLE fleet_agent_order');
        $this->addSql('DROP TABLE fleet_agent');
        $this->addSql('DROP TABLE fleet_host');
        $this->addSql('DROP TABLE fleet_enrollment_token');
    }
}
