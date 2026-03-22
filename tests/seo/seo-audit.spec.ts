import { test, expect } from '@playwright/test';

const pages = [
  { name: 'Homepage', path: '/' },
  // Add your event pages here, e.g.:
  // { name: 'Event Page', path: '/events/sample-event/' },
];

test.describe('SEO Audit - Meta Tags', () => {
  for (const p of pages) {
    test(`${p.name}: has essential meta tags`, async ({ page }) => {
      await page.goto(p.path);

      // Title exists and is non-empty
      const title = await page.title();
      expect(title.length).toBeGreaterThan(0);
      expect(title.length).toBeLessThanOrEqual(70);

      // Meta description
      const metaDesc = page.locator('meta[name="description"]');
      if (await metaDesc.count()) {
        const content = await metaDesc.getAttribute('content');
        expect(content?.length).toBeGreaterThan(0);
        expect(content!.length).toBeLessThanOrEqual(160);
      }

      // Canonical URL
      const canonical = page.locator('link[rel="canonical"]');
      expect(await canonical.count()).toBeGreaterThan(0);

      // Viewport meta (mobile-friendly)
      const viewport = page.locator('meta[name="viewport"]');
      expect(await viewport.count()).toBeGreaterThan(0);

      // Language attribute on html
      const lang = await page.locator('html').getAttribute('lang');
      expect(lang).toBeTruthy();
    });

    test(`${p.name}: has proper heading hierarchy`, async ({ page }) => {
      await page.goto(p.path);

      // Exactly one H1
      const h1Count = await page.locator('h1').count();
      expect(h1Count).toBe(1);

      // H1 is non-empty
      const h1Text = await page.locator('h1').first().textContent();
      expect(h1Text?.trim().length).toBeGreaterThan(0);
    });

    test(`${p.name}: images have alt attributes`, async ({ page }) => {
      await page.goto(p.path);

      const images = page.locator('img');
      const count = await images.count();

      for (let i = 0; i < count; i++) {
        const img = images.nth(i);
        const alt = await img.getAttribute('alt');
        const src = await img.getAttribute('src');
        // Every visible image should have alt (can be empty for decorative)
        expect(alt, `Image missing alt: ${src}`).not.toBeNull();
      }
    });

    test(`${p.name}: Open Graph tags present`, async ({ page }) => {
      await page.goto(p.path);

      const ogTitle = page.locator('meta[property="og:title"]');
      const ogDesc = page.locator('meta[property="og:description"]');
      const ogType = page.locator('meta[property="og:type"]');
      const ogUrl = page.locator('meta[property="og:url"]');

      expect(await ogTitle.count(), 'Missing og:title').toBeGreaterThan(0);
      expect(await ogType.count(), 'Missing og:type').toBeGreaterThan(0);
      expect(await ogUrl.count(), 'Missing og:url').toBeGreaterThan(0);
    });
  }
});

test.describe('SEO Audit - Performance & Accessibility', () => {
  for (const p of pages) {
    test(`${p.name}: no broken internal links`, async ({ page }) => {
      await page.goto(p.path);
      const baseURL = process.env.WP_BASE_URL || 'https://staging.lukaszkomar.com';

      const links = page.locator(`a[href^="${baseURL}"], a[href^="/"]`);
      const count = await links.count();
      const broken: string[] = [];

      // Check first 20 internal links to avoid slow tests
      const limit = Math.min(count, 20);
      for (let i = 0; i < limit; i++) {
        const href = await links.nth(i).getAttribute('href');
        if (!href) continue;

        const url = href.startsWith('/') ? `${baseURL}${href}` : href;
        const response = await page.request.get(url);
        if (response.status() >= 400) {
          broken.push(`${response.status()} - ${url}`);
        }
      }

      expect(broken, `Broken links found:\n${broken.join('\n')}`).toHaveLength(0);
    });

    test(`${p.name}: robots meta allows indexing`, async ({ page }) => {
      await page.goto(p.path);

      const robotsMeta = page.locator('meta[name="robots"]');
      if (await robotsMeta.count()) {
        const content = await robotsMeta.getAttribute('content');
        expect(content).not.toContain('noindex');
      }
    });

    test(`${p.name}: page loads within 5 seconds`, async ({ page }) => {
      const start = Date.now();
      await page.goto(p.path, { waitUntil: 'load' });
      const loadTime = Date.now() - start;

      expect(loadTime, `Page load took ${loadTime}ms`).toBeLessThan(5000);
    });

    test(`${p.name}: has structured data (JSON-LD)`, async ({ page }) => {
      await page.goto(p.path);

      const jsonLd = page.locator('script[type="application/ld+json"]');
      // Structured data is recommended but not required for all pages
      if (await jsonLd.count()) {
        const content = await jsonLd.first().textContent();
        expect(() => JSON.parse(content!)).not.toThrow();
      }
    });
  }
});

test.describe('SEO Audit - Technical', () => {
  test('robots.txt is accessible', async ({ request }) => {
    const response = await request.get('/robots.txt');
    expect(response.status()).toBe(200);
    const body = await response.text();
    expect(body).toContain('User-agent');
  });

  test('sitemap.xml is accessible', async ({ request }) => {
    const response = await request.get('/sitemap.xml');
    // Might be sitemap_index.xml with Yoast/RankMath
    if (response.status() === 404) {
      const indexResponse = await request.get('/sitemap_index.xml');
      expect(indexResponse.status()).toBe(200);
    } else {
      expect(response.status()).toBe(200);
    }
  });
});
