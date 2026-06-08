# MapleBoost.ca — Pages to Build

**Status:** June 3, 2026 — All Priority 1–3 pages built.

## Hub/Section Pages (Priority 1)

- [x] `/services` — Business services landing/overview page
- [x] `/grow` — Business growth guides hub
- [x] `/tools` — Tools and calculators hub
- [x] `/data` — Data and resources hub
- [x] `/reviews` — Reviews hub/index
- [x] `/blog` — Blog index/archive

## Start Section Sub-Pages (Priority 2)

- [x] `/start/sole-proprietorship`
- [x] `/start/ontario/incorporate`
- [x] `/start/quebec/incorporate`
- [x] `/start/bc/incorporate`
- [x] `/start/alberta/incorporate`
- [x] `/start/register-gst-hst`
- [x] `/start/business-number-cra`
- [x] `/start/ontario`
- [x] `/start/quebec`
- [x] `/start/bc`
- [x] `/start/alberta`

## Tools/Calculators (Priority 3)

- [x] `/tools/gst-hst-calculator` — Interactive calculator
- [x] `/tools/incorporation-cost` — Cost estimator tool

## Data Pages (Priority 3)

- [x] `/data/provincial-tax-rates` — Tax rates by province
- [x] `/data/grants-database` — Searchable grants database

## Review Pages (Priority 2)

- [x] `/reviews/best-incorporation-services` — Complete (existing)
- [x] `/reviews/best-accounting-software`
- [x] `/reviews/best-business-bank-accounts`

---

## Follow-ups (linked but not yet built)

These are mentioned by name in the new pages but don't have dedicated articles yet. Build when time allows:

- `/grow/accounting-setup` — chart of accounts and bookkeeping setup
- `/grow/hiring` — hire your first employee
- `/grow/payroll-setup` — first payroll run, CPP/EI/source deductions
- `/grow/corporate-tax-basics` — small business deduction explained
- `/grow/dividends-vs-salary` — owner-manager compensation
- `/grow/contractors-vs-employees` — CRA classification test
- `/grow/invoicing` — invoicing that gets paid
- `/grow/late-payments` — collections and small claims
- `/grow/payment-processing` — Stripe vs. Square vs. Helcim vs. Moneris
- `/grow/sred` — SR&ED program for first-timers
- `/grow/business-loans` — BDC, big banks, online lenders
- `/grow/year-end-checklist` — T4s, T5s, GST/HST, T2
- `/grow/annual-return` — federal and provincial annual returns
- `/reviews/best-business-credit-cards` — Amex vs. RBC Avion vs. CIBC
- `/reviews/best-payroll-software` — Wagepoint vs. Humi vs. ADP
- `/reviews/best-crm-small-business` — HubSpot vs. Pipedrive vs. monday
- `/reviews/best-legal-services` — LawDepot vs. Clio vs. LegalDeeds

## Notes

- All built pages use SSI includes (`<!--#include virtual="/inc/nav.html" -->`)
- All pages include JSON-LD schema markup (CollectionPage, Article, HowTo, WebApplication, Dataset, ItemList)
- Affiliate links route via `/go?id=...`
- llms.txt updated with all new URLs
- Calculators (`/tools/*`) include vanilla-JS interactivity, no build step
