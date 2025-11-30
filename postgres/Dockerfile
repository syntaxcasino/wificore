#FROM postgres:15
#FROM postgres:16.10-trixie
FROM debian:stable-slim
RUN apt-get update && apt-get install -y \
    build-essential \
    postgresql-server-dev-15 \
    git \
    && git clone https://github.com/citusdata/pg_cron.git \
    && cd pg_cron \
    && make && make install \
    && cd .. && rm -rf pg_cron \
    && git clone https://github.com/pgpartman/pg_partman.git \
    && cd pg_partman \
    && make && make install \
    && cd .. && rm -rf pg_partman \
    && apt-get remove -y build-essential postgresql-server-dev-15 git \
    && apt-get autoremove -y \
    && apt-get clean