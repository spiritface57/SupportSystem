FROM node:20-alpine

RUN apk add --no-cache \
    clamav \
    clamav-daemon \
    supervisor

WORKDIR /app
COPY . /app

RUN chmod +x /app/install-clamav.sh

# دیتابیس در build یا start آماده می‌شود
RUN /app/install-clamav.sh

CMD ["supervisord", "-c", "/app/supervisord.conf"]
