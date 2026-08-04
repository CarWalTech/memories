import { expect, type PlaywrightTestArgs } from '@playwright/test';

/**
 * Instance under test. Defaults to the upstream dev container so the existing
 * `make dev-*` flow is unchanged; override to point the suite at a real
 * deployment (see e2e/chunking.spec.ts, which needs a library big enough to
 * actually chunk).
 */
export const BASE_URL = process.env.MEMORIES_E2E_URL ?? 'http://localhost:8080';
export const USER = process.env.MEMORIES_E2E_USER ?? 'admin';
export const PASS = process.env.MEMORIES_E2E_PASS ?? 'password';

/**
 * Root of the Memories app on the instance under test.
 *
 * Deliberately without the /index.php prefix: the Vue router's base is
 * /apps/memories/, so an /index.php/apps/memories/ URL loads the Nextcloud
 * shell but matches no route, leaving the router outlet empty. Override with
 * MEMORIES_E2E_INDEX_PHP=1 for instances without URL rewriting.
 */
export const APP_ROOT = `${BASE_URL}${process.env.MEMORIES_E2E_INDEX_PHP === '1' ? '/index.php' : ''}/apps/memories`;

/**
 * Whether tests that create, modify or delete real data may run.
 *
 * Off by default: the suite is now pointable at a real instance, and a
 * destructive test there is not recoverable. Opt in with
 * MEMORIES_E2E_DESTRUCTIVE=1, and only against disposable data.
 */
export const ALLOW_DESTRUCTIVE = process.env.MEMORIES_E2E_DESTRUCTIVE === '1';

/**
 * Authenticate with HTTP Basic instead of the login form.
 *
 * Nextcloud accepts an app password over Basic auth, which is how the suite
 * can run against a real instance without the account's actual login password
 * (and without tripping 2FA). Enabled by setting MEMORIES_E2E_BASIC=1.
 */
export const USE_BASIC = process.env.MEMORIES_E2E_BASIC === '1';

/**
 * Playwright context options needed for Basic auth, if enabled.
 *
 * The Authorization header is set explicitly rather than via httpCredentials:
 * Playwright only answers a 401 challenge, and Nextcloud redirects browser
 * requests to its login page instead of challenging, so the credentials would
 * never be offered.
 */
export const contextOptions = USE_BASIC
  ? {
      extraHTTPHeaders: {
        Authorization: `Basic ${Buffer.from(`${USER}:${PASS}`).toString('base64')}`,
        'OCS-APIRequest': 'true',
      },
    }
  : {};

export function login(route: string) {
  return async ({ page }: PlaywrightTestArgs) => {
    page.on('console', (msg) => {
      switch (msg.type()) {
        case 'error':
          console.error('js_console=' + msg.text());
          break;
        case 'warning':
          console.warn('js_console=' + msg.text());
          break;
        default:
          console.log('js_console=' + msg.text());
      }
    });

    await page.setViewportSize({ width: 800, height: 600 });
    await page.goto(APP_ROOT + route);

    // Already authenticated (Basic auth, or reused storage state)
    if (!(await page.locator('#user').count())) {
      await expect(page).toHaveURL(APP_ROOT + route);
      return;
    }

    if (USE_BASIC) {
      throw new Error('Basic auth was requested but the login form was served; check MEMORIES_E2E_USER/PASS');
    }

    await page.locator('#user').click();
    await page.locator('#user').fill(USER);
    await page.locator('#user').press('Tab');
    await page.locator('#password').fill(PASS);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(APP_ROOT + route);
  };
}
