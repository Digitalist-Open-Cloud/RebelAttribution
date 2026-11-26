describe('RebelAttribution', function () {
    this.fixture = 'Piwik\\Plugins\\RebelAttribution\\tests\\Fixtures\\SiteWithJourneys';

    const params = 'idSite=1&period=day&date=2024-03-10';

    before(function () {
        testEnvironment.pluginsToLoad = ['RebelAttribution'];
        testEnvironment.save();
    });

    async function open(subcategory, selector) {
        await page.goto(`?module=CoreHome&action=index&${params}#?${params}&category=RebelAttribution_Attribution&subcategory=RebelAttribution_${subcategory}`);
        await page.waitForSelector(selector);
        await page.waitForNetworkIdle();
    }

    // The rows that are shown, each as its texts.
    function rows(selector) {
        return page.evaluate((rowSelector) => [...document.querySelectorAll(rowSelector)]
            .map((row) => row.innerText.replace(/\s+/g, ' ').trim()), selector);
    }

    it('should have a page for channels and one for journeys in the menu, with the icon of the plugin', async function () {
        await open('Channels', '.rebelAttributionChannel');

        const menu = await page.evaluate(() => {
            const category = document.querySelector('#secondNavBar .rebelAttributionIcon').closest('li');
            // The first link is the one that opens the category for screen readers.
            return [...category.querySelectorAll('ul a')].map((link) => link.textContent.trim()).slice(1);
        });

        expect(menu).to.deep.equal(['Channels', 'Journeys']);
    });

    it('should compare what each channel gets today with what the model gives it', async function () {
        expect(await rows('.rebelAttributionChannel')).to.deep.equal([
            'Search Engines 2 conversions · $20 2.7 conversions · $27 +35%',
            'Direct Entry 2 conversions · $20 0.9 conversions · $9 −55%',
            'Social Networks 0 conversions 0.4 conversions · $4 +',
        ]);
    });

    it('should show the role of each channel', async function () {
        const roles = await page.evaluate(() => [...document.querySelectorAll('.rebelAttributionRole')]
            .map((row) => row.querySelector('strong').textContent + ': '
                + [...row.querySelectorAll('.rebelAttributionStack span')].map((part) => part.title).join(', ')));

        expect(roles).to.deep.equal([
            'Search Engines: Introduced: 1 conversion, Assisted: 1 conversion, The only visit: 2 conversions',
            'Direct Entry: Closed: 2 conversions',
            'Social Networks: Introduced: 1 conversion',
        ]);
    });

    it('should count again when another model is picked', async function () {
        await page.click('.rebelAttributionModel[data-model="first"]');
        await page.waitForNetworkIdle();

        expect(await page.evaluate(() => document.querySelector('.rebelAttributionModelHelp').textContent))
            .to.equal('The first visit of the journey gets the whole conversion.');
        expect(await rows('.rebelAttributionChannel')).to.deep.equal([
            'Search Engines 2 conversions · $20 3 conversions · $30 +50%',
            'Social Networks 0 conversions 1 conversion · $10 +',
            'Direct Entry 2 conversions · $20 0 conversions −100%',
        ]);
    });

    it('should draw the journeys to a conversion as chains of channels', async function () {
        await open('Journeys', '.rebelAttributionJourney');

        expect(await rows('.rebelAttributionJourney')).to.have.members([
            'Search Engines → Signed up 2 conversions · $20',
            'Search Engines → Direct Entry → Signed up 1 conversion · $10',
            'Social Networks → Search Engines → Direct Entry → Signed up 1 conversion · $10',
        ]);
    });

    it('should show how many visits and days the conversions took', async function () {
        expect(await rows('.rebelAttributionVisits .rebelAttributionGroup'))
            .to.deep.equal(['1 visit 2', '2 visits 1', '3 visits 1', '4 visits or more 0']);
        expect(await rows('.rebelAttributionDays .rebelAttributionGroup'))
            .to.deep.equal(['Less than a day 2', '1–3 days 1', '4–7 days 1', '8–14 days 0', '15–30 days 0']);
    });
});
