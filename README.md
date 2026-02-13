# Krayin Financial Reports

A dedicated financial dashboard for Krayin CRM to track monthly sales performance and key financial metrics.

## Features
- **Dedicated Dashboard:** Accessible via the "Informes" menu item.
- **KPI Cards:** Overview of Total Revenue (YTD), Monthly Revenue, and Growth.
- **Visual Charts:** Monthly Sales bar charts to visualize trends.
- **Detailed Reporting:** Tables listing Won Leads with filtering capabilities.

## Installation from GitHub

To install this package in your Krayin CRM project:

### 1. Update `composer.json`
Add the repository to your root `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/carlvallory/KrayinFinancialReports.git"
    }
]
```

### 2. Require the Package
Run:

```bash
composer require carlvallory/krayin-financial-reports
```

### 3. Verify
Access your CRM admin panel and check for the new **"Informes"** menu item in the sidebar.
