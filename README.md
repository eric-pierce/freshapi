# FreshAPI
A FreshRSS / Google Reader API Plugin for Tiny-Tiny RSS

## Background
Tiny-Tiny RSS is one of the best and most customizable self-hostable RSS Readers, but has limited compatibility with third party RSS readers, which were only available through:
1. [The Official API](https://tt-rss.org/ApiReference/)
2. [The Fever API Plugin](https://github.com/DigitalDJ/tinytinyrss-fever-plugin)

While many mobile applications support one of these protocols, many do not. FreshAPI implements the FreshRSS / Google Reader API to allow Tiny-Tiny RSS to be used with more third party apps, and with more features.

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

FreshAPI assumes that you're using the official docker based integration and running the latest version of TT-RSS. A change required for the API to work (enabling PATH_INFO for the plugins.local directory) was [pushed on 9/11/2024](https://gitlab.tt-rss.org/tt-rss/tt-rss/-/merge_requests/61), so any docker images from before that change will need to be updated.

If you are using another installation and are running into problems or incompatibilities please open an Issue and I'll still work to address.

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
  
4. When configuring your mobile app, select either "FreshRSS" or "Google Reader API", and use https://example.com/tt-rss/plugins.local/freshapi/api/greader.php as the server name. Use your standard TT-RSS username and password. If you've enabled 2 Factor Authentication (2FA) generate and use an App Password.

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

## Updates

FreshAPI uses a rolling release approach, though I'll increment the version number for significant changes. If cloned into the plugins.local folder TT-RSS should keep the plugin up to date.

## Issues & Contributing

Both Issues and Contributions and Pull Requests are welcome and encouraged - please feel free to open either.

## License

This project is licensed under the [GNU AGPL 3 License](http://www.gnu.org/licenses/agpl-3.0.html)

## Acknowledgements

- Major thanks to Tiny Tiny RSS and its developer Fox for making the best self-hosted RSS reader available
- Thanks to the FreshRSS team for both expanding on the Google Reader API and for providing an excellent example of implementation using PHP found at [here](https://github.com/FreshRSS/FreshRSS/blob/edge/p/api/greader.php)

## Disclaimer

This project is not affiliated with or endorsed by FreshRSS, Google, or Tiny Tiny RSS. Use at your own risk.
