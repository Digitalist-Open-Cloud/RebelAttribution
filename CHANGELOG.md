# Changelog

## Unreleased

### Added

- "Attribution" in the reporting menu, with two pages for the goal you pick:
  - Channels: what each channel gets with an attribution model, next to what it gets when the last visit gets the whole conversion, and the role each channel took in the journeys. A channel opens to show its campaigns, search engines, websites or social networks.
  - Journeys: the most common orders of channels that ended in a conversion, and how many visits and days the conversions took.
- Both pages tell when their report was generated.
- The attribution models first visit, shared equally, first and last count most, closer counts more, and last that was not direct.
- The API methods `RebelAttribution.getChannels`, `RebelAttribution.getJourneys`, `RebelAttribution.getVisitsToConversion` and `RebelAttribution.getDaysToConversion`.
