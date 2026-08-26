#!/usr/bin/env bash
#
# Helper script to quickly update localdev with the changes that have been pushed to the `dev` branch
# Super simple, just does one thing.


git checkout dev
git pull
docker compose -f compose.yaml build
docker compose -f compose.yaml up -d --remove-orphans
docker compose -f compose.yaml exec -T app php artisan migrate --force
docker compose -f compose.yaml exec -T app php artisan config:cache
docker compose -f compose.yaml exec -T app php artisan route:cache
docker compose -f compose.yaml exec -T app php artisan event:cache
docker compose -f compose.yaml exec -T app php artisan horizon:terminate

