#!/bin/sh
set -e

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then

	# In dev, php and the worker share the bind-mounted vendor/: two concurrent `composer install` into it break each
	# other ("exists and is not a directory"). Only the web container installs; the others wait for the end of it
	# (autoload_runtime.php is written last). In prod the vendors are in the image, so nothing waits.
	if [ "$1" = 'frankenphp' ]; then
		if [ -z "$(ls -A 'vendor/' 2>/dev/null)" ]; then
			composer install --prefer-dist --no-progress --no-interaction
		fi
	elif [ ! -f vendor/autoload_runtime.php ]; then
		echo 'Waiting for the php service to install the vendors...'
		ATTEMPTS_LEFT_FOR_VENDORS=600
		until [ -f vendor/autoload_runtime.php ] || [ $ATTEMPTS_LEFT_FOR_VENDORS -eq 0 ]; do
			sleep 1
			ATTEMPTS_LEFT_FOR_VENDORS=$((ATTEMPTS_LEFT_FOR_VENDORS - 1))
		done
		if [ ! -f vendor/autoload_runtime.php ]; then
			echo 'The vendors are still missing: see `docker compose logs php`.' >&2
			exit 1
		fi
	fi

	# Display information about the current project
	# Or about an error in project initialization
	php bin/console -V

	if grep -q ^DATABASE_URL= .env; then
		echo 'Waiting for database to be ready...'
		ATTEMPTS_LEFT_TO_REACH_DATABASE=60
		until [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ] || DATABASE_ERROR=$(php bin/console dbal:run-sql -q "SELECT 1" 2>&1); do
			if [ $? -eq 255 ]; then
				# If the Doctrine command exits with 255, an unrecoverable error occurred
				ATTEMPTS_LEFT_TO_REACH_DATABASE=0
				break
			fi
			# A wrong password never heals by waiting: the roles are created only on a fresh volume (roles.sh).
			case "$DATABASE_ERROR" in
			*'password authentication failed'*)
				echo 'The role password in the database differs from DATABASE_URL: run bin/install-console again, or make db-roles (INSTALL.md, Troubleshooting).'
				ATTEMPTS_LEFT_TO_REACH_DATABASE=0
				break
				;;
			esac
			sleep 1
			ATTEMPTS_LEFT_TO_REACH_DATABASE=$((ATTEMPTS_LEFT_TO_REACH_DATABASE - 1))
			echo "Still waiting for database to be ready... Or maybe the database is not reachable. $ATTEMPTS_LEFT_TO_REACH_DATABASE attempts left."
		done

		if [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ]; then
			echo 'The database is not up or not reachable:'
			echo "$DATABASE_ERROR"
			exit 1
		else
			echo 'The database is now ready and reachable'
		fi

		# No migration here: the web container runs as the runtime role (depverify_app), which has no DDL right.
		# Migrations run from the CLI as the owner role (`make db-migrate`, see .docker/database/owner.env.dist).
	fi

	echo 'PHP app ready!'
fi

exec docker-php-entrypoint "$@"
