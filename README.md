# FreshAPI
A FreshRSS / Google Reader API Plugin for Tiny-Tiny RSS

## Background
Tiny-Tiny RSS is one of the best and most customizable self-hostable RSS readers available, but historically has had only limited compatibility with third party RSS readers through two APIs:
1. [The Official API](https://tt-rss.org/ApiReference/)
2. [The Fever API Plugin](https://github.com/DigitalDJ/tinytinyrss-fever-plugin)

Many mobile applications support one of these protocols, many do not. FreshAPI implements the FreshRSS / Google Reader API to allow Tiny-Tiny RSS to be used with more third party apps, and with more features.

| Feature  | Fever API | Official API | FreshAPI |
| :---: | :---: | :---: | :---: |
| Mark Article as Read/Unread | :white_check_mark: | :white_check_mark: | :white_check_mark: |
| Add/Remove Star from Article | :white_check_mark: | :white_check_mark: | :white_check_mark: |
| Mark Entire Feed/Category as Read | :white_check_mark: | :white_check_mark: | :white_check_mark: |
| Secure Implementation | :x: | :white_check_mark: | :white_check_mark: |
| Subscribe to a Feed | :x: | :white_check_mark: | :white_check_mark: |
| Unsubscribe from a Feed | :x: | :white_check_mark: | :white_check_mark: |
| Rename a Feed | :x: | :x: | :white_check_mark: |
| Add a Category to a Feed | :x: | :x: | :white_check_mark: |
| Remove a Category from a Feed | :x: | :x: | :white_check_mark: |
| Create a new Category | :x: | :x: | :white_check_mark: |
| Rename a Label | :x: | :x: | :white_check_mark: |
| Delete a Label | :x: | :x: | :white_check_mark: |
| OPML Export | :x: | :x: | :white_check_mark: |
| OPML Import | :x: | :x: | :white_check_mark: |

## Requirements

FreshAPI assumes that you're using the official docker based integration and running the latest version of TT-RSS with PostgreSQL as the backend database. A change required for the API to work (enabling PATH_INFO for the plugins.local directory) was [pushed on 9/11/2024](https://gitlab.tt-rss.org/tt-rss/tt-rss/-/merge_requests/61), so any docker images from before that change will need to be updated.

If you are using another installation method you may need to make this PATH_INFO update yourself, more details [here](#non-official-docker-based-installs).

This plugin also uses backend components of the official auth_internal plugin, which is enabled by default with TT-RSS installs. If you've disabled this plugin you'll need to re-enable it to use freshapi.

Please provide details about your setup in any issues you open.

## Installation

1. Clone this repository into your Tiny Tiny RSS plugins directory:
   ```
   cd tt-rss/plugins.local
   git clone https://github.com/eric-pierce/freshapi.git
   ```  
2. Navigate to the Preferences menu in Tiny Tiny RSS, and check the box under "General" titled "Enable API"
   <img src="https://github.com/user-attachments/assets/f79e6fe3-bfb0-4989-a0fb-0bda4ac8b84d" width="800" />
  
3. In Preferences, open the Plugin menu and enable "freshapi"
   <img src="https://github.com/user-attachments/assets/68260e5f-bcb8-4e14-a416-3d31104d9006" width="800" />
  
4. When configuring your mobile app, select either "FreshRSS" or "Google Reader API". You'll need to point your client to your TT-RSS installation, depending on your setup. If you're using a subdomain to host TT-RSS then use ```https://yoursubdomain.yourdomain.com``` instead of ```https://yourdomain.com``` in the requests below.

   If you're using the standard docker installation use ```https://yourdomain.com/tt-rss/plugins.local/freshapi/api/greader.php``` as the server URL. 

   If you're running the TT-RSS app at the website root (not including /tt-rss/ in the URL) by using the APP_WEB_ROOT and APP_BASE environment variables as described [here](https://tt-rss.org/wiki/InstallationNotes/#how-do-i-make-it-run-without-tt-rss-in-the-url-ie-at-website-root) you'll also need to remove tt-rss from the domain you use with clients: ```https://yourdomain.com/plugins.local/freshapi/api/greader.php```

   Use your standard TT-RSS username and password. If you've enabled 2 Factor Authentication (2FA) generate and use an App Password.

## Non-Official Docker based Installs
If you're using an install method other than [the official docker images](https://tt-rss.org/wiki/InstallationNotes/) or [Awesome-TTRSS](https://github.com/HenryQW/Awesome-TTRSS) then you may need to modify your nginx.conf files to support PATH_INFO, which is how the FreshRSS and Google Reader APIs pass requests to the backend server. This is as simple as adding a new "location" ruleset in the .conf file to enable PATH_INFO for the freshapi URL. You can use the nginx.conf files from the [official](https://gitlab.tt-rss.org/tt-rss/tt-rss/-/blob/master/.docker/web-nginx/nginx.conf?ref_type=heads#L53-L72) and [Awesome-TTRSS](https://github.com/HenryQW/Awesome-TTRSS/blob/main/src/ttrss.nginx.conf#L38-L46) installs as a guide, and there's a discussion about enabling this [here](https://github.com/eric-pierce/freshapi/issues/7#issuecomment-2395496729).

### NixOS

If you're using NixOS with Postgres, use the following as a template:

`configuration.nix`

```
   services.tt-rss = {
     enable = true;
     database = {
       type = "pgsql";
     };
     selfUrlPath = "http://<Host>";
     virtualHost = "<Host>";
     pluginPackages = [ (pkgs.callPackage ./freshapi.nix {}) ];
   };
```

PATH_INFO nginx config in `configuration.nix`

```
     virtualHosts."<HOST from above>" = {
       locations."~ /plugins\\.local/.*/api/.*\\.php(/|$)" = {
         extraConfig = ''
           fastcgi_split_path_info ^(.+\.php)(/.+)$;
           try_files $fastcgi_script_name =404;
           set $path_info $fastcgi_path_info;
           fastcgi_param PATH_INFO $path_info;
           fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
           fastcgi_pass unix:/run/phpfpm/tt-rss.sock;
           include ${config.services.nginx.package}/conf/fastcgi_params;
           fastcgi_index index.php;
         '';
       };
```

`freshapi.nix` should contain:

```
{ lib, stdenv, fetchFromGitHub, tt-rss }:

stdenv.mkDerivation {
  pname = "tt-rss-plugin-freshapi";
  version = "0.01";

  src = fetchFromGitHub {
    owner = "eric-pierce";
    repo = "freshapi";
    rev = "942b1c37ef2035444c3a22a21e225f3ece73c705"; # this and the line below needs to be updated based on the git sha of freshapi you want to install
    sha256 = "sha256-XWzEya+1A/UtwTL+HseoX8trEypNjHurK/VF3k2seMQ";
  };

  installPhase = ''
    mkdir -p $out/freshapi
    cp -r api init.php $out/freshapi
  '';

  meta = with lib; {
    description = "Tiny Tiny RSS FreshAPI Plugin";
    longDescription = ''
      A FreshRSS / Google Reader API Plugin for Tiny-Tiny RSS
    '';
    license = with licenses; [ agpl3Only ];
    homepage = "https://github.com/eric-pierce/freshapi";
    maintainers = with maintainers; [ bigloser ];
    inherit (tt-rss.meta) platforms;
  };
}
```

## Compatible Clients

The following clients have been tested, but FreshAPI should be compatible with any FreshRSS or Google Reader API compatible client. If you run into any issues or would like to report a client as working, please [open up an issue](https://github.com/eric-pierce/freshapi/issues/new/choose).

| App | Platform | Status | Notes |
| :---: | :---: | :---: | :---: |
| [Reeder Classic](https://reederapp.com/classic/) | iOS, macOS | Fully Functional | None |
| [NetNewsWire](https://netnewswire.com/) | iOS, macOS | Fully Functional | None |
| [lire](https://lireapp.com/) | iOS, macOS | Fully Functional | None |
| [Fiery Feeds](https://voidstern.net/fiery-feeds) | iOS, macOS | Fully Functional | None |
| [ReadKit](https://readkit.app/) | iOS, macOS | Fully Functional | None |
| [Fluent Reader Lite](https://github.com/yang991178/fluent-reader-lite) | iOS | Fully Functional | 1. Fluent Reader Lite supports Max 1500 unread articles across all types (unread, starred, etc). <br>2. The article count for Fluent Reader is oftentimes inaccurate. This is a Fluent Reader issue (tracked here https://github.com/yang991178/fluent-reader/issues/537), not a FreshAPI issue. |
| [Fluent Reader](https://github.com/yang991178/fluent-reader) | macOS, Windows | Fully Functional | The article count for Fluent Reader is oftentimes inaccurate. This is a Fluent Reader issue (tracked here https://github.com/yang991178/fluent-reader/issues/537), not a FreshAPI issue. |
| [FeedMe](https://github.com/seazon/FeedMe) | Android | Fully Functional | None |
| [Read You](https://github.com/Ashinch/ReadYou) | Android | Fully Functional | None |
| [News Flash](https://gitlab.com/news-flash/news_flash_gtk) | Linux | Fully Functional | None |

## Direct API Usage
FreshRSS and Google Reader compatible clients can natively use this API, but if you'd like to access it directly you can do so by making cURL calls. The Google Reader API spec is well documented, but here is an example of API usage:

1. Authorization

Make a POST cURL call to your server's ClientLogin Endpoint using your TT-RSS username and password. If you have enabled 2FA you can use an App password generated in the TT-RSS preferences pane
```console
foo@bar:~$ curl -X POST --data 'Email=yourusername&Passwd=yourpassword' https://example.com/tt-rss/plugins.local/freshapi/api/greader.php/accounts/ClientLogin/
```
This will return your authorization credentials in the format 'username/session_id"
```
SID=yourusername/r4ih6gt412opqh11gptp3hodd6
LSID=
Auth=yourusername/r4ih6gt412opqh11gptp3hodd6
```

2. Calling the API Directly

Take the username/session_id combination from step 1 and make a new cURL call to the endpoint you'd like to use. In this case we'll ask to export the subscription, folder, and tag OPML through the subscription export feature:

```console
foo@bar:~$ curl -X POST --header 'Authorization: GoogleLogin auth=yourusername/r4ih6gt412opqh11gptp3hodd6' https://example.com/tt-rss/plugins.local/freshapi/api/greader.php/reader/api/0/subscription/export
```
In the example above the cURL call will return your subscription OPML in XML form.

## Updates

FreshAPI uses a rolling release approach, though I'll increment the version number for significant changes. If cloned into the plugins.local folder TT-RSS should keep the plugin up to date.

## Issues & Contributing

Both Issues and Contributions and Pull Requests are welcome and encouraged - please feel free to open either.

## License

This project is licensed under the [GNU AGPL 3 License](http://www.gnu.org/licenses/agpl-3.0.html)

## Acknowledgements

- Major thanks to Tiny Tiny RSS and its developer Andrew for making the best self-hosted RSS reader available
- Thanks to the FreshRSS team for both expanding on the Google Reader API and for providing an excellent example of implementation using PHP found [here](https://github.com/FreshRSS/FreshRSS/blob/edge/p/api/greader.php)

## Disclaimer

This project is not affiliated with or endorsed by FreshRSS, Google, or Tiny Tiny RSS. Use at your own risk.
