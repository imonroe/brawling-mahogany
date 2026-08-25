#!/usr/bin/env bash
#
# Helper script to quickly update localdev with the changes that have been pushed to the `dev` branch
# Super simple, just does one thing.


git checkout dev; git pull; make deps; make down; make build; make up; make migrate