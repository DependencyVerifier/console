<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Inventory: the inventories of the agents, their snapshots (gzip blobs, once per team and digest), the hosts' facts,
 * the applications and container images found, their history, and the packages of each SBOM (raw SQL table, bulk
 * inserts, excluded by the schema filter). References to Fleet and Organization are plain UUIDs, except the trigger
 * that enforces cadrage §5: an application belongs only to an environment of its host's team.
 */
final class Version20261111170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Inventory tables (runs, blobs, hosts, applications, images, history, packages)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE inventory_run (
                id UUID NOT NULL,
                agent_id UUID NOT NULL,
                team_id UUID NOT NULL,
                host_id UUID NOT NULL,
                manifest JSON NOT NULL,
                status VARCHAR(16) NOT NULL,
                received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                processed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX inventory_run_open ON inventory_run (status, received_at)');
        $this->addSql('CREATE INDEX inventory_run_host ON inventory_run (host_id, received_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE inventory_blob (
                id UUID NOT NULL,
                team_id UUID NOT NULL,
                digest VARCHAR(71) NOT NULL,
                media_type VARCHAR(64) NOT NULL,
                size INT NOT NULL,
                content BYTEA NOT NULL,
                stored_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX inventory_blob_digest ON inventory_blob (team_id, digest)');

        $this->addSql(<<<'SQL'
            CREATE TABLE inventory_host (
                host_id UUID NOT NULL,
                team_id UUID NOT NULL,
                facts JSON NOT NULL,
                facts_digest VARCHAR(71) DEFAULT NULL,
                packages_digest VARCHAR(71) DEFAULT NULL,
                last_inventory_id UUID DEFAULT NULL,
                inventoried_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (host_id)
            )
            SQL);
        $this->addSql('CREATE INDEX inventory_host_team ON inventory_host (team_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE inventory_app_instance (
                id UUID NOT NULL,
                team_id UUID NOT NULL,
                host_id UUID NOT NULL,
                subject_key TEXT NOT NULL,
                subject_key_hash VARCHAR(64) NOT NULL,
                kind VARCHAR(32) NOT NULL,
                fields JSON NOT NULL,
                sbom_digest VARCHAR(71) NOT NULL,
                image_id VARCHAR(71) DEFAULT NULL,
                environment_id UUID DEFAULT NULL,
                assignment VARCHAR(16) NOT NULL,
                present BOOLEAN NOT NULL,
                first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX inventory_app_instance_subject ON inventory_app_instance (host_id, subject_key_hash)');
        $this->addSql('CREATE INDEX inventory_app_instance_team ON inventory_app_instance (team_id)');
        $this->addSql('CREATE INDEX inventory_app_instance_environment ON inventory_app_instance (environment_id)');
        // Cadrage §5: the environment of an application is one of its host's team, whatever the code does.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION inventory_app_instance_same_team() RETURNS trigger AS $$
            BEGIN
                IF NEW.environment_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM organization_environment e JOIN organization_project p ON p.id = e.project_id
                    WHERE e.id = NEW.environment_id AND p.team_id = NEW.team_id
                ) THEN
                    RAISE EXCEPTION 'The environment % is not one of the team % of this application', NEW.environment_id, NEW.team_id
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql('CREATE TRIGGER inventory_app_instance_same_team BEFORE INSERT OR UPDATE OF environment_id, team_id ON inventory_app_instance FOR EACH ROW EXECUTE FUNCTION inventory_app_instance_same_team()');

        $this->addSql(<<<'SQL'
            CREATE TABLE inventory_container_image (
                id UUID NOT NULL,
                team_id UUID NOT NULL,
                image_id VARCHAR(71) NOT NULL,
                fields JSON NOT NULL,
                sbom_digest VARCHAR(71) NOT NULL,
                first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX inventory_container_image_id ON inventory_container_image (team_id, image_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE inventory_snapshot (
                id UUID NOT NULL,
                team_id UUID NOT NULL,
                inventory_id UUID NOT NULL,
                subject_type VARCHAR(16) NOT NULL,
                subject_id UUID NOT NULL,
                digest VARCHAR(71) NOT NULL,
                taken_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX inventory_snapshot_subject ON inventory_snapshot (subject_id, taken_at)');
        $this->addSql('CREATE INDEX inventory_snapshot_team ON inventory_snapshot (team_id, taken_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE inventory_package (
                id UUID NOT NULL,
                team_id UUID NOT NULL,
                blob_id UUID NOT NULL REFERENCES inventory_blob (id) ON DELETE CASCADE,
                purl TEXT NOT NULL,
                type VARCHAR(64) NOT NULL,
                namespace TEXT DEFAULT NULL,
                name TEXT NOT NULL,
                version TEXT DEFAULT NULL,
                dependency VARCHAR(16) DEFAULT NULL,
                source VARCHAR(255) DEFAULT NULL,
                manager VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX inventory_package_blob ON inventory_package (blob_id)');
        // The matching (step 4) looks advisories up by ecosystem and name.
        $this->addSql('CREATE INDEX inventory_package_name ON inventory_package (type, name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE inventory_package');
        $this->addSql('DROP TABLE inventory_snapshot');
        $this->addSql('DROP TABLE inventory_container_image');
        $this->addSql('DROP TABLE inventory_app_instance');
        $this->addSql('DROP FUNCTION inventory_app_instance_same_team()');
        $this->addSql('DROP TABLE inventory_host');
        $this->addSql('DROP TABLE inventory_blob');
        $this->addSql('DROP TABLE inventory_run');
    }
}
