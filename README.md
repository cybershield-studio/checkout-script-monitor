# CyberShield Checkout Script Monitor for WooCommerce

A free, open-source WordPress/WooCommerce plugin that inventories the scripts on a
merchant's checkout and shows where they stand on **PCI DSS 6.4.3**, in plain
English. Built by [CyberShield Studio](https://cybershieldstudio.com).

It is a **readiness and visibility** aid, not a compliance guarantee, and it is not
affiliated with or endorsed by the PCI Security Standards Council.

## What it does

- **Script inventory** — scans the store's own home/shop/cart/checkout pages
  (`wp_remote_get`), parses `<script src>`, classifies first- vs third-party, names
  the source (WordPress core, the installed plugin/theme via `get_plugins()` /
  `wp_get_theme()`, or the external vendor via a host catalog), and records SRI status.
- **6.4.3 readout** — counts, a per-script table, and plain-English guidance.
- **Drift monitoring** (off by default) — emits a `Content-Security-Policy-Report-Only`
  header on cart/checkout, built from the merchant's **trusted list** (their own
  observed scripts, never an opinionated "trusted sources" default), with a local REST
  endpoint (`/wp-json/cybershield-checkout-script-monitor/v1/csp-report`) storing the reports in the
  site's own database. New or changed scripts surface as **Alerts**. Visitor IP and
  referrer are dropped at ingest. Report-Only never blocks anything.

## Installation

**From a release zip**

1. In WordPress: **Plugins → Add New Plugin → Upload Plugin**, choose
   `cybershield-checkout-script-monitor.zip`, **Install Now**, then **Activate**.
2. A **CyberShield Checkout Script Monitor** menu appears in the admin sidebar.

**From source**

```bash
git clone https://github.com/cybershield-studio/checkout-script-monitor.git \
  wp-content/plugins/cybershield-checkout-script-monitor
```

Then activate it from **Plugins**.

Requirements: WordPress 6.2+, PHP 7.4+, and WooCommerce (the CSP header is scoped to
the cart/checkout pages).

## Usage

1. **CyberShield Checkout Script Monitor → Checkout Scripts** — run the short 3-step setup:
   **Scan my checkout** to inventory the scripts, **mark them as trusted** (the
   known-good list), then **turn on monitoring**.
2. Once monitoring is on, a new or changed script on the checkout appears under
   **Alerts**, where **Trust this** adds it to the trusted list (or you remove it
   from the store).
3. Manage the trusted sources — add from a re-scan, or remove a domain — on the
   **Checkout Scripts** screen; the monitoring toggle lives in **Settings**.

## Design principles

- **No endorsement.** The trusted list is derived from the merchant's own site, so
  the plugin vouches for no third party. It alerts on drift.
- **No phone-home.** The only outbound request is scanning the merchant's own URLs.
- **Never claims compliance.** Wording is readiness/visibility throughout.
- **Never blocks.** The plugin only ever emits the Report-Only CSP variant, so it
  cannot break a checkout.

## Structure

```
cybershield-checkout-script-monitor.php   Bootstrap: header, constants, activation
includes/class-inventory.php  Scan + parse + classify + SRI + snapshot
includes/class-csp.php        Report-Only header + REST report endpoint + storage
includes/class-admin.php      Admin menus and screens
uninstall.php                 Removes options + violations table
readme.txt                    wordpress.org listing
```

## License

GPL-2.0-or-later. Free and open source.
