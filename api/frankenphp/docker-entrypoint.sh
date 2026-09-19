#!/bin/sh
set -e

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
	if [ -z "$(ls -A 'vendor/' 2>/dev/null)" ]; then
		composer install --prefer-dist --no-progress --no-interaction
	fi

	# Display information about the current project
	# Or about an error in project initialization
	php bin/console -V

	# JWT signing keys — generated on first boot, but ONLY in the container that
	# owns them, for the same reason migrations are (see the migration block
	# below). api, worker and scheduler all share this image and this entrypoint;
	# letting every one of them generate would race them to write the same key
	# files. --skip-if-exists makes it idempotent, so a restart never overwrites a
	# working pair, and an operator who mounts their own keys keeps them. In
	# compose.selfhost.yaml the keys default to a path on the persisted api_data
	# volume (JWT_SECRET_KEY under /data), so they survive `docker compose down`;
	# worker and scheduler set SKIP_MIGRATIONS=1 and wait for api to be healthy.
	if [ "${SKIP_MIGRATIONS:-0}" = "1" ]; then
		echo 'SKIP_MIGRATIONS=1 — leaving JWT key generation to the api container.'
	else
		# Create the key directory up front so the generate command (running as
		# www-data in the prod image) can write into it. Dev keeps its own
		# config/jwt/ path from .env; the default here matches the self-host volume.
		mkdir -p "$(dirname "${JWT_SECRET_KEY:-/data/jwt/private.pem}")"
		php bin/console lexik:jwt:generate-keypair --skip-if-exists
	fi

	if grep -q ^DATABASE_URL= .env; then
		echo 'Waiting for database to be ready...'
		ATTEMPTS_LEFT_TO_REACH_DATABASE=60
		until [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ] || DATABASE_ERROR=$(php bin/console dbal:run-sql -q "SELECT 1" 2>&1); do
			if [ $? -eq 255 ]; then
				# If the Doctrine command exits with 255, an unrecoverable error occurred
				ATTEMPTS_LEFT_TO_REACH_DATABASE=0
				break
			fi
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

		# Migrations run on boot — but ONLY in the container that owns them.
		#
		# Every service (api, worker, scheduler) shares this image and therefore this
		# entrypoint. Letting them all migrate is a race: they start together, each
		# sees an empty doctrine_migration_versions, and they run the same migration
		# concurrently. One wins; the loser dies with "relation already exists" and
		# restart-loops, and the migration bookkeeping can be left inconsistent with
		# the actual schema — which is far worse than the crash, because the next
		# boot then tries to re-apply an already-applied migration.
		#
		# So: `api` migrates, everything else sets SKIP_MIGRATIONS=1 and waits for
		# api to report healthy (see depends_on in the compose files).
		if [ "${SKIP_MIGRATIONS:-0}" = "1" ]; then
			echo 'SKIP_MIGRATIONS=1 — leaving migrations to the api container.'
		elif [ "$(find ./migrations -iname '*.php' -print -quit)" ]; then
			php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
		fi
	fi

	echo 'PHP app ready!'
fi

exec docker-php-entrypoint "$@"
