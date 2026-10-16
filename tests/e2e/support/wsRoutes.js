// @ts-check

/**
 * Failures of the real server for one ws.php call: the next call of a method
 * goes out with one field replaced, so the server refuses it for a reason of
 * its own, or is dropped as a lost connection would be. Later calls pass.
 */

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} method
 * @param {(route: import('@playwright/test').Route, body: string) => Promise<void>} handle
 */
async function onNextCall(page, method, handle) {
  let done = false;
  const handler = async (/** @type {import('@playwright/test').Route} */ route) => {
    const body = route.request().postData() || '';
    if (done || !body.includes(`method=${encodeURIComponent(method)}`)) {
      return route.continue();
    }
    done = true;
    // unrouted only after the route is handled: unroute() itself would let a pending route through
    await handle(route, body);
    await page.unroute('**/ws.php*', handler);
  };
  await page.route('**/ws.php*', handler);
}

/** @param {import('@playwright/test').Page} page @param {string} method @param {string} field @param {string} value */
async function corruptNextCall(page, method, field, value) {
  await onNextCall(page, method, (route, body) =>
    route.continue({ postData: body.replace(new RegExp(`(^|&)${field}=[^&]*`), `$1${field}=${value}`) }));
}

/** @param {import('@playwright/test').Page} page @param {string} method */
async function abortNextCall(page, method) {
  await onNextCall(page, method, (route) => route.abort('failed'));
}

module.exports = { corruptNextCall, abortNextCall };
