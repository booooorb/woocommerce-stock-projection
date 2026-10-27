import { startEnvironment } from './environment.mjs';

const instance = await startEnvironment();
console.log(`\nDashboard: ${instance.serverUrl}/wp-admin/admin.php?page=veryshop-product-analysis`);
console.log(`Development inbox: ${instance.serverUrl}/wp-admin/tools.php?page=veryshop-development-inbox`);
console.log('Local login: admin / password. All WordPress emails are captured locally.');
console.log('Edit plugin/ files and refresh. Stop with Ctrl+C.');
for (const signal of ['SIGINT', 'SIGTERM']) {
  process.once(signal, async () => { await instance[Symbol.asyncDispose](); process.exit(0); });
}
