const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');

test.describe('WooCommerce Facebook Logs Validation', () => {

  test('Check WooCommerce logs for fatal errors and non-200 responses', async () => {
    console.log('🔍 Checking WooCommerce logs for errors...');

    const today = new Date().toISOString().split('T')[0];

    const logsDir = process.env(LOG_PATH)  || '../../../wp-content/uploads/wc-logs';

    // Find today's log file
    const logFile = execSync(
      `find ${logsDir} -name "facebook_for_woocommerce-${today}*.log" 2>/dev/null | head -1`,
      { encoding: 'utf8' }
    ).trim();
    if (!logFile) {
      console.log(`⚠️ No log file found for today (${today}) - plugin may not have logged yet`);
      return;
    }

    console.log(`📄 Checking: ${logFile}`);
    const errors = [];

    // Check for fatal errors (case insensitive)
    const fatalCount = execSync(
      `grep -ic "fatal" ${logFile} || echo 0`,
      { encoding: 'utf8' }
    ).trim();
    if (parseInt(fatalCount) > 0) {
      const fatalLines = execSync(`grep -i "fatal" ${logFile}`, { encoding: 'utf8' });
      errors.push(`❌ Found ${fatalCount} fatal error(s):\n${fatalLines}`);
    }

    // Check for non-200 response codes
    const nonOkCodes = execSync(
      `grep "^code: " ${logFile} | grep -v "^code: 200" || true`,
      { encoding: 'utf8' }
    ).trim();
    if (nonOkCodes) {
      errors.push(`❌ Found non-200 response codes:\n${nonOkCodes}`);
    }

    if (errors.length > 0) {
      console.log('\n' + errors.join('\n\n'));
      throw new Error('Log validation failed');
    }

    console.log('✅ Log validation PASSED');
    console.log('   - No fatal errors');
    console.log('   - All response codes are 200 OK');
  });

});
