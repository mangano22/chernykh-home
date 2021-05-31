#!/bin/bash

if [ -n "$1" ]; then
    cat /etc/nginx/default.template | envsubst '\$APP_ENTRYPOINT \$FPM_UPSTREAM' > /etc/nginx/conf.d/default.conf
    exec "$@"
fi