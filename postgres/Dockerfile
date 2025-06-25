FROM postgres:15
RUN apt-get update && apt-get install -y \
    build-essential \
    postgresql-server-dev-15 \
    git \
    && git clone https://github.com/citusdata/pg_cron.git \
    && cd pg_cron \
    && make && make install \
    && cd .. && rm -rf pg_cron \
    && apt-get remove -y build-essential postgresql-server-dev-15 git \
    && apt-get autoremove -y \
    && apt-get clean