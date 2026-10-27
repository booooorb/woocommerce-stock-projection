import { readFile, readdir, writeFile } from 'node:fs/promises';
import { startEnvironment, project } from './environment.mjs';
import path from 'node:path';

let instance;
try {
  instance = await startEnvironment({ test: true });
  const playground = instance.playground;
  // PHP's parser checks all production sources before exercising WordPress behavior.
  const sources = [path.join(project, 'plugin', 'veryshop-product-analysis.php'), ...(await readdir(path.join(project, 'plugin', 'includes'))).filter(f => f.endsWith('.php')).map(f => path.join(project, 'plugin', 'includes', f))];
  for (const file of sources) {
    const code = await readFile(file, 'utf8');
    const encoded = Buffer.from(code).toString('base64');
    const result = await playground.run({ code: `<?php token_get_all(base64_decode('${encoded}'), TOKEN_PARSE); echo 'Syntax OK';` });
    if (result.errors || result.exitCode) throw new Error(`PHP syntax failure in ${file}: ${result.errors}`);
  }
  let transcript = '';
  for (const mode of ['yes', 'no']) {
    await playground.run({ code: `<?php
      require '/wordpress/wp-load.php';
      foreach (get_option('vpa_test_orders', array()) as $id) { $order = wc_get_order($id); if ($order) $order->delete(true); }
      foreach (array_reverse(get_option('vpa_test_products', array())) as $id) { $product = wc_get_product($id); if ($product) $product->delete(true); }
      update_option('vpa_test_orders', array(), false); update_option('vpa_test_products', array(), false);
      update_option('woocommerce_custom_orders_table_enabled', '${mode}');
      update_option('woocommerce_custom_orders_table_data_sync_enabled', 'no');
    ` });
    console.log(`\nTesting ${mode === 'yes' ? 'HPOS' : 'legacy order storage'}…`);
    const result = await playground.run({ code: `<?php require '/wordpress/wp-load.php'; require '/internal/vpa-dev/integration.php';` });
    const output = result.text ?? new TextDecoder().decode(result.bytes);
    console.log(output);
    transcript += output;
    if (result.errors) console.error(result.errors);
    if (result.exitCode || !output.includes('ALL TESTS PASSED')) process.exitCode = 1;
  }
  await writeFile(path.join(project, '.local', 'test-results.txt'), transcript);
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
} finally {
  if (instance) await instance[Symbol.asyncDispose]();
}
