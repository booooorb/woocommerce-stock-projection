import { writeFile } from 'node:fs/promises';
import path from 'node:path';
import { startEnvironment, project } from './environment.mjs';
const minimum = process.argv.includes('--minimum');
const legacy = process.argv.includes('--legacy');
const label = minimum ? 'minimum' : 'current';
const suite = `${label}${legacy ? '-legacy' : ''}`;
let instance, transcript = '';
try {
  instance = await startEnvironment({ test: true, autoLogin: false, profile: `safety-${suite}`, port: (minimum ? 9403 : 9402) + (legacy ? 2 : 0), php: minimum ? '8.0' : '8.3', wp: minimum ? '6.6' : 'latest', woocommerce: minimum ? '9.0.0' : 'latest' });
  const php = async code => {
    const result = await instance.playground.run({ code: `<?php require '/wordpress/wp-load.php'; ${code}` });
    if (result.exitCode || result.errors) throw new Error(result.text + '\n' + result.errors);
    return result.text;
  };
  const phase = async (name, mode) => {
    const out = await php(`define('VPA_SAFETY_PHASE','${name}'); define('VPA_SAFETY_MODE','${mode}'); require '/internal/vpa-dev/safety.php';`);
    transcript += out; console.log(out);
    if (out.includes('FAILED:')) throw new Error('Safety assertion failed');
  };
  for (const mode of [legacy ? 'no' : 'yes']) {
    console.log(`Auditing ${label} runtime / ${mode === 'yes' ? 'HPOS' : 'legacy'}…`);
    await phase('disable', mode); await phase('seed', mode);
    const before = await instance.playground.request({ url: instance.serverUrl + '/' });
    await phase('activate', mode);
    const after = await instance.playground.request({ url: instance.serverUrl + '/' });
    if (before.httpStatusCode !== 200 || after.httpStatusCode !== before.httpStatusCode || after.text.includes('veryshop-product-analysis/assets/')) throw new Error(`Storefront response regression (${before.httpStatusCode}/${after.httpStatusCode}, assets=${after.text.includes('veryshop-product-analysis/assets/')}, response=${after.text.slice(0,180)})`);
    const storefront = 'PASS: Storefront responds normally with no analysis assets on the public page\n'; transcript += storefront; console.log(storefront);
    await phase('audit', mode);
    await php(`require_once ABSPATH.'wp-admin/includes/plugin.php'; activate_plugin('veryshop-product-analysis/veryshop-product-analysis.php');`);
    const auth = JSON.parse(await php(`echo wp_json_encode(get_option('vpa_safety_auth'));`));
    const noNonce = await instance.playground.request({ url: new URL(auth.url, instance.serverUrl).href, method: 'POST', headers: { cookie: auth.cookie, 'content-type': 'application/json' }, body: new TextEncoder().encode(JSON.stringify({ reorder_days: 1 })) });
    const valid = await instance.playground.request({ url: new URL(auth.url, instance.serverUrl).href, method: 'GET', headers: { cookie: auth.cookie, 'x-wp-nonce': auth.nonce } });
    if (![401,403].includes(noNonce.httpStatusCode) || valid.httpStatusCode !== 200) throw new Error(`Cookie/nonce boundary failed (${noNonce.httpStatusCode}/${valid.httpStatusCode})`);
    const nonceResult = 'PASS: Real HTTP cookie authentication rejects a missing REST nonce and accepts a valid nonce\n'; transcript += nonceResult; console.log(nonceResult);
  }
  await php(`require_once ABSPATH.'wp-admin/includes/plugin.php'; deactivate_plugins(array('veryshop-product-analysis/veryshop-product-analysis.php','woocommerce/woocommerce.php'));`);
  await phase('missing_dependency', 'no');
  await phase('missing_dependency_loaded', 'no');
  await writeFile(path.join(project, '.local', `safety-${suite}-results.txt`), transcript);
  console.log(`ALL SAFETY CHECKS PASSED (${suite})`);
} catch (error) {
  console.error(error.message); process.exitCode = 1;
  await writeFile(path.join(project, '.local', `safety-${suite}-results.txt`), transcript + '\nFAILED: '+error.message);
} finally { if (instance) await instance[Symbol.asyncDispose](); }
