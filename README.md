This is an updated port of the [Python](http://python.org/) [yweather](http://crunchbang.org/forums/viewtopic.php?id=25691) pipe menu for [OpenBox](http://openbox.org/).  I rewrote it in [PHP](http://php.net/), which made it easier to write and should make it easier to update.

The notable features from the original code remain:

1. Reading from [Yahoo!](http://yahoo.com/) weather services.
1. Caching.
1. Metric and imperial units.

However, the new Yahoo! API seems not to support metric units, so the code uses a [regexp](http://en.wikipedia.org/wiki/Regular_expressions).

A new feature includes downloading in [RSS](http://en.wikipedia.org/wiki/RSS) (like the Python code) or [JSON](http://en.wikipedia.org/wiki/JSON) (the default, as it's much more lightweight).
