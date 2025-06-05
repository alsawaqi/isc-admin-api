FROM nginx:latest

# Copy your custom Nginx site configuration
COPY default.conf /etc/nginx/conf.d/default.conf
