/**
 * Webhook Authentication — verifies incoming requests from WordPress.
 *
 * Checks HMAC signature, timestamp freshness, and nonce uniqueness.
 */

import { createHmac, timingSafeEqual } from 'crypto';

// Simple in-memory nonce cache (good enough for single-process agent).
const seenNonces = new Map();
const NONCE_TTL_MS = 10 * 60 * 1000; // 10 minutes.
const MAX_AGE_SECONDS = 300; // 5 minutes.

// Clean expired nonces periodically.
setInterval(() => {
  const now = Date.now();
  for (const [nonce, timestamp] of seenNonces) {
    if (now - timestamp > NONCE_TTL_MS) {
      seenNonces.delete(nonce);
    }
  }
}, 60_000);

/**
 * Verify an incoming webhook request.
 *
 * @param {import('express').Request} req
 * @returns {{ valid: boolean, reason: string }}
 */
export function verifyWebhook(req) {
  const secret = process.env.WEBHOOK_SHARED_SECRET;

  if (!secret) {
    return { valid: false, reason: 'WEBHOOK_SHARED_SECRET not configured on VPS.' };
  }

  const timestamp = parseInt(req.headers['x-tossa-timestamp'] || '0', 10);
  const nonce = req.headers['x-tossa-nonce'] || '';
  const signature = req.headers['x-tossa-signature'] || '';

  // 1. Timestamp freshness.
  const age = Math.abs(Math.floor(Date.now() / 1000) - timestamp);
  if (age > MAX_AGE_SECONDS) {
    return { valid: false, reason: `Timestamp too old: ${age}s` };
  }

  // 2. Nonce replay protection.
  if (!nonce) {
    return { valid: false, reason: 'Missing nonce.' };
  }
  if (seenNonces.has(nonce)) {
    return { valid: false, reason: 'Duplicate nonce.' };
  }
  seenNonces.set(nonce, Date.now());

  // 3. HMAC signature verification.
  const body = typeof req.body === 'string' ? req.body : JSON.stringify(req.body);
  const expected = createHmac('sha256', secret)
    .update(timestamp + nonce + body)
    .digest('hex');

  try {
    const expectedBuf = Buffer.from(expected, 'hex');
    const signatureBuf = Buffer.from(signature, 'hex');

    if (expectedBuf.length !== signatureBuf.length || !timingSafeEqual(expectedBuf, signatureBuf)) {
      return { valid: false, reason: 'HMAC signature mismatch.' };
    }
  } catch {
    return { valid: false, reason: 'Invalid signature format.' };
  }

  return { valid: true, reason: '' };
}
