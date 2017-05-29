#!/bin/bash
docker login -u="$QUAY_USERNAME" -p="$QUAY_PASSWORD" quay.io
docker tag keboola/cli-utils quay.io/keboola/cli-utils:$TRAVIS_TAG
docker tag keboola/cli-utils quay.io/keboola/cli-utils:latest
docker images
docker push quay.io/keboola/cli-utils:$TRAVIS_TAG
docker push quay.io/keboola/cli-utils:latest