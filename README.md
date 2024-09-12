# FreshAPI
A FreshRSS / Google Reader API Plugin for Tiny-Tiny RSS

## Background
Tiny-Tiny RSS is one of the most featureful and customizable self-hostable RSS Reader server implementations, but historically has had limited compatibility with third party RSS readers, which were only available through:
1. [The Official API](https://tt-rss.org/ApiReference/)
2. [The Fever API Plugin](https://github.com/DigitalDJ/tinytinyrss-fever-plugin)

While many mobile applications support either of these protocols, many do not. FreshAPI's goal is to be the bridge between Tiny-Tiny RSS and these fantastic mobile applications, while offering more functionality than either of the historically supported protocols.

| Feature  | Fever API | Official API | FreshAPI | FreshAPI Comments |
| :---: | :---: | :---: | :---: | :---: |
| Mark Article as Read/Unread | :white_check_mark: | :white_check_mark: | :white_check_mark: | |
| Add/Remove Star from Article | :white_check_mark: | :white_check_mark: | :white_check_mark: | |
| Mark Entire Feed/Category as Read | :white_check_mark: | :white_check_mark: | :white_check_mark: | |
| Secure Implementation | :x: | :white_check_mark: | :white_check_mark: | |
| Subscribe to a Feed | :x: | :white_check_mark: | :white_check_mark: | |
| Unsubscribe from a Feed | :x: | :white_check_mark: | :white_check_mark: | |
| Rename a Feed | :x: | :x: | :white_check_mark: | |
| Add a Category to a Feed | :x: | :x: | :white_check_mark: | |
| Remove a Category from a Feed | :x: | :x: | :white_check_mark: | |
| Create a new Category | :x: | :x: | :white_check_mark: | |
| OPML Export | :x: | :x: | :white_check_mark: | |
| OPML Import | :x: | :x: | :white_check_mark: | |
| Trigger Individual Feed Update | :x: | :white_check_mark: | :x: | This feature not supported by the Google Reader API |

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

**NOTE Steps 4-7 will not be needed after [this merge request](https://gitlab.tt-rss.org/tt-rss/tt-rss/-/merge_requests/61) which was approved on 9/11 makes its way to the official docker images.**

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

                # Allow PATH_INFO for PHP files in plugins.local directories with an /api/ sub directory
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

6. The official Docker setup for Tiny-Tiny RSS consists of three containers. You will need to map the file you just created into the cthulhoo/ttrss-web-nginx:latest container as part of the docker setup.
```yaml
    volumes:
      - $DOCKERDIR/ttrss:/var/www/html:ro
      - $DOCKERDIR/ttrss-nginx.conf.template:/etc/nginx/templates/nginx.conf.template
```
Example full docker-compose portion for all three of TT-RSS's official containers plus postgres as a backend database using [Traefik](https://github.com/traefik/traefik) and FreshAPI support below. 
```yaml
########################### NETWORKS
networks:
  traefik:
    name: traefik
    external: true
  default:
    driver: bridge
  internal:
    external: false

########################### SECRETS
secrets:
  postgres_root_password:
    file: $SECRETSDIR/postgres_root_password

########################### SERVICES
services:
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

  postgres:
    container_name: postgres
    image: postgres:alpine
    restart: unless-stopped
    networks:
      - internal
    security_opt:
      - no-new-privileges:true
    ports:
      - "127.0.0.1:5432:5432"
    secrets:
      - postgres_root_password
    volumes:
      - $DOCKERDIR/postgres:/var/lib/postgresql/data
    environment:
      - TZ=$TZ
      - PUID=$PUID
      - PGID=$PGID
      - POSTGRES_USER=$POSTGRES_USER
      - POSTGRES_PASSWORD_FILE=/run/secrets/postgres_root_password
```
7. Restart all images
8. When configuring your mobile app, select either "FreshRSS" or "Google Reader API", and use https://example.com/tt-rss/plugins.local/freshapi/api/greader.php as the server name. Use your standard TT-RSS username and password. If you've enabled 2 Factor Authentication (2FA) generate and use an App Password.

## Compatible Clients

The following clients have been or will be tested. For any reports of additional client funcationality please open an issue with your experiences.

| App | Platform | Status | Notes |
| :---: | :---: | :---: | :---: |
| Reeder | iOS, macOS | Fully Functional | None |
| NetNewsWire | iOS | Fully Functional | None |
| Fiery Feeds | iOS | Fully Functional | None |
| ReadKit | iOS | Fully Functional | None |
| Fluent Reader | iOS | Not Functional | Investigation Needed |
| FeedMe | Android | Not Tested | Testing Planned |
| Readrops | Android | Not Tested | Testing Planned |

## Updates

FreshAPI uses a rolling release approach, though I'll increment the version number for significant changes. If cloned into the plugins.local folder TT-RSS should keep the plugin up to date.

## Issues & Contributing

Both Issues and Contributions and Pull Requests are welcome and encouraged - please feel free to open either.

If you'd like to donate to this project you can do so here:

<a href="https://www.buymeacoffee.com/ericpierce" target="_blank"><img src="https://cdn.buymeacoffee.com/buttons/v2/default-yellow.png" alt="Buy Me A Coffee" style="height: 60px !important;width: 217px !important;" ></a>

## License

This project is licensed under the [GNU AGPL 3 License](http://www.gnu.org/licenses/agpl-3.0.html)

## Acknowledgements

- Major thanks to Tiny Tiny RSS and its developer Fox for making the best self-hosted RSS reader available
- Thanks to the FreshRSS team for both expanding on the Google Reader API and for providing an excellent example of implementation using PHP found at [here](https://github.com/FreshRSS/FreshRSS/blob/edge/p/api/greader.php)

## Disclaimer

This project is not affiliated with or endorsed by FreshRSS, Google, or Tiny Tiny RSS. Use at your own risk.