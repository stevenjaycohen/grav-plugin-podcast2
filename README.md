# Podcast 2 Plugin

The **Podcast 2** Plugin is a maintained fork for [Grav CMS](http://github.com/getgrav/grav). This plugin creates the following:

- Admin Page templates for Podcast Channel, Podcast Series, and Podcast Episode
- An iTunes compatible podcast RSS feed, both at the Podcast Channel (all episodes) and Podcast Series (only a series' episodes)

This fork exists to add and maintain Grav 2 compatibility, including support for the Admin2 API-based Page editor. Version 4 requires Grav 2.0 or newer.

## Installation

The preferred installation method is the Grav Package Manager (GPM). From the root of your Grav installation, run:

    bin/gpm install podcast2

Update an existing GPM installation with:

    bin/gpm update podcast2

For a manual installation, download this repository, extract it under `user/plugins`, and rename the extracted directory to `podcast2`.

The directory name must be `podcast2`. Do not replace `user/plugins/podcast`; Podcast 2 has a separate plugin identity and configuration, so both plugins can coexist.

You should now have all the plugin files under

    /your/site/grav/user/plugins/podcast2

> NOTE: This plugin is a modular component for Grav which requires the following to operate:

- [Grav Core](http://github.com/getgrav/grav) 2.0 or newer
- [Breadcrumbs](https://github.com/getgrav/grav-plugin-breadcrumbs)
- [Feed](https://github.com/getgrav/grav-plugin-feed)
- [GetId3](https://github.com/jgonyea/grav-plugin-get-id3), along with its accompanying [getID3 php library](http://www.getid3.org/)

...and any other required plugins the above list requires.

## Configuration

Before configuring this plugin, copy `user/plugins/podcast2/podcast2.yaml` to `user/config/plugins/podcast2.yaml` and only edit that copy.

Here is the default configuration and an explanation of available options:

```yaml
enabled: true
```

_Defaults plugin to **enabled** after installation_

Remote audio validation resolves hostnames through PHP's system resolver before pinning every validated public address into cURL. This supports system resolver configuration such as `/etc/hosts` and split-horizon DNS while continuing to reject local, private, reserved, or otherwise non-public destinations.

## Usage

After installing and enabling the plugins, the admin form will now have three new page templates:

- Podcast Channel
- Podcast Series
- Podcast Episode

The general folder structure using only series will look like this:

- Podcast Channel
  - Podcast Series A
    - Podcast Episode 1
    - Podcast Episode 2
  - Podcast Series B
    - Podcast Episode 3
    - Podcast Episode 4

Using no series will look like this:

- Podcast Channel
  - Podcast Episode 1
  - Podcast Episode 2

Non-series episodes can exist next to series:

- Podcast Channel
  - Podcast Episode 1
  - Podcast Episode 2
  - Podcast Series A
    - Podcast Episode 3
    - Podcast Episode 4

### Podcast Channel

A podcast RSS feed is created at PAGENAME.rss of all episodes underneath the channel, including ones within series. RSS tags are filled with the appropriate data submitted in the admin form for a podcast channel/ episode.

Podcast channel and series page blueprints select Podcast 2's `podcast-feed.rss.twig` through Feed's per-page RSS template setting. Other RSS pages continue to use the Feed plugin's default template.

Example:
If a podcast channel is created at at http://www.example.com/mypodcast, then the url for the podcast RSS feed is found at http://www.example.com/mypodcast.rss

### Podcast Series

Used to group episodes, a podcast series page should be a child page to a podcast channel. Multiple series can exist as child pages of a podcast channel. A podcast RSS feed is also available at SERIESNAME.rss, but it will only contain episodes that are child pages to the series.

Example:
If a podcast series is created at at http://www.example.com/mypodcast/series1, then the url for the podcast RSS feed is found at http://www.example.com/mypodcast/series1.rss

### Podcast Episode

These should be created as child pages of either a podcast channel or a podcast series. Note: Episodes won't show up in the RSS feed if there is no podcast audio attached. Episodes can use the built-in publish_date field to schedule publishing of the page, and the RSS feed will use publish_date, falling back to just date if found.

## Credits

- Thanks to [aleclerc7](https://github.com/aleclerc7) for his initial French and Portugese language translations.
- Thanks to apotropaic for his help with remote file support.
- Thanks to [flaviocopes](https://github.com/flaviocopes) who assisted me with the initial groundwork from the feeds plugin
- RSS structure based on [iTunes RSS Feed Sample](https://help.apple.com/itc/podcasts_connect/#/itcbaf351599)

## To Do

Submit any issues you find to the [issue queue](https://github.com/stevenjaycohen/grav-plugin-podcast2/issues).
