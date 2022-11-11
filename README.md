# Netlogix.GoogleSearchConsoleInspector

This package adds an inspector tab for document level nodes, which provides insights into Google Search Console data for the given document, provided by the [Google Search Console URL Inspection API](https://developers.google.com/search/blog/2022/01/url-inspection-api).

### Authentication

Please refer to [the flowpack-googleapiclient documentation](https://github.com/Flowpack/Flowpack.GoogleApiClient#authentication).

### Installation

```shell
composer require netlogix/googlesearchconsoleinspector
```

| Supported Neos Versions |
|-------------------------|
| ^ 7.3                   |
| ^ 8.1                   |

### Configuration

Under [Configuration/Settings.yaml](./Configuration/Settings.yaml) add the mapping of Google Search Console Properties to URL prefixes for your site.
Please refer to [Google's documentation](https://developers.google.com/webmaster-tools/v1/urlInspection.index/inspect) for details.

### Usage

On document level nodes a new tab will be added in the left-hand inspector window. It provides the following functionality:

![Example Inspection View](./Documentation/Images/example.png)

The first section offers a button leading directly to Googles own [URL Inspection Tool](https://support.google.com/webmasters/answer/9012289), preconfigured with the current page data.

The second sections provides data about Google's [indexing status](https://support.google.com/webmasters/answer/9012289?hl=en#using_the_tool), [mobile usability](https://developers.google.com/search/mobile-sites/get-started) according to Google, [rich results]((https://developers.google.com/search/docs/appearance/structured-data/intro-structured-data)) availability and the last time the site was crawled.

The third section displays information about the current Google and user [canonicals](https://developers.google.com/search/docs/crawling-indexing/consolidate-duplicate-urls) used.

The fourth section contains a list of Urls for which Google is aware of the current page is being referenced from.

The last section shows information Google has about the [structured data](https://developers.google.com/search/docs/appearance/structured-data/intro-structured-data) the page contains.
