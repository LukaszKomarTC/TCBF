/**
 * SEO Auditor — calls Claude with site inventory and returns structured actions.
 *
 * This is the thinnest possible auditor for the first end-to-end loop:
 * - Receives inventory (pages + SEOPress metadata)
 * - Builds a focused prompt for metadata hygiene
 * - Calls Claude with structured output (output_config.format)
 * - Returns schema-compliant actions
 *
 * No GSC, no PSI, no content analysis — just metadata gaps.
 */

import Anthropic from '@anthropic-ai/sdk';
import { readFileSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

const __dirname = dirname(fileURLToPath(import.meta.url));

const client = new Anthropic();
const MODEL = process.env.CLAUDE_MODEL || 'claude-sonnet-4-6';

// Load the system prompt.
const SYSTEM_PROMPT = readFileSync(
  join(__dirname, '..', 'prompts', 'seo-audit-system.txt'),
  'utf-8'
);

// JSON schema for structured output.
const ACTION_SCHEMA = JSON.parse(readFileSync(
  join(__dirname, '..', 'schemas', 'audit-response.json'),
  'utf-8'
));

/**
 * Run an SEO audit using Claude.
 *
 * @param {string} auditId
 * @param {object} inventory - Site inventory from WordPress.
 * @param {object} objectives - SEO objectives from settings.
 * @returns {Promise<object>} Structured audit result matching seo-audit-v1 schema.
 */
export async function runSEOAudit(auditId, inventory, objectives) {
  const startTime = Date.now();

  // Build the user message with inventory data.
  const userMessage = buildUserMessage(inventory, objectives);

  console.log(`[auditor] Calling Claude (${MODEL}) for audit ${auditId}...`);
  console.log(`[auditor] Input: ~${Math.round(userMessage.length / 4)} tokens estimated`);

  const response = await client.messages.create({
    model: MODEL,
    max_tokens: 8192,
    system: SYSTEM_PROMPT,
    messages: [
      { role: 'user', content: userMessage },
    ],
  });

  const latencyMs = Date.now() - startTime;
  const tokensIn = response.usage?.input_tokens || 0;
  const tokensOut = response.usage?.output_tokens || 0;

  console.log(`[auditor] Claude responded in ${latencyMs}ms — ${tokensIn} in, ${tokensOut} out`);

  // Parse the response.
  const text = response.content
    .filter(block => block.type === 'text')
    .map(block => block.text)
    .join('');

  let parsed;
  try {
    parsed = JSON.parse(text);
  } catch (e) {
    // Try to extract JSON from markdown code fence.
    const jsonMatch = text.match(/```(?:json)?\s*([\s\S]*?)```/);
    if (jsonMatch) {
      parsed = JSON.parse(jsonMatch[1]);
    } else {
      throw new Error(`Failed to parse Claude response as JSON: ${text.substring(0, 200)}`);
    }
  }

  // Calculate cost (Sonnet 4.6: $3/MTok in, $15/MTok out).
  const costUsd = (tokensIn * 3 + tokensOut * 15) / 1_000_000;

  return {
    summary: parsed.summary || 'SEO audit completed.',
    overall_score: parsed.overall_score ?? 50,
    issues: parsed.issues || [],
    actions: (parsed.actions || []).map(action => ({
      action_id: action.action_id || `action-${auditId.substring(0, 8)}-${Math.random().toString(36).substring(2, 8)}`,
      action_type: action.action_type,
      entity_type: action.entity_type || 'post',
      entity_id: action.entity_id,
      safety_tier: action.safety_tier || 'A',
      evidence_source: action.evidence_source || 'llm_inference',
      rationale: action.rationale,
      confidence: action.confidence ?? 0.7,
      expected_impact: action.expected_impact || 'medium',
      target_metric: action.target_metric || 'CTR',
      payload: action.payload || {},
      before: action.before || {},
    })),
    model: MODEL,
    tokens_in: tokensIn,
    tokens_out: tokensOut,
    cost_usd: Math.round(costUsd * 10000) / 10000,
  };
}

/**
 * Build the user message from inventory data.
 *
 * Focuses on metadata gaps — the minimum needed for v1.
 */
function buildUserMessage(inventory, objectives) {
  const pages = inventory.pages || {};
  const stats = inventory.stats || {};
  const siteInfo = inventory.site_info || {};

  // Build a compact representation of each page's SEO state.
  const pageList = Object.entries(pages).map(([id, page]) => {
    const issues = [];
    if (!page.title) issues.push('MISSING_TITLE');
    if (!page.description) issues.push('MISSING_DESC');
    if (!page.focus_keyword) issues.push('NO_FOCUS_KW');
    if (!page.canonical) issues.push('NO_CANONICAL');
    if ((page.word_count || 0) < 300) issues.push('THIN_CONTENT');
    if ((page.internal_links || 0) === 0) issues.push('ORPHAN');

    // Title/description length issues.
    if (page.title && page.title.length > 60) issues.push('TITLE_LONG');
    if (page.title && page.title.length < 20) issues.push('TITLE_SHORT');
    if (page.description && page.description.length > 160) issues.push('DESC_LONG');
    if (page.description && page.description.length < 70) issues.push('DESC_SHORT');

    return {
      id: parseInt(id),
      type: page.post_type,
      title: page.post_title,
      url: page.post_url,
      seo_title: page.title || null,
      seo_desc: page.description || null,
      focus_kw: page.focus_keyword || null,
      words: page.word_count || 0,
      internal_links: page.internal_links || 0,
      images: page.images || 0,
      issues,
    };
  });

  // Only include pages with issues to keep token count down.
  const pagesWithIssues = pageList.filter(p => p.issues.length > 0);
  const pagesOk = pageList.filter(p => p.issues.length === 0);

  let message = `## Site: ${siteInfo.name || 'Unknown'} (${siteInfo.url || ''})\n\n`;
  message += `## Stats\n`;
  message += `- Total pages: ${stats.total_pages || pageList.length}\n`;
  message += `- Missing title: ${stats.missing_title || 0}\n`;
  message += `- Missing description: ${stats.missing_desc || 0}\n`;
  message += `- Thin content (<300 words): ${stats.thin_content || 0}\n`;
  message += `- No focus keyword: ${stats.no_focus_keyword || 0}\n`;
  message += `- Orphan pages (0 internal links): ${stats.orphan_pages || 0}\n`;
  message += `- Pages with no issues: ${pagesOk.length}\n\n`;

  if (objectives) {
    message += `## SEO Objectives\n`;
    if (objectives.primary_keywords) message += `- Primary keywords: ${objectives.primary_keywords}\n`;
    if (objectives.target_audience) message += `- Target audience: ${objectives.target_audience}\n`;
    if (objectives.geo_target) message += `- Geo target: ${objectives.geo_target}\n`;
    if (objectives.seo_goal) message += `- Goal: ${objectives.seo_goal}\n`;
    if (objectives.brand_voice) message += `- Brand voice: ${objectives.brand_voice}\n`;
    message += '\n';
  }

  message += `## Pages With Issues (${pagesWithIssues.length})\n\n`;
  message += '```json\n';
  message += JSON.stringify(pagesWithIssues, null, 2);
  message += '\n```\n\n';

  message += `Analyze these pages and propose specific SEO actions. Focus on:\n`;
  message += `1. Missing or weak meta titles and descriptions\n`;
  message += `2. Missing focus keywords\n`;
  message += `3. Canonical issues\n`;
  message += `4. Critical metadata gaps on commercial pages (products, tours, rentals)\n\n`;
  message += `Return your response as a JSON object matching the schema in your instructions.\n`;
  message += `Only propose actions you are confident about (confidence >= 0.7).\n`;
  message += `For each proposed title/description, include the actual text in the payload.\n`;

  return message;
}
