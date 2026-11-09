<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Organization: teams, memberships, projects, environments, invitations, permission grants, instance settings.
 * Privileges for depverify_app come from the default privileges of the public schema (first migration).
 */
final class Version20261109160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Organization tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE organization_environment (id UUID NOT NULL, name VARCHAR(60) NOT NULL, production BOOLEAN NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_767A7A59166D1F9C ON organization_environment (project_id)');
        $this->addSql('CREATE TABLE organization_instance_settings (id VARCHAR(16) NOT NULL, default_max_projects INT DEFAULT NULL, default_max_environments_per_project INT DEFAULT NULL, default_max_agents INT DEFAULT NULL, default_max_members INT DEFAULT NULL, default_max_notification_only_accounts INT DEFAULT NULL, default_max_notification_channels INT DEFAULT NULL, default_max_reusable_enrollment_tokens INT DEFAULT NULL, default_snapshot_retention_days INT DEFAULT NULL, default_min_scan_interval_minutes INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE organization_invitation (status VARCHAR(16) NOT NULL, token_hash VARCHAR(128) NOT NULL, issued_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, accepted_by UUID DEFAULT NULL, id UUID NOT NULL, email VARCHAR(254) NOT NULL, invited_by UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, target_type VARCHAR(16) NOT NULL, team_id UUID NOT NULL, project_id UUID DEFAULT NULL, role VARCHAR(16) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1846F34DB3BC57DA ON organization_invitation (token_hash)');
        $this->addSql('CREATE INDEX organization_invitation_team ON organization_invitation (team_id)');
        $this->addSql('CREATE TABLE organization_permission_grant (id UUID NOT NULL, team_id UUID NOT NULL, project_id UUID DEFAULT NULL, user_id UUID NOT NULL, change VARCHAR(32) NOT NULL, value VARCHAR(64) NOT NULL, granted_by UUID DEFAULT NULL, granted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX organization_permission_grant_team ON organization_permission_grant (team_id, granted_at)');
        $this->addSql('CREATE TABLE organization_project (id UUID NOT NULL, team_id UUID NOT NULL, name VARCHAR(120) NOT NULL, slug VARCHAR(140) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX organization_project_slug ON organization_project (team_id, slug)');
        $this->addSql('CREATE TABLE organization_project_membership (status VARCHAR(16) NOT NULL, extra_permissions JSON NOT NULL, id UUID NOT NULL, user_id UUID NOT NULL, role VARCHAR(16) NOT NULL, joined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX organization_project_membership_unique ON organization_project_membership (project_id, user_id)');
        $this->addSql('CREATE INDEX IDX_FF73A4F5166D1F9C ON organization_project_membership (project_id)');
        $this->addSql('CREATE TABLE organization_team (admin_established BOOLEAN NOT NULL, id UUID NOT NULL, name VARCHAR(120) NOT NULL, slug VARCHAR(140) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, quota_max_projects INT DEFAULT NULL, quota_max_environments_per_project INT DEFAULT NULL, quota_max_agents INT DEFAULT NULL, quota_max_members INT DEFAULT NULL, quota_max_notification_only_accounts INT DEFAULT NULL, quota_max_notification_channels INT DEFAULT NULL, quota_max_reusable_enrollment_tokens INT DEFAULT NULL, quota_snapshot_retention_days INT DEFAULT NULL, quota_min_scan_interval_minutes INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FDE99882989D9B62 ON organization_team (slug)');
        $this->addSql('CREATE TABLE organization_team_membership (status VARCHAR(16) NOT NULL, extra_permissions JSON NOT NULL, id UUID NOT NULL, user_id UUID NOT NULL, role VARCHAR(16) NOT NULL, joined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, team_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX organization_team_membership_unique ON organization_team_membership (team_id, user_id)');
        $this->addSql('CREATE INDEX IDX_EABE5095296CD8AE ON organization_team_membership (team_id)');
        $this->addSql('ALTER TABLE organization_environment ADD CONSTRAINT FK_767A7A59166D1F9C FOREIGN KEY (project_id) REFERENCES organization_project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE organization_project_membership ADD CONSTRAINT FK_FF73A4F5166D1F9C FOREIGN KEY (project_id) REFERENCES organization_project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE organization_team_membership ADD CONSTRAINT FK_EABE5095296CD8AE FOREIGN KEY (team_id) REFERENCES organization_team (id) ON DELETE CASCADE NOT DEFERRABLE');
        // One pending invitation per email and target, deferred to the commit: InviteMember revokes an expired one and
        // inserts the new one in the same flush (Doctrine inserts before it updates).
        $this->addSql(<<<'SQL'
            ALTER TABLE organization_invitation ADD CONSTRAINT organization_invitation_one_pending
                EXCLUDE USING btree (email WITH =, team_id WITH =, (COALESCE(project_id, '00000000-0000-0000-0000-000000000000'::uuid)) WITH =)
                WHERE (status = 'pending') DEFERRABLE INITIALLY DEFERRED
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization_environment DROP CONSTRAINT FK_767A7A59166D1F9C');
        $this->addSql('ALTER TABLE organization_project_membership DROP CONSTRAINT FK_FF73A4F5166D1F9C');
        $this->addSql('ALTER TABLE organization_team_membership DROP CONSTRAINT FK_EABE5095296CD8AE');
        $this->addSql('DROP TABLE organization_environment');
        $this->addSql('DROP TABLE organization_instance_settings');
        $this->addSql('DROP TABLE organization_invitation');
        $this->addSql('DROP TABLE organization_permission_grant');
        $this->addSql('DROP TABLE organization_project');
        $this->addSql('DROP TABLE organization_project_membership');
        $this->addSql('DROP TABLE organization_team');
        $this->addSql('DROP TABLE organization_team_membership');
    }
}
