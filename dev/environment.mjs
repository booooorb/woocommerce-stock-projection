import { mkdir, readFile, access } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { runCLI } from '@wp-playground/cli';

export const project = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

export async function startEnvironment({ test = false, profile, php = '8.3', wp = 'latest', woocommerce = 'latest', port, autoLogin = true } = {}) {
  const site = path.join(project, '.local', profile || (test ? 'test-wordpress' : 'wordpress'));
  await mkdir(site, { recursive: true });
  const hasWordPress = await access(path.join(site, 'wp-load.php')).then(() => true, () => false);
  const muPlugin = await readFile(path.join(project, 'dev', 'local-mail.php'), 'utf8');
  const seed = `<?php require '/wordpress/wp-load.php'; require '/internal/vpa-dev/seed.php';`;
  return runCLI({
    command: 'server',
    port: port || (test ? 9401 : 9400),
    workers: 1,
    php, wp,
    login: autoLogin,
    verbosity: 'normal',
    wordpressInstallMode: hasWordPress ? 'install-from-existing-files-if-needed' : 'download-and-install',
    'define-bool': { WP_DEBUG: true, WP_DEBUG_LOG: true, WP_DEBUG_DISPLAY: false, DISABLE_WP_CRON: true },
    'mount-before-install': [
      { hostPath: site, vfsPath: '/wordpress' },
      { hostPath: path.join(project, 'dev'), vfsPath: '/internal/vpa-dev' },
    ],
    mount: [{ hostPath: path.join(project, 'plugin'), vfsPath: '/wordpress/wp-content/plugins/veryshop-product-analysis' }],
    blueprint: {
      landingPage: '/wp-admin/admin.php?page=veryshop-product-analysis',
      steps: [
        { step: 'mkdir', path: '/wordpress/wp-content/mu-plugins' },
        { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/veryshop-local-mail.php', data: muPlugin + (test ? "\nadd_filter('action_scheduler_allow_async_request_runner', '__return_false');\n" : '') },
        { step: 'installPlugin', pluginData: woocommerce === 'latest' ? { resource: 'wordpress.org/plugins', slug: 'woocommerce' } : { resource: 'url', url: `https://downloads.wordpress.org/plugin/woocommerce.${woocommerce}.zip` }, options: { activate: true }, ifAlreadyInstalled: 'skip' },
        { step: 'activatePlugin', pluginPath: '/wordpress/wp-content/plugins/woocommerce/woocommerce.php' },
        { step: 'activatePlugin', pluginPath: '/wordpress/wp-content/plugins/veryshop-product-analysis/veryshop-product-analysis.php' },
        { step: 'setSiteOptions', options: { blogname: 'VeryShop · Local development', timezone_string: 'America/Vancouver', woocommerce_store_address: 'Development store', woocommerce_store_city: 'Vancouver', woocommerce_default_country: 'CA:BC', woocommerce_currency: 'CAD', woocommerce_allow_tracking: 'no', woocommerce_show_marketplace_suggestions: 'no', woocommerce_onboarding_profile: { completed: true } } },
        ...(!test ? [{ step: 'runPHP', code: seed }] : []),
        ...(autoLogin ? [{ step: 'login', username: 'admin', password: 'password' }] : []),
      ],
    },
  });
}
