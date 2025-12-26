FROM quay.io/soketi/soketi:latest-16-alpine
RUN apk add --no-cache wget \
    && rm -rf /var/cache/apk/* /tmp/*
ENV AWS_SDK_JS_SUPPRESS_MAINTENANCE_MODE_MESSAGE=1
