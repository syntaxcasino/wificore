FROM nginx:stable-alpine
RUN rm /etc/nginx/conf.d/default.conf
RUN rm -rf /usr/share/nginx/html/*
COPY nginx.conf /etc/nginx/conf.d/default.conf
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]