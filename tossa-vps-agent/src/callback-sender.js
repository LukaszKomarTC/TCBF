/**
 * Callback Sender — sends results back to WordPress via signed webhook.
 */

import { createHmac, randomUUID } from 'crypto';

/**
 * Send a callback to the WordPress plugin.
 *
 * @param {string} auditId
 * @param {object} payload
 */
export async function sendCallback(auditId, payload) {
  const callbackUrl = process.env.WORDPRESS_CALLBACK_URL;
  const secret = process.env.WEBHOOK_SHARED_SECRET;

  if (!callbackUrl) {
    console.warn(`[callback] No WORDPRESS_CALLBACK_URL configured, skipping callback for ${auditId}`);
    return;
  }

  const body = JSON.stringify(payload);
  const timestamp = Math.floor(Date.now() / 1000).toString();
  const nonce = randomUUID();
  const signature = createHmac('sha256', secret || '')
    .update(timestamp + nonce + body)
    .digest('hex');

  for (let attempt = 0; attempt < 3; attempt++) {
    try {
      const response = await fetch(callbackUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${secret}`,
          'X-Tossa-Timestamp': timestamp,
          'X-Tossa-Nonce': nonce,
          'X-Tossa-Signature': signature,
          'X-Tossa-Schema-Version': '1.0',
        },
        body,
        signal: AbortSignal.timeout(30_000),
      });

      if (response.ok) {
        console.log(`[callback] Sent to WP: ${payload.type} for audit ${auditId}`);
        return;
      }

      console.warn(`[callback] WP returned ${response.status}: ${await response.text()}`);
    } catch (error) {
      console.error(`[callback] Attempt ${attempt + 1}/3 failed:`, error.message);
    }

    // Exponential backoff: 2s, 4s.
    if (attempt < 2) {
      await new Promise(resolve => setTimeout(resolve, 2000 * (attempt + 1)));
    }
  }

  console.error(`[callback] All attempts failed for audit ${auditId}`);
}
