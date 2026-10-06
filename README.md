# WOT Product Consultant

AI-powered product consultant chat for [World of Test](https://www.worldoftest.com) — helps visitors find the right testing equipment based on their needs.

## Stack

- **Frontend:** Vanilla HTML/CSS/JS — chat widget UI
- **Backend:** PHP (`api.php`) — keyword search + AI API proxy
- **AI:** Groq (default) / Gemini / Claude — model-agnostic, swap via env var
- **Data:** 773 products across 5 categories, sourced from WOT sitemap

## Categories Covered

1. Metal Testing Equipment
2. Plastic Testing Equipment
3. Plastics PVC / Pipe Testing
4. Environmental Chambers
5. Color, Paint & Coating Testing

## Setup

```bash
# Run locally
GROQ_API_KEY=your_key php -S localhost:8088
# Open http://localhost:8088
```

Set one of these env vars:

| Var | Provider |
|-----|----------|
| `GROQ_API_KEY` | Groq (recommended, free tier) |
| `GEMINI_API_KEY` | Google Gemini Flash |
| `CLAUDE_API_KEY` | Anthropic Claude Haiku |

## Features

- Conversational product recommendations with follow-up questions
- Smart keyword filtering — only sends relevant products to AI (~25 max)
- Lazy product image loading from WOT product pages
- Markdown table + link rendering in chat
- Prompt injection / jailbreak protection

## Ongoing

| # | Task | Status |
|---|------|--------|
| A | **Deploy to cPanel** — zip and upload prototype for client demo | 🔲 Pending |
| B | **Expand product data** — 908 products still unmatched/uncategorized | 🔲 Pending |
| C | **Image cache** — pre-scrape all product image URLs into `products.json` (avoid per-request fetch) | 🔲 Pending |
| D | **Drupal module** — wrap as a Drupal block/module for worldoftest.com production | 🔲 Pending |
| E | **Client feedback** — demo prototype, iterate based on feedback | 🔲 Pending |

## File Structure

```
prototype/
├── index.html          # Chat UI
├── api.php             # Backend: AI proxy + product search + image fetch
├── products.json       # 773 products from WOT sitemap (5 categories)
└── build_products.py   # Script to rebuild products.json from sitemap
```
