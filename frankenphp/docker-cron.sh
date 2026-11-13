#!/bin/sh
# The `cron` service: the cron daemon (root) runs the crontab of www-data (frankenphp/crontab, installed by the
# Dockerfile). Cron starts each job with an empty environment: the container's one (database, secrets) is handed to
# the jobs through /etc/environment (read by pam_env, root only). Their output goes to the logs of the container
# through a pipe the jobs may write to.
set -eu

env | grep -Ev '^(HOME|HOSTNAME|PWD|SHLVL|_)=' > /etc/environment
chmod 600 /etc/environment

# The jobs run as www-data: the cache and the logs of this container must be theirs (in dev, /app/var is a volume of
# this container created by root; in prod it is already www-data's).
mkdir -p /app/var
chown -R www-data /app/var

rm -f /tmp/cron.log
mkfifo -m 0622 /tmp/cron.log
cat <> /tmp/cron.log &

exec cron -f
