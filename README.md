# Rebel Attribution

[![PHPCS check](https://github.com/Digitalist-Open-Cloud/RebelAttribution/actions/workflows/phpcs.yaml/badge.svg)](https://github.com/Digitalist-Open-Cloud/RebelAttribution/actions/workflows/phpcs.yaml)
[![Semgrep OSS scan](https://github.com/Digitalist-Open-Cloud/RebelAttribution/actions/workflows/semgrep.yaml/badge.svg)](https://github.com/Digitalist-Open-Cloud/RebelAttribution/actions/workflows/semgrep.yaml)
[![Test plugin with Matomo](https://github.com/Digitalist-Open-Cloud/RebelAttribution/actions/workflows/matomo.yaml/badge.svg)](https://github.com/Digitalist-Open-Cloud/RebelAttribution/actions/workflows/matomo.yaml)
[![PHPStan check](https://github.com/Digitalist-Open-Cloud/RebelAttribution/actions/workflows/phpstan.yaml/badge.svg)](https://github.com/Digitalist-Open-Cloud/RebelAttribution/actions/workflows/phpstan.yaml)

A Matomo plugin that shows which channels earn the conversions of a goal. Matomo gives a
conversion to the last visit before it. This plugin looks at every visit the visitor made in the
30 days before converting, so a channel that brings visitors who convert later gets its share.

## Install

Activate the plugin. It needs no change to the tracking code. The reports are filled when Matomo
archives; to fill them for days that are archived already, run:

```sh
./console core:invalidate-report-data --dates=<from>,<to> --plugin=RebelAttribution
./console core:archive
```

## The pages

**Attribution** in the reporting menu has two pages. Pick a goal at the top of either; a website
with ecommerce also has its orders to pick. Revenue is shown next to conversions when the goal
has any.

### Channels

Each channel has two bars: the conversions it gets when the last visit gets it all, and what it
gets with the model you pick. The percentage is the difference between the two.

| Model | How a conversion is shared |
|---|---|
| First and last count most | The first and the last visit get 40% each, the visits between them share 20% |
| First visit | All to the first visit of the journey |
| Shared equally | The same share to every visit |
| Closer counts more | A visit counts half as much as the visit after it |
| Last that was not direct | All to the last visit that was not a direct entry |

Under the bars, **The role of each channel** shows for the conversions a channel took part in
whether it was the first visit of the journey, one in the middle, the last, or the only one.

### Journeys

The most common orders of channels that ended in a conversion, the oldest visit first. Visits in
a row from one channel are one step with their number. Below are how many visits the conversions
took and how many days passed from the first visit of the journey.

## What counts

- A channel is a channel type of Matomo: direct entry, search engines, websites, campaigns,
  social networks and AI assistants.
- A journey is the visits of the visitor that started in the 30 days before the conversion, the
  visit of the conversion included. It keeps the latest ten visits.
- A visitor is who Matomo recognises as one: by the visitor ID, or the user ID when one is set.
  A visitor who changes device or clears cookies starts a new journey.
- A segment picks conversions by the visit they happened in. The earlier visits of those
  journeys count whether they match the segment or not.

## API

`RebelAttribution.getChannels`, `getJourneys`, `getVisitsToConversion` and `getDaysToConversion`
need view access to the website. They take `idSite`, `period`, `date`, `idGoal` (0 for ecommerce
orders) and optionally `segment`. `getChannels` also takes `model`: `position`, `first`, `linear`,
`decay` or `lastNotDirect`.

## License

GPL v3 or later, see [LICENSE.md](LICENSE.md).
