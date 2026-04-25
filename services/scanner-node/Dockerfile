FROM node:20-alpine

RUN apk add --no-cache \
    clamav \
    clamav-daemon \
    supervisor \
    dos2unix

WORKDIR /app
COPY . /app

RUN chmod +x /app/install-clamav.sh
RUN /app/install-clamav.sh

COPY clamd.conf /etc/clamav/clamd.conf
RUN dos2unix /etc/clamav/clamd.conf

COPY supervisord.conf /app/supervisord.conf

CMD ["supervisord", "-c", "/app/supervisord.conf"]