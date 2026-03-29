/**
 * Tossa VPS Agent — Minimal Server
 *
 * Receives site inventory from WordPress, runs Claude SEO audit,
 * sends structured actions back via signed webhook.
 *
 * This is intentionally thin: one endpoint, one Claude call, one callback.
 * No Redis, no queues, no GSC, no PSI — just the core loop.
 */

import express from 'express';
import { createHmac } from 'crypto';
import { verifyWebhook } from './webhook-auth.js';
import { runSEOAudit } from './seo-auditor.js';
import { sendCallback } from './callback-sender.js';

const app = express();
app.use(express.json({ limit: '10mb' }));

const PORT = process.env.PORT || 3100;

// Health check.
app.get('/health', (req, res) => {
  res.json({
    status: 'ok',
    version: '0.1.0',
    model: process.env.CLAUDE_MODEL || 'claude-sonnet-4-6',
  });
});

/**
 * POST /api/seo-audit
 *
 * Receives inventory from WordPress, runs Claude analysis, sends results back.
 */
app.post('/api/seo-audit', async (req, res) => {
  // 1. Verify webhook signature.
  const authResult = verifyWebhook(req);
  if (!authResult.valid) {
    console.error(`[auth] Rejected: ${authResult.reason}`);
    return res.status(401).json({ error: authResult.reason });
  }

  const { audit_id, inventory, objectives } = req.body;

  if (!audit_id || !inventory) {
    return res.status(400).json({ error: 'Missing audit_id or inventory.' });
  }

  console.log(`[audit] Received audit ${audit_id} — ${Object.keys(inventory.pages || {}).length} pages`);

  // 2. Acknowledge immediately — processing happens async.
  res.json({ status: 'accepted', audit_id });

  // 3. Notify WP that analysis is starting.
  await sendCallback(audit_id, { type: 'status_update', audit_id, status: 'analyzing' });

  try {
    // 4. Run Claude SEO audit.
    const result = await runSEOAudit(audit_id, inventory, objectives);

    // 5. Send results back to WordPress.
    await sendCallback(audit_id, {
      type: 'seo_audit_result',
      audit_id,
      result,
    });

    console.log(`[audit] Completed ${audit_id} — score: ${result.overall_score}, actions: ${result.actions.length}`);
  } catch (error) {
    console.error(`[audit] Failed ${audit_id}:`, error.message);

    // 6. Notify WP of failure.
    await sendCallback(audit_id, {
      type: 'error',
      audit_id,
      error: error.message,
    });
  }
});

app.listen(PORT, () => {
  console.log(`[server] Tossa VPS Agent running on port ${PORT}`);

  if (!process.env.ANTHROPIC_API_KEY) {
    console.warn('[server] WARNING: ANTHROPIC_API_KEY not set!');
  }
  if (!process.env.WEBHOOK_SHARED_SECRET) {
    console.warn('[server] WARNING: WEBHOOK_SHARED_SECRET not set!');
  }
});
