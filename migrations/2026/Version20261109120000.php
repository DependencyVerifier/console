<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Foundations, run by the owner role:
 * - default privileges of the runtime role (DML on application tables, no DDL);
 * - rate limiter states;
 * - the append-only audit log: own schema, monthly partitions plus DEFAULT, triggers refusing UPDATE / DELETE /
 *   TRUNCATE, INSERT / SELECT only for the runtime role.
 */
final class Version20261109120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Runtime role privileges, rate limiter state, append-only partitioned audit log';
    }

    public function up(Schema $schema): void
    {
        // Application tables (public schema): DML for the runtime role, on every table the owner creates from now on.
        $this->addSql('GRANT USAGE ON SCHEMA public TO depverify_app');
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO depverify_app');
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO depverify_app');

        $this->addSql('CREATE TABLE rate_limiter_state (id VARCHAR(255) NOT NULL, state TEXT NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX rate_limiter_state_expires_at ON rate_limiter_state (expires_at)');

        // Audit schema: its own default privileges, INSERT / SELECT only, also for partitions created later.
        $this->addSql('CREATE SCHEMA audit');
        $this->addSql('REVOKE ALL ON SCHEMA audit FROM PUBLIC');
        $this->addSql('GRANT USAGE ON SCHEMA audit TO depverify_app');
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA audit REVOKE ALL ON TABLES FROM PUBLIC');
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA audit GRANT INSERT, SELECT ON TABLES TO depverify_app');
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA audit REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC');

        $this->addSql(<<<'SQL'
            CREATE TABLE audit.audit_log (
                id UUID NOT NULL,
                occurred_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                actor_type VARCHAR(16) NOT NULL,
                actor_id UUID DEFAULT NULL,
                action VARCHAR(128) NOT NULL,
                target_type VARCHAR(64) NOT NULL,
                target_id VARCHAR(64) DEFAULT NULL,
                team_id UUID DEFAULT NULL,
                project_id UUID DEFAULT NULL,
                outcome VARCHAR(16) NOT NULL,
                reason VARCHAR(128) DEFAULT '' NOT NULL,
                ip INET DEFAULT NULL,
                user_agent VARCHAR(512) DEFAULT '' NOT NULL,
                request_id VARCHAR(64) NOT NULL,
                context JSONB DEFAULT '{}' NOT NULL,
                PRIMARY KEY (id, occurred_at)
            ) PARTITION BY RANGE (occurred_at)
            SQL);
        $this->addSql('CREATE INDEX audit_log_team ON audit.audit_log (team_id, occurred_at)');
        $this->addSql('CREATE INDEX audit_log_actor ON audit.audit_log (actor_id, occurred_at)');
        $this->addSql('CREATE INDEX audit_log_action ON audit.audit_log (action, occurred_at)');

        // Append-only: row triggers (cloned to every partition) refuse UPDATE / DELETE; a statement trigger refuses
        // TRUNCATE (set on the parent and on each partition, statement triggers are not inherited).
        $this->addSql(<<<'SQL'
            CREATE FUNCTION audit.forbid_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit.audit_log is append-only: % refused', TG_OP USING ERRCODE = 'insufficient_privilege';
            END
            $$
            SQL);
        $this->addSql('CREATE TRIGGER audit_log_append_only BEFORE UPDATE OR DELETE ON audit.audit_log FOR EACH ROW EXECUTE FUNCTION audit.forbid_mutation()');
        $this->addSql('CREATE TRIGGER audit_log_no_truncate BEFORE TRUNCATE ON audit.audit_log FOR EACH STATEMENT EXECUTE FUNCTION audit.forbid_mutation()');

        // One partition per month, with explicit privileges and its own TRUNCATE trigger. Reused by
        // app:audit:maintain-partitions (owner role only: EXECUTE is revoked from PUBLIC).
        $this->addSql(<<<'SQL'
            CREATE FUNCTION audit.protect_partition(partition regclass) RETURNS void LANGUAGE plpgsql AS $$
            BEGIN
                EXECUTE format('REVOKE ALL ON %s FROM PUBLIC, depverify_app', partition);
                EXECUTE format('GRANT INSERT, SELECT ON %s TO depverify_app', partition);
                IF NOT EXISTS (SELECT FROM pg_trigger WHERE tgrelid = partition AND tgname = 'audit_log_no_truncate') THEN
                    EXECUTE format('CREATE TRIGGER audit_log_no_truncate BEFORE TRUNCATE ON %s FOR EACH STATEMENT EXECUTE FUNCTION audit.forbid_mutation()', partition);
                END IF;
            END
            $$
            SQL);
        $this->addSql(<<<'SQL'
            CREATE FUNCTION audit.ensure_month_partition(month DATE) RETURNS regclass LANGUAGE plpgsql AS $$
            DECLARE
                first_day DATE := date_trunc('month', month)::date;
                name TEXT := format('audit_log_%s', to_char(first_day, 'YYYY_MM'));
                partition regclass;
            BEGIN
                partition := to_regclass(format('audit.%I', name));
                IF partition IS NULL THEN
                    EXECUTE format(
                        'CREATE TABLE audit.%I PARTITION OF audit.audit_log FOR VALUES FROM (%L) TO (%L)',
                        name, first_day, (first_day + INTERVAL '1 month')::date
                    );
                    partition := to_regclass(format('audit.%I', name));
                END IF;
                PERFORM audit.protect_partition(partition);

                RETURN partition;
            END
            $$
            SQL);
        $this->addSql('REVOKE EXECUTE ON FUNCTION audit.forbid_mutation(), audit.protect_partition(regclass), audit.ensure_month_partition(date) FROM PUBLIC');

        // DEFAULT catches any row outside the monthly partitions, so no insert ever fails; then 12 months ahead.
        $this->addSql('CREATE TABLE audit.audit_log_default PARTITION OF audit.audit_log DEFAULT');
        $this->addSql("SELECT audit.protect_partition('audit.audit_log_default'::regclass)");
        $this->addSql("SELECT audit.ensure_month_partition((date_trunc('month', now()) + make_interval(months => m))::date) FROM generate_series(0, 12) AS m");
        $this->addSql('REVOKE ALL ON audit.audit_log FROM PUBLIC');
        $this->addSql('GRANT INSERT, SELECT ON audit.audit_log TO depverify_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SCHEMA audit CASCADE');
        $this->addSql('DROP TABLE rate_limiter_state');
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLES FROM depverify_app');
        $this->addSql('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE USAGE, SELECT ON SEQUENCES FROM depverify_app');
    }
}
