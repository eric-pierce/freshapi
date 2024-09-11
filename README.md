# FreshAPI
A FreshRSS / Google Reader API Bridge for Tiny-Tiny RSS

## Background
Tiny-Tiny RSS is one of the most featureful and customizable self-hostable RSS Reader server implementations, but before the release of FreshAPI had two main API integrations: 
1. The official API 
2. The Fever API Plugin 

While many mobile applications support either of these protocols, many do not. FreshAPI's goal is to be the bridge between Tiny-Tiny RSS and these fantastic mobile applications, while offering more functionality than either.

| Feature  | Fever API | Official API | FreshAPI |
| :---: | :---: | :---: | :---: |
| Mark Article as Read/Unread | :white_check_mark: | :white_check_mark: | :white_check_mark: |
| Add/Remove Star from Article | :white_check_mark: | :white_check_mark: | :white_check_mark: |
| Mark Feed/Category as Read | :white_check_mark: | :white_check_mark: | :white_check_mark: |
| Secure Implementation | :x: | :white_check_mark: | :white_check_mark: |
| Subscribe to a Feed | :x: | :white_check_mark: | :white_check_mark: |
| Unsubscribe from a Feed | :x: | :white_check_mark: | :white_check_mark: |
| Rename a Feed | :x: | :x: | :white_check_mark: |
| Add a Category to a Feed | :x: | :x: | :white_check_mark: |
| Remove a Category to a Feed | :x: | :x: | :white_check_mark: |
| Create a new Category | :x: | :x: | :white_check_mark: |
| OPML Export | :x: | :x: | :white_check_mark: |
| OPML Import | :x: | :x: | :white_check_mark: |

## Requirements

FreshAPI assumes that you're using the official docker based integration and running the latest version of TT-RSS, but please do open up an issue if you are using another installation and are running into problems or incompatibilities.

## Installation

1. Clone this repository into your Tiny Tiny RSS plugins directory:

   ```
   cd tt-rss/plugins.local
   git clone https://github.com/eric-pierce/freshapi.git
   ```  
2. Navigate to the Preferences menu in Tiny Tiny RSS, and check the box under "General" titled "Enable API"
3. In Preferences, open the Plugin menu and enable "freshapi"
4. By Default the nginx settings in Tiny Tiny RSS's official docker images do not enable PATH_INFO, which the Google Reader API is built on. In order to enable PATH_INFO for FreshAPI, we'll need to update the official nginx.conf file by making a modification to /etc/nginx/templates/nginx.conf.template. This is very easy to do by mounting the file to your system using docker's volume.
5. First create a new file called ```ttrss-nginx.conf.template``` containing the following:
```
worker_processes auto;
pid /var/run/nginx.pid;

events {
    worker_connections  1024;
}

http {
        include /etc/nginx/mime.types;
        default_type  application/octet-stream;

        access_log /dev/stdout;
        error_log /dev/stderr warn;

        sendfile on;

        index index.php;

        resolver ${RESOLVER} valid=5s;

        server {
                listen 80;
                listen [::]:80;
                root ${APP_WEB_ROOT};

                location ${APP_BASE}/cache {
                        aio threads;
                        internal;
                }

                location ${APP_BASE}/backups {
                        internal;
                }

                rewrite ${APP_BASE}/healthz ${APP_BASE}/public.php?op=healthcheck;

                # Regular PHP handling (without PATH_INFO)
                location ~ .php$ {
                        # regex to split $uri to $fastcgi_script_name and $fastcgi_path_info
                        fastcgi_split_path_info ^(.+?\.php)(/.*)$;

                        # Check that the PHP script exists before passing it
                        try_files $fastcgi_script_name =404;

                        fastcgi_index index.php;
                        include fastcgi.conf;

                        set $backend "${APP_UPSTREAM}:9000";

                        fastcgi_pass $backend;
                }

                # Special handling for PHP files in plugins.local directories (with PATH_INFO)
                location ~ /plugins\.local/.*/api/.*\.php(/|$) {
                        fastcgi_split_path_info ^(.+?\.php)(/.*)$;
                        try_files $fastcgi_script_name =404;

                        # Bypass the fact that try_files resets $fastcgi_path_info
                        # see: http://trac.nginx.org/nginx/ticket/321
                        set $path_info $fastcgi_path_info;
                        fastcgi_param PATH_INFO $path_info;

                        fastcgi_index index.php;
                        include fastcgi.conf;

                        set $backend "${APP_UPSTREAM}:9000";

                        fastcgi_pass $backend;
                }

                location / {
                        try_files $uri $uri/ =404;
                }

        }
}
```

6. Next map the file we just created into the cthulhoo/ttrss-web-nginx:latest image as part of the docker setup.
```yaml
    volumes:
      - $DOCKERDIR/ttrss:/var/www/html:ro
      - $DOCKERDIR/ttrss-nginx.conf.template:/etc/nginx/templates/nginx.conf.template
```
Example full docker-compose portion for TT-RSS's official images and FreshAPI support below:
```yaml
  app:
    container_name: ttrss-app
    image: cthulhoo/ttrss-fpm-pgsql-static:latest
    restart: always
    networks:
      - internal
    depends_on:
      - postgres
    security_opt:
      - no-new-privileges:true
    volumes:
      - $DOCKERDIR/ttrss:/var/www/html
    environment:
      - TZ=$TZ
      - TTRSS_DB_HOST=postgres
      - TTRSS_DB_NAME=ttrss
      - TTRSS_DB_USER=ttrss
      - TTRSS_DB_PASS=$POSTGRES_TTRSS_PASSWORD
      - TTRSS_SELF_URL_PATH=https://feed.$DOMAINNAME/tt-rss
      - TTRSS_PLUGINS=auth_internal,nginx_xaccel,freshapi

  ttrss-updater:
    container_name: ttrss-updater
    image: cthulhoo/ttrss-fpm-pgsql-static:latest
    restart: always
    networks:
      - internal
    depends_on:
      - app
    security_opt:
      - no-new-privileges:true
    volumes:
      - $DOCKERDIR/ttrss:/var/www/html
    environment:
      - TZ=$TZ
      - TTRSS_DB_HOST=postgres
      - TTRSS_DB_NAME=ttrss
      - TTRSS_DB_USER=ttrss
      - TTRSS_DB_PASS=$POSTGRES_TTRSS_PASSWORD
      - TTRSS_SELF_URL_PATH=https://feed.$DOMAINNAME/tt-rss
    command: /opt/tt-rss/updater.sh

  ttrss:
    container_name: ttrss
    image: cthulhoo/ttrss-web-nginx:latest
    restart: unless-stopped
    networks:
      - traefik
      - internal
    depends_on:
      - app
    security_opt:
      - no-new-privileges:true
    ports:
      - 8009:80
    volumes:
      - $DOCKERDIR/ttrss:/var/www/html:ro
      - $DOCKERDIR/ttrss-nginx.conf.template:/etc/nginx/templates/nginx.conf.template
    environment:
      - TZ=$TZ
      - HTTP_PORT=8009
    labels:
      - "traefik.enable=true"
      ## HTTP Routers
      - "traefik.http.routers.ttrss-rtr.entrypoints=https"
      - "traefik.http.routers.ttrss-rtr.rule=Host(`feed.$DOMAINNAME`)"
      - "traefik.http.routers.ttrss-rtr.tls=true"
      ## Middlewares
      - "traefik.http.routers.ttrss-rtr.middlewares=chain-no-auth@file"
      ## HTTP Services
      - "traefik.http.routers.ttrss-rtr.service=ttrss-svc"
      - "traefik.http.services.ttrss-svc.loadbalancer.server.port=80"
```
7. Restart all images
8. When configuring your mobile app, select either "FreshRSS" or "Google Reader API", and use https://yoursubdomain.yourdomain.tld/tt-rss/plugins.local/freshapi/api/greader.php as the server name. Use your standard TT-RSS username and password. If you've enabled 2 Factor Authentication (2FA) generate and user an App Password.

## Compatible Clients

The following clients have been tested. For any reports of additional client funcationality please open an issue with your experiences

| App | Platform | Status | Notes |
| :---: | :---: | :---: | :---: |
| Reeder | iOS | Fully Functional | None |
| NetNewsWire | iOS | Fully Functional | None |
| Fiery Feeds | iOS | Fully Functional | None |
| ReadKit | iOS | Fully Functional | None |
| Fluent Reader | iOS | Not Functional | Investigation Needed |

## Updates

There are no point releases, FreshAPI has a rolling release. If cloned into the plugins.local folder TT-RSS should keep the plugin up to date. 

## Issues & Contributing

Both Issues and Contributions/Pull Requests are welcome and encouraged - please feel free to open either.

## License

This project is licensed under the [GNU AGPL 3 License](http://www.gnu.org/licenses/agpl-3.0.html)

## Acknowledgements

- Major thanks to Tiny Tiny RSS and its developer for making the best self-hosted RSS reader available
- Thanks to the FreshRSS team for both expanding on the Google Reader API and for providing an excellent example of implementation using PHP found at [here](https://github.com/FreshRSS/FreshRSS/blob/edge/p/api/greader.php)

## Disclaimer

This project is not affiliated with or endorsed by Google or Tiny Tiny RSS. Use at your own risk.